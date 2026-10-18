<?php
// Advanced Disk Analyzer: shared code. Not reachable from the web (see .htaccess).
//
// Sizes follow the desktop Advanced Disk Analyzer rules: "allocated" is the space on disk
// (blocks * 512, like du), "apparent" is the byte length (like ls -l). Symlinks are
// never followed and hard-linked files are counted once.

declare(strict_types=1);

const DA_VERSION = '1.0';
const CHILD_LIMIT = 5000;
const MAP_ITEMS = 60000;        // the treemap leaves out items smaller than total / MAP_ITEMS

/**
 * True when cPanel itself runs this page (installed as a cPanel plugin): cPanel has
 * already signed the user in, and LiveAPI can call UAPI directly.
 */
function plugin_mode(): bool
{
    return str_ends_with((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''), '.live.php') && getenv('REMOTE_USER') !== false;
}

/** cPanel LiveAPI connection (plugin mode only). */
function live_api()
{
    static $cp = false;
    if ($cp === false) {
        $cp = null;
        if (plugin_mode() && is_file('/usr/local/cpanel/php/cpanel.php')) {
            require_once '/usr/local/cpanel/php/cpanel.php';
            $cp = new CPANEL();
        }
    }
    return $cp;
}

function home_dir(): string
{
    if (str_starts_with(PHP_SAPI, 'cli') && getenv('DA_HOME')) {
        return rtrim((string) getenv('DA_HOME'), '/'); // tests only
    }
    if (function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid(posix_geteuid());
        if ($pw && is_dir($pw['dir'])) {
            return rtrim($pw['dir'], '/');
        }
    }
    return rtrim((string) (getenv('HOME') ?: dirname((string) $_SERVER['DOCUMENT_ROOT'])), '/');
}

function account_user(): string
{
    if (function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid(posix_geteuid());
        if ($pw) {
            return $pw['name'];
        }
    }
    return basename(home_dir());
}

/** Private folder for the database and state, outside public_html. */
function data_dir(): string
{
    $d = home_dir() . '/.diskanalyzer';
    if (!is_dir($d)) {
        mkdir($d, 0700, true);
    }
    return $d;
}

function read_json(string $file, $default = null)
{
    $s = @file_get_contents($file);
    return $s === false ? $default : (json_decode($s, true) ?? $default);
}

function write_json(string $file, $value): void
{
    $tmp = $file . '.tmp' . getmypid();
    file_put_contents($tmp, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, $file);
}

function extension_of(string $name): string
{
    $dot = strrpos($name, '.');
    if ($dot === false || $dot === 0) {
        return '(none)';
    }
    $ext = substr($name, $dot + 1);
    return ($ext === '' || strlen($ext) > 10) ? '(none)' : strtolower($ext);
}

/** True if $path is $home itself or inside it (no symlink tricks: both are resolved). */
function inside_home(string $path): bool
{
    $home = realpath(home_dir());
    $real = realpath($path);
    return $home !== false && $real !== false && ($real === $home || str_starts_with($real, $home . '/'));
}

// ---------------------------------------------------------------- database

function open_db(string $file): PDO
{
    $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA journal_mode = OFF; PRAGMA synchronous = OFF; PRAGMA temp_store = MEMORY');
    return $db;
}

function result_db(): ?PDO
{
    static $db = null;
    $file = data_dir() . '/scan.sqlite';
    if ($db === null && is_file($file)) {
        $db = open_db($file);
    }
    return $db;
}

function create_schema(PDO $db): void
{
    $db->exec('CREATE TABLE entries (
        id INTEGER PRIMARY KEY, parent INTEGER, depth INTEGER, name TEXT, is_dir INTEGER,
        size INTEGER, apparent INTEGER, files INTEGER, dirs INTEGER, modified INTEGER, ext TEXT, path TEXT)');
    // Plain tables and indexes only: many cPanel servers ship SQLite 3.7 (no WITHOUT ROWID, no partial indexes).
    $db->exec('CREATE TABLE seen (dev INTEGER, ino INTEGER, PRIMARY KEY (dev, ino))');
    $db->exec('CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT)');
}

