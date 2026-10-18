<?php
// Advanced Disk Analyzer: JSON API used by the dashboard page (api.php?a=<action>).

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function fail(string $message, int $code = 400): never { reply(['error' => $message], $code); }

if (!signed_in()) {
    fail('Please sign in again.', 401);
}
$action = (string) ($_GET['a'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !csrf_ok()) {
    fail('This request did not come from the dashboard.', 403);
}
$body = $post ? (json_decode((string) file_get_contents('php://input'), true) ?? []) : [];
$id = (int) ($_GET['id'] ?? $body['id'] ?? 0);

function db_or_fail(): PDO
{
    return result_db() ?? fail('Scan a folder first.', 409);
}
function entry_or_fail(PDO $db, int $id): array
{
    return entry($db, $id) ?? fail('Item not found.', 404);
}

try {
    switch ($action) {
        case 'status':
            $st = scan_step(); // a running scan advances a little on every poll
            $db = result_db();
            $scan = $db ? meta($db, 'scan', []) : [];
            reply([
                'running' => !empty($st['running']), 'phase' => $st['phase'] ?? null, 'files' => $st['files'] ?? 0, 'dirs' => $st['dirs'] ?? 0,
                'bytes' => $st['bytes'] ?? 0, 'errors' => $st['errors'] ?? ($scan['errors'] ?? 0), 'error' => $st['error'] ?? null,
                'ready' => $db !== null, 'partial' => $scan['partial'] ?? false, 'elapsed_ms' => $scan['elapsed_ms'] ?? 0, 'root' => $scan['root'] ?? null,
            ]);

        case 'scan':
            $post || fail('Use POST.', 405);
            start_scan((string) ($body['path'] ?? home_dir()));
            reply(['running' => true]);

        case 'cancel':
            $st = scan_state();
            if (!empty($st['running'])) {
                $st['cancel'] = true;
                write_json(state_file(), $st);
            }
            reply(['ok' => true]);

        case 'drives':
            $home = home_dir();
            $list = [['mount' => $home, 'home' => true]];
            foreach (['public_html' => 'Website files', 'mail' => 'Email', 'logs' => 'Logs', 'tmp' => 'Temporary files'] as $dir => $label) {
                if (is_dir("$home/$dir")) {
                    $list[] = ['mount' => "$home/$dir", 'label' => $label];
                }
            }
            reply($list);

        case 'info':
            $db = db_or_fail();
            $root = entry_or_fail($db, 0);
            $q = uapi('Quota', 'get_quota_info');
            $disk = null;
            if ($q && !empty($q['data'])) {
                $d = $q['data'];
                $limit = (float) ($d['megabyte_limit'] ?? 0) * 1048576;
                $used = (float) ($d['megabytes_used'] ?? 0) * 1048576;
                $disk = ['total' => $limit ?: null, 'used' => $used, 'free' => $limit ? max(0, $limit - $used) : null,
                         'inodes_used' => (int) ($d['inodes_used'] ?? 0), 'inodes_limit' => (int) ($d['inode_limit'] ?? 0)];
            }
            $scan = meta($db, 'scan', []);
            reply(['root' => summary($root) + ['path' => $root['path']], 'types' => meta($db, 'types', []), 'disk' => $disk,
                   'elapsed_ms' => $scan['elapsed_ms'] ?? 0, 'errors' => $scan['errors'] ?? 0, 'partial' => $scan['partial'] ?? false,
                   'user' => account_user(), 'host' => $_SERVER['HTTP_HOST'] ?? '']);

        case 'children':
            $db = db_or_fail();
            $e = entry_or_fail($db, $id);
            $st = $db->prepare('SELECT * FROM entries WHERE parent = ? ORDER BY size DESC LIMIT ' . CHILD_LIMIT);
            $st->execute([$id]);
            $kids = array_map('summary', $st->fetchAll());
            reply(['children' => $kids, 'more' => max(0, (int) $db->query('SELECT COUNT(*) FROM entries WHERE parent = ' . $id)->fetchColumn() - count($kids))]);

        case 'node':
            $db = db_or_fail();
            $e = entry_or_fail($db, $id);
            reply(summary($e) + ['path' => path_of($db, $e), 'chain' => chain($db, $id)]);

        case 'map':
            $db = db_or_fail();
            $cache = data_dir() . "/map-$id.json";
            if ($id === 0 && is_file($cache)) {
                header('Content-Length: ' . filesize($cache));
                readfile($cache);
                exit;
            }
            $e = entry_or_fail($db, $id);
            $kinds = [];
            foreach (meta($db, 'types', []) as $i => $t) {
                $kinds[$t['ext']] = $i;
            }
            $map = compact_map($db, $e, intdiv((int) $e['size'], MAP_ITEMS), $kinds);
            if ($id === 0) {
                write_json($cache, $map);
            }
            reply($map);

        case 'files':
            reply(search_files(db_or_fail(), $_GET));

        case 'trash':
            $post || fail('Use POST.', 405);
            reply(move_to_trash(db_or_fail(), $id));

        case 'account':
            reply(account_overview(result_db()));

        case 'export':
            export_csv(db_or_fail());

        default:
            fail('Unknown action.', 404);
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    fail($e->getMessage());
} catch (Throwable $e) {
    fail('Server error: ' . $e->getMessage(), 500);
}

/** File View: biggest files (or folders) matching text or a wildcard, with duplicate info. */
function search_files(PDO $db, array $q): array
{
    $max = max(1, min(100000, (int) ($q['max'] ?? 1000)));
    $text = trim((string) ($q['q'] ?? ''));
    $dups = (string) ($q['dups'] ?? '');
    $dupsOnly = ($q['dups_only'] ?? '') === 'true' && $dups !== '';
    $folders = ($q['folders'] ?? '') === 'true' && $dups === '';
    $where = ['e.id > 0', 'e.is_dir = ' . ($folders ? 1 : 0)];
    $args = [];
    $pathExpr = "CASE WHEN e.is_dir THEN e.path ELSE p.path || '/' || e.name END";
    if ($text !== '') {
        $like = strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
        $like = str_contains($text, '*') || str_contains($text, '?') ? strtr($like, ['*' => '%', '?' => '_']) : "%$like%";
        $where[] = (($q['whole_path'] ?? '') === 'true' ? $pathExpr : 'e.name') . " LIKE ? ESCAPE '\\'";
        $args[] = $like;
    }
    $join = '';
    $dupCols = 'NULL AS dup_group, NULL AS dup_n';
    if ($dups !== '') {
        // Duplicates: same name and size (and modified time if asked). Empty files are ignored.
        $byDate = $dups === 'name_size_date';
        $keys = $byDate ? 'name, apparent, modified' : 'name, apparent';
        $on = 'd.name = e.name AND d.apparent = e.apparent' . ($byDate ? ' AND d.modified = e.modified' : '');
        $join = ($dupsOnly ? 'JOIN' : 'LEFT JOIN') . " (SELECT $keys, COUNT(*) n, MIN(id) g FROM entries WHERE is_dir = 0 AND apparent > 0 GROUP BY $keys HAVING COUNT(*) > 1) d ON $on";
        $dupCols = 'd.g AS dup_group, d.n AS dup_n';
    }
    $from = "FROM entries e LEFT JOIN entries p ON p.id = e.parent $join WHERE " . implode(' AND ', $where);
    $count = $db->prepare("SELECT COUNT(*) $from");
    $count->execute($args);
    $order = $dupsOnly ? 'e.size DESC, d.g, e.id' : 'e.size DESC';
    $st = $db->prepare("SELECT e.*, $pathExpr AS full_path, $dupCols $from ORDER BY $order LIMIT $max");
    $st->execute($args);
    $files = [];
    foreach ($st->fetchAll() as $r) {
        $f = summary($r) + ['path' => $r['full_path']];
        if ($r['dup_group'] !== null) {
            $f += ['dup_group' => (int) $r['dup_group'], 'dup_count' => (int) $r['dup_n'] - 1, 'dup_size' => (int) $r['apparent'] * ((int) $r['dup_n'] - 1)];
        }
        $files[] = $f;
    }
    return ['files' => $files, 'matches' => (int) $count->fetchColumn(), 'root_size' => (int) entry($db, 0)['size']];
}

/**
 * Moves an item into cPanel's own trash (~/.trash), where File Manager can show and
 * restore it. Top-level account folders and the dashboard itself are refused.
 */
function move_to_trash(PDO $db, int $id): array
{
    $e = entry_or_fail($db, $id);
    $path = path_of($db, $e);
    $home = realpath(home_dir());
    $real = realpath($path);
    if ($id === 0 || $real === false || !inside_home($real) || $real === $home) {
        fail('This item cannot be moved to Trash.');
    }
    if (dirname($real) === $home) {
        fail('Top-level account folders (like public_html, mail or etc) are protected. Open them and remove what is inside instead.');
    }
    foreach ([realpath(__DIR__), realpath(data_dir()), $home . '/.trash'] as $protected) {
        if ($protected && ($real === $protected || str_starts_with($real, $protected . '/') || str_starts_with($protected, $real . '/'))) {
            fail('This item is protected.');
        }
    }
    $trash = $home . '/.trash';
    if (!is_dir($trash)) {
        mkdir($trash, 0700);
    }
    $name = basename($real);
    for ($target = "$trash/$name", $n = 1; file_exists($target); $n++) {
        $target = "$trash/$name.$n";
    }
    if (!@rename($real, $target)) {
        fail('Could not move to Trash (permission denied).');
    }
    file_put_contents("$trash/.trash_restore", basename($target) . '=' . $real . "\n", FILE_APPEND);
    return ['ok' => true, 'path' => $real];
}

/** cPanel resources: quota, inodes, databases, email accounts and a breakdown by kind. */
function account_overview(?PDO $db): array
{
    $quota = uapi('Quota', 'get_quota_info')['data'] ?? null;
    $mysql = uapi('Mysql', 'list_databases');
    $pg = uapi('Postgresql', 'list_databases');
    $mail = uapi('Email', 'list_pops_with_disk');
    $dbs = [];
    foreach ([['MySQL', $mysql], ['PostgreSQL', $pg]] as [$engine, $res]) {
        foreach ($res['data'] ?? [] as $d) {
            $dbs[] = ['engine' => $engine, 'name' => $d['database'] ?? '', 'size' => (int) ($d['disk_usage'] ?? 0), 'users' => $d['users'] ?? []];
        }
    }
    usort($dbs, fn($a, $b) => $b['size'] <=> $a['size']);
    $emails = [];
    foreach ($mail['data'] ?? [] as $m) {
        $used = isset($m['_diskused']) ? (int) $m['_diskused'] : (int) round((float) ($m['diskused'] ?? 0) * 1048576);
        $quotaBytes = isset($m['_diskquota']) ? (int) $m['_diskquota'] : (is_numeric($m['diskquota'] ?? null) ? (int) round((float) $m['diskquota'] * 1048576) : 0);
        $emails[] = ['email' => $m['email'] ?? $m['login'] ?? '', 'used' => $used, 'quota' => $quotaBytes];
    }
    usort($emails, fn($a, $b) => $b['used'] <=> $a['used']);

    // Breakdown of the scanned home folder by what the space is used for.
    $groups = [];
    if ($db && rtrim((string) meta($db, 'scan', [])['root'], '/') === realpath(home_dir())) {
        $kind = function (string $name): string {
            if ($name === 'public_html' || $name === 'www') return 'Website files';
            if ($name === 'mail') return 'Email';
            if (in_array($name, ['logs', 'access-logs'], true)) return 'Logs';
            if ($name === '.trash') return 'Trash';
            if (in_array($name, ['tmp', '.cache', '.cpanel', '.cagefs', '.npm', '.composer'], true)) return 'Cache & temporary';
            if ($name === 'softaculous_backups' || preg_match('/^backup|\.tar\.gz$/i', $name)) return 'Backups';
            return 'Other files';
        };
        $st = $db->query('SELECT name, size, files FROM entries WHERE parent = 0');
        foreach ($st->fetchAll() as $r) {
            $k = $kind($r['name']);
            $groups[$k]['size'] = ($groups[$k]['size'] ?? 0) + (int) $r['size'];
            $groups[$k]['files'] = ($groups[$k]['files'] ?? 0) + (int) $r['files'];
        }
    }
    $dbTotal = array_sum(array_column($dbs, 'size'));
    if ($dbs) {
        $groups['Databases'] = ['size' => $dbTotal, 'files' => count($dbs)];
    }
    $out = [];
    foreach ($groups as $k => $g) {
        $out[] = ['kind' => $k] + $g;
    }
    usort($out, fn($a, $b) => $b['size'] <=> $a['size']);
    return [
        'available' => $quota !== null,
        'quota' => $quota ? ['used' => (float) $quota['megabytes_used'] * 1048576, 'limit' => (float) $quota['megabyte_limit'] * 1048576,
                             'inodes_used' => (int) $quota['inodes_used'], 'inodes_limit' => (int) $quota['inode_limit']] : null,
        'groups' => $out, 'databases' => $dbs, 'emails' => $emails, 'scanned_home' => $db !== null && $groups !== [] ,
    ];
}

function csv_cell(string $s): string
{
    // Spreadsheet formula protection also applies to file names.
    $prefix = preg_match('/^[=+\-@\t\r]/', $s) ? "'" : '';
    return '"' . $prefix . str_replace('"', '""', $s) . '"';
}

function export_csv(PDO $db): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="disk-usage.csv"');
    echo "Path,Type,Allocated bytes,Apparent bytes,Files,Folders,Modified\r\n";
    $st = $db->query("SELECT e.*, CASE WHEN e.is_dir THEN e.path ELSE p.path || '/' || e.name END AS full_path FROM entries e LEFT JOIN entries p ON p.id = e.parent ORDER BY e.id");
    while ($r = $st->fetch()) {
        echo csv_cell($r['full_path']), ',', $r['is_dir'] ? 'Folder' : 'File', ',', $r['size'], ',', $r['apparent'], ',', $r['files'], ',', $r['dirs'], ',', date('c', (int) $r['modified']), "\r\n";
    }
    exit;
}