function meta(PDO $db, string $key, $default = null)
{
    $st = $db->prepare('SELECT v FROM meta WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : json_decode($v, true);
}

function set_meta(PDO $db, string $key, $value): void
{
    $db->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)')->execute([$key, json_encode($value, JSON_UNESCAPED_SLASHES)]);
}

// ---------------------------------------------------------------- scanning
//
// Shared hosting cannot run a background process, so the scan is a resumable
// depth-first walk: every status request does a few seconds of work and saves the
// stack of folders still to read. Ids grow in visiting order, so a parent's id is
// always smaller than its children's.

function state_file(): string { return data_dir() . '/scan-state.json'; }
function work_db_file(): string { return data_dir() . '/scan-new.sqlite'; }

/** Work per request, well under any host time limit (tests may shorten it). */
function step_seconds(): float
{
    return str_starts_with(PHP_SAPI, 'cli') && getenv('DA_STEP') ? (float) getenv('DA_STEP') : 4.0;
}

function scan_state(): array
{
    return read_json(state_file(), ['running' => false]);
}

/** Folders the scan never enters: virtual or not worth it. */
function skipped(string $path): bool
{
    return in_array($path, [home_dir() . '/.diskanalyzer'], true);
}

function start_scan(string $root): array
{
    if ($root !== '' && $root[0] !== '/') {
        $root = home_dir() . '/' . $root; // "public_html" means the one in this account
    }
    $real = realpath($root);
    if ($real === false || !is_dir($real) || !inside_home($real)) {
        throw new InvalidArgumentException('Choose a folder inside your account (' . home_dir() . ').');
    }
    $st = scan_state();
    if (!empty($st['running']) && time() - ($st['touched'] ?? 0) < 120) {
        throw new RuntimeException('A scan is running. Stop it before starting another.');
    }
    @unlink(work_db_file());
    $db = open_db(work_db_file());
    create_schema($db);
    $s = lstat($real);
    $db->prepare('INSERT INTO entries VALUES (0, NULL, 0, ?, 1, ?, 0, 0, 0, ?, NULL, ?)')
        ->execute([$real, $s['blocks'] * 512, $s['mtime'], $real]);
    $state = [
        'running' => true, 'phase' => 'walk', 'root' => $real, 'stack' => [[0, $real, 0]], 'next_id' => 1,
        'files' => 0, 'dirs' => 0, 'bytes' => $s['blocks'] * 512, 'errors' => 0, 'cancel' => false,
        'started' => microtime(true), 'touched' => time(), 'error' => null,
    ];
    write_json(state_file(), $state);
    return $state;
}

/** Does up to STEP_SECONDS of scanning. Returns the updated state. */
function scan_step(): array
{
    $state = scan_state();
    if (empty($state['running'])) {
        return $state;
    }
    $lock = fopen(data_dir() . '/scan.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        return $state; // another request is already working on it
    }
    try {
        $state = scan_state(); // re-read under the lock
        @set_time_limit(60);
        ignore_user_abort(true);
        $db = open_db(work_db_file());
        $until = microtime(true) + step_seconds();
        $db->beginTransaction();
        if ($state['phase'] === 'walk') {
            walk($db, $state, $until);
        }
        if ($state['phase'] === 'rollup' && microtime(true) < $until) {
            rollup($db, $state, $until);
        }
        $db->commit();
        if ($state['phase'] === 'done') {
            finish($db, $state);
        }
    } catch (Throwable $e) {
        $state['running'] = false;
        $state['error'] = 'Scan failed: ' . $e->getMessage();
    } finally {
        $state['touched'] = time();
        write_json(state_file(), $state);
        flock($lock, LOCK_UN);
    }
    return $state;
}

function walk(PDO $db, array &$state, float $until): void
{
    $insert = $db->prepare('INSERT INTO entries VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)');
    $seen = $db->prepare('INSERT OR IGNORE INTO seen VALUES (?, ?)');
    $rootDev = lstat($state['root'])['dev'];
    while ($state['stack'] && microtime(true) < $until) {
        if ($state['cancel']) {
            $state['stack'] = [];
            $state['partial'] = true;
            break;
        }
        [$pid, $path, $depth] = array_pop($state['stack']);
        $names = @scandir($path, SCANDIR_SORT_NONE);
        if ($names === false) {
            $state['errors']++; // usually "permission denied"
            continue;
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $path . '/' . $name;
            $s = @lstat($full);
            if ($s === false) {
                $state['errors']++;
                continue;
            }
            $isDir = ($s['mode'] & 0170000) === 0040000;
            $id = $state['next_id']++;
            $size = $s['blocks'] * 512;
            $apparent = $s['size'];
            if ($isDir) {
                if ($s['dev'] !== $rootDev || skipped($full)) {
                    $state['next_id']--;
                    continue; // other file system, or our own data
                }
                $insert->execute([$id, $pid, $depth + 1, $name, 1, $size, 0, 0, $s['mtime'], null, $full]);
                $state['stack'][] = [$id, $full, $depth + 1];
                $state['dirs']++;
            } else {
                if ($s['nlink'] > 1) {
                    $seen->execute([$s['dev'], $s['ino']]);
                    if ($seen->rowCount() === 0) {
                        $size = $apparent = 0; // another hard link to a file already counted
                    }
                }
                $insert->execute([$id, $pid, $depth + 1, $name, 0, $size, $apparent, 1, $s['mtime'], extension_of($name), null]);
                $state['files']++;
            }
            $state['bytes'] += $size;
        }
    }
    if (!$state['stack']) {
        $db->exec('CREATE INDEX by_depth ON entries (depth, is_dir)');
        $state['phase'] = 'rollup';
        $state['level'] = (int) $db->query('SELECT MAX(depth) FROM entries')->fetchColumn();
    }
}

/** Adds every folder's contents into its totals, deepest level first. */
function rollup(PDO $db, array &$state, float $until): void
{
    $update = $db->prepare('UPDATE entries SET size = size + ?, apparent = apparent + ?, files = files + ?, dirs = dirs + ? WHERE id = ?');
    while ($state['level'] > 0 && microtime(true) < $until) {
        $rows = $db->prepare('SELECT parent, SUM(size) s, SUM(apparent) a, SUM(files) f, SUM(dirs + is_dir) d FROM entries WHERE depth = ? GROUP BY parent');
        $rows->execute([$state['level']]);
        foreach ($rows->fetchAll(PDO::FETCH_NUM) as [$parent, $s, $a, $f, $d]) {
            $update->execute([$s, $a, $f, $d, $parent]);
        }
        $state['level']--;
    }
    if ($state['level'] === 0) {
        $state['phase'] = 'done';
    }
}

function finish(PDO $db, array &$state): void
{
    $db->exec('CREATE INDEX by_parent ON entries (parent, size DESC)');
    $db->exec('CREATE INDEX by_dup ON entries (name, apparent)');
    $types = $db->query("SELECT ext, SUM(size) size, SUM(apparent) apparent, COUNT(*) files FROM entries WHERE is_dir = 0 GROUP BY ext ORDER BY size DESC LIMIT 100")->fetchAll();
    foreach ($types as &$t) {
        $t = ['ext' => $t['ext'], 'size' => (int) $t['size'], 'apparent' => (int) $t['apparent'], 'files' => (int) $t['files']];
    }
    set_meta($db, 'types', $types);
    $state['elapsed_ms'] = (int) ((microtime(true) - $state['started']) * 1000);
    set_meta($db, 'scan', ['root' => $state['root'], 'elapsed_ms' => $state['elapsed_ms'], 'errors' => $state['errors'], 'partial' => !empty($state['partial']), 'at' => time()]);
    $db = null;
    rename(work_db_file(), data_dir() . '/scan.sqlite');
    @unlink(data_dir() . '/map-0.json');
    $state['running'] = false;
    $state['stack'] = [];
}

// ---------------------------------------------------------------- reading results

function summary(array $r): array
{
    return [
        'id' => (int) $r['id'], 'name' => $r['name'], 'size' => (int) $r['size'], 'apparent' => (int) $r['apparent'],
        'files' => (int) $r['files'], 'dirs' => (int) $r['dirs'], 'modified' => (int) $r['modified'],
        'is_dir' => (bool) $r['is_dir'], 'has_children' => (bool) $r['is_dir'] && ((int) $r['files'] + (int) $r['dirs']) > 0,
    ];
}

function entry(PDO $db, int $id): ?array
{
    $st = $db->prepare('SELECT * FROM entries WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Ids from the scan root down to $id. */
function chain(PDO $db, int $id): array
{
    $out = [];
    $st = $db->prepare('SELECT parent FROM entries WHERE id = ?');
    for ($cur = $id; $cur !== null; ) {
        array_unshift($out, $cur);
        $st->execute([$cur]);
        $p = $st->fetchColumn();
        $cur = ($p === null || $p === false) ? null : (int) $p;
    }
    return $out;
}

function path_of(PDO $db, array $e): string
{
    if ($e['is_dir']) {
        return $e['path'];
    }
    return entry($db, (int) $e['parent'])['path'] . '/' . $e['name'];
}

/** Compact nested map for the treemap: [id, size, kind, name, children?]. */
function compact_map(PDO $db, array $e, int $min, array $kinds): array
{
    if (!$e['is_dir']) {
        return [(int) $e['id'], (int) $e['size'], $kinds[$e['ext']] ?? count($kinds), $e['name']];
    }
    $st = $db->prepare('SELECT * FROM entries WHERE parent = ? AND size >= ? ORDER BY size DESC LIMIT 3000');
    $st->execute([$e['id'], max($min, 1)]);
    $kids = [];
    foreach ($st->fetchAll() as $c) {
        $kids[] = compact_map($db, $c, $min, $kinds);
    }
    return [(int) $e['id'], (int) $e['size'], -1, $e['name'], $kids];
}

// ---------------------------------------------------------------- cPanel

/**
 * Calls a cPanel UAPI function through the `uapi` command. PHP already runs as the
 * account user, so no password or token is needed. Returns null if unavailable.
 */
function uapi(string $module, string $func, array $args = []): ?array
{
    if ($cp = live_api()) {
        $r = $cp->uapi($module, $func, $args);
        $res = $r['cpanelresult']['result'] ?? null;
        return ($res['status'] ?? 0) == 1 ? $res : null;
    }
    if (!function_exists('shell_exec') || in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
        return null;
    }
    $cmd = 'uapi --output=json ' . escapeshellarg($module) . ' ' . escapeshellarg($func);
    foreach ($args as $k => $v) {
        $cmd .= ' ' . escapeshellarg("$k=$v");
    }
    $out = @shell_exec($cmd . ' 2>/dev/null');
    $data = $out ? json_decode($out, true) : null;
    return ($data['result']['status'] ?? 0) == 1 ? $data['result'] : null;
}

/** Checks a cPanel username and password against cPanel itself (nothing is stored). */
function cpanel_login_ok(string $user, string $pass): ?bool
{
    if (!function_exists('curl_init')) {
        return null;
    }
    foreach (['https://127.0.0.1:2083', 'https://' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . ':2083'] as $base) {
        $ch = curl_init($base . '/login/?login_only=1');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['user' => $user, 'pass' => $pass]),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, // local connection to this same server
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code === 0) {
            continue; // try the next address
        }
        $d = json_decode((string) $body, true);
        return is_array($d) && ($d['status'] ?? 0) == 1;
    }
    return null;
}
