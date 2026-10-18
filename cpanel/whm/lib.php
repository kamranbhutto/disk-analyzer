<?php
// Advanced Disk Analyzer for WHM: shared code for the admin dashboard and the
// hourly alert job. Runs as root inside WHM (or from cron).

declare(strict_types=1);

const ADA_VERSION = '1.1.0';
const HISTORY_DAYS = 400;

/** Test-only overrides; real WHM and cron runs use the defaults. */
function test_env(string $name): ?string
{
    return str_starts_with(PHP_SAPI, 'cli') && getenv($name) !== false ? (string) getenv($name) : null;
}

function data_dir(): string
{
    $d = test_env('ADA_DATA') ?? '/var/cpanel/advanced_disk_analyzer';
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
    file_put_contents($tmp, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    chmod($tmp, 0600);
    rename($tmp, $file);
}

// ---------------------------------------------------------------- settings

function default_settings(): array
{
    return [
        'alerts' => true,
        'thresholds' => [80, 90],
        'admin_emails' => [],          // empty = the server contact email from WHM
        'notify_owner' => false,       // also email the account's own contact address
        'token' => bin2hex(random_bytes(16)),
    ];
}

function settings(): array
{
    $file = data_dir() . '/settings.json';
    $s = read_json($file, null);
    if (!is_array($s)) {
        $s = default_settings();
        write_json($file, $s);
    }
    return $s + default_settings();
}

/** Validates and saves settings sent from the dashboard. Returns the saved settings. */
function save_settings(array $in): array
{
    $s = settings();
    $th = array_values(array_unique(array_filter(array_map('intval', (array) ($in['thresholds'] ?? [])), fn($t) => $t >= 1 && $t <= 100)));
    sort($th);
    if (!$th) {
        throw new InvalidArgumentException('Enter at least one alert level between 1 and 100 %.');
    }
    $emails = [];
    foreach (preg_split('/[\s,;]+/', (string) ($in['admin_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $e) {
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("\"$e\" is not a valid email address.");
        }
        $emails[] = $e;
    }
    $s['alerts'] = (bool) ($in['alerts'] ?? false);
    $s['thresholds'] = array_slice($th, 0, 5);
    $s['admin_emails'] = $emails;
    $s['notify_owner'] = (bool) ($in['notify_owner'] ?? false);
    write_json(data_dir() . '/settings.json', $s);
    return $s;
}

// ---------------------------------------------------------------- WHM API

/** Calls WHM API 1 through the whmapi1 command (we are root). */
function whmapi(string $func, array $args = []): array
{
    $bin = test_env('ADA_WHMAPI') ?? '/usr/local/cpanel/bin/whmapi1';
    $cmd = escapeshellarg($bin) . ' --output=json ' . escapeshellarg($func);
    foreach ($args as $k => $v) {
        $cmd .= ' ' . escapeshellarg("$k=$v");
    }
    $out = shell_exec($cmd . ' 2>/dev/null');
    $d = $out ? json_decode($out, true) : null;
    if (!is_array($d) || ($d['metadata']['result'] ?? 0) != 1) {
        throw new RuntimeException("WHM API $func failed: " . ($d['metadata']['reason'] ?? 'no response'));
    }
    return $d['data'] ?? [];
}

/** "1.5G" / "300M" / "unlimited" (listaccts style) to bytes; null = unlimited. */
function parse_size(string $s): ?int
{
    if (!preg_match('/^([\d.]+)\s*([KMGT]?)/i', trim($s), $m)) {
        return null;
    }
    $mult = ['' => 1048576, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776][strtoupper($m[2])];
    return (int) round((float) $m[1] * $mult);
}

/**
 * Every account with its disk and inode usage. Limits are null when unlimited.
 * Uses get_disk_usage (exact, includes inodes) and listaccts (domain, owner...).
 */
function accounts(): array
{
    $meta = [];
    foreach (whmapi('listaccts', ['want' => 'user,domain,owner,plan,suspended,email,diskused,disklimit,startdate'])['acct'] ?? [] as $a) {
        $meta[$a['user']] = $a;
    }
    $usage = [];
    try {
        foreach (whmapi('get_disk_usage')['accounts'] ?? [] as $u) {
            $usage[$u['user']] = $u;
        }
    } catch (RuntimeException $e) {
        // Older cPanel: fall back to the rounded figures from listaccts.
    }
    $out = [];
    foreach ($meta as $user => $a) {
        $u = $usage[$user] ?? null;
        $used = $u ? (int) $u['blocks_used'] * 1024 : (parse_size((string) ($a['diskused'] ?? '0')) ?? 0);
        $limit = $u ? ((int) $u['blocks_limit'] ? (int) $u['blocks_limit'] * 1024 : null) : parse_size((string) ($a['disklimit'] ?? 'unlimited'));
        $out[] = [
            'user' => $user, 'domain' => $a['domain'] ?? '', 'owner' => $a['owner'] ?? '', 'plan' => $a['plan'] ?? '',
            'email' => $a['email'] ?? '', 'suspended' => (bool) ($a['suspended'] ?? false),
            'used' => $used, 'limit' => $limit,
            'inodes' => $u ? (int) $u['inodes_used'] : null,
            'inode_limit' => $u && (int) $u['inodes_limit'] ? (int) $u['inodes_limit'] : null,
        ];
    }
    return $out;
}

function percent(?int $used, ?int $limit): ?float
{
    return $limit ? round($used / $limit * 100, 1) : null;
}

/** Real disks holding home folders and the system (pseudo file systems left out). */
function server_disks(): array
{
    $out = shell_exec('df -P -B1 -x tmpfs -x devtmpfs -x squashfs -x overlay -x efivarfs 2>/dev/null') ?: '';
    $disks = [];
    foreach (array_slice(explode("\n", trim($out)), 1) as $line) {
        $p = preg_split('/\s+/', $line, 6);
        if (count($p) === 6 && ($p[5] === '/' || preg_match('#^/home\d*$|^/backup#', $p[5]))) {
            $disks[] = ['source' => $p[0], 'total' => (int) $p[1], 'used' => (int) $p[2], 'free' => (int) $p[3], 'mount' => $p[5]];
        }
    }
    return $disks;
}

// ---------------------------------------------------------------- history

function history_file(): string { return data_dir() . '/history.json'; }

/** Records one snapshot per day: total and per-account usage. */
function record_snapshot(array $accounts, ?string $day = null): void
{
    $day ??= gmdate('Y-m-d');
    $h = read_json(history_file(), []);
    $h[$day] = ['total' => array_sum(array_column($accounts, 'used')), 'users' => array_column($accounts, 'used', 'user')];
    ksort($h);
    $h = array_slice($h, -HISTORY_DAYS, null, true);
    write_json(history_file(), $h);
}

/** Growth per account over about 30 days (null when there is no older snapshot). */
function growth(array $accounts): array
{
    $h = read_json(history_file(), []);
    ksort($h);
    $cutoff = gmdate('Y-m-d', time() - 30 * 86400);
    $baseDay = null;
    foreach (array_keys($h) as $day) {
        if ($day <= $cutoff) {
            $baseDay = $day; // newest snapshot at least 30 days old
        }
    }
    $baseDay ??= array_key_first($h); // otherwise the oldest one we have
    $out = [];
    foreach ($accounts as $a) {
        $old = $h[$baseDay]['users'][$a['user']] ?? null;
        // No growth from today's own snapshot.
        $out[$a['user']] = $old !== null && $baseDay !== gmdate('Y-m-d') ? $a['used'] - (int) $old : null;
    }
    return $out;
}

function history_series(): array
{
    $out = [];
    foreach (read_json(history_file(), []) as $day => $snap) {
        $out[] = ['day' => $day, 'total' => (int) $snap['total']];
    }
    return $out;
}

// ---------------------------------------------------------------- alerts

function host_name(): string
{
    return test_env('ADA_HOST') ?? (gethostname() ?: 'this server');
}

/** The server contact email from WHM → Basic WebHost Manager Setup. */
function server_contact(): string
{
    $conf = @file_get_contents(test_env('ADA_WWWACCT') ?? '/etc/wwwacct.conf') ?: '';
    if (preg_match('/^CONTACTEMAIL[ \t=]+(\S+@\S+)/m', $conf, $m)) {
        return $m[1];
    }
    try {
        $c = whmapi('get_tweaksetting', ['key' => 'CONTACTEMAIL', 'module' => 'Basic'])['tweaksetting']['value'] ?? '';
    } catch (RuntimeException $e) {
        $c = '';
    }
    return $c ?: 'root@localhost';
}

function send_mail(string $to, string $subject, string $body): bool
{
    $sendmail = test_env('ADA_SENDMAIL') ?? '/usr/sbin/sendmail -t -i';
    $host = host_name();
    $p = popen($sendmail, 'w');
    if (!$p) {
        return false;
    }
    fwrite($p, "To: $to\nFrom: Advanced Disk Analyzer <root@$host>\nSubject: $subject\nContent-Type: text/plain; charset=utf-8\n\n$body\n");
    return pclose($p) === 0;
}

function human(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < 4) {
        $n /= 1024;
        $i++;
    }
    return ($i ? number_format($n, 1) : (string) $bytes) . ' ' . $u[$i];
}

/**
 * Emails the admin (and optionally account owners) when an account reaches a new
 * alert level. Each level is reported once; it resets when usage drops below it.
 * Returns what was sent, for the dashboard.
 */
function check_alerts(array $accounts): array
{
    $s = settings();
    $stateFile = data_dir() . '/alert-state.json';
    $state = read_json($stateFile, []);
    $new = [];
    foreach ($accounts as $a) {
        $pct = percent($a['used'], $a['limit']);
        $ipct = percent($a['inodes'], $a['inode_limit']);
        foreach (['disk' => $pct, 'inodes' => $ipct] as $what => $p) {
            $key = "{$a['user']}:$what";
            $level = 0;
            foreach ($s['thresholds'] as $t) {
                if ($p !== null && $p >= $t) {
                    $level = $t;
                }
            }
            if ($level > ($state[$key] ?? 0)) {
                $new[] = $a + ['what' => $what, 'pct' => $p, 'level' => $level];
            }
            if ($level) {
                $state[$key] = $level;
            } else {
                unset($state[$key]);
            }
        }
    }
    $sent = [];
    if ($s['alerts'] && $new) {
        $host = host_name();
        $lines = array_map(fn($n) => sprintf("  %-16s %-30s %s %5.1f%%  (%s of %s)", $n['user'], $n['domain'], $n['what'] === 'disk' ? 'disk  ' : 'inodes', $n['pct'],
            $n['what'] === 'disk' ? human($n['used']) : number_format($n['inodes']), $n['what'] === 'disk' ? human((int) $n['limit']) : number_format((int) $n['inode_limit'])), $new);
        $to = implode(', ', $s['admin_emails'] ?: [server_contact()]);
        $body = "These accounts on $host reached a new usage level:\n\n" . implode("\n", $lines) .
            "\n\nOpen WHM → Plugins → Advanced Disk Analyzer for details.\n";
        if (send_mail($to, '[Disk alert] ' . count($new) . " account(s) near their limit on $host", $body)) {
            $sent[] = $to;
        }
        if ($s['notify_owner']) {
            foreach ($new as $n) {
                if (filter_var($n['email'], FILTER_VALIDATE_EMAIL)) {
                    $what = $n['what'] === 'disk' ? 'disk space' : 'file count (inodes)';
                    $body = "Hello,\n\nYour hosting account {$n['domain']} has used {$n['pct']}% of its $what.\n" .
                        "To free space, open cPanel → Files → Advanced Disk Analyzer (or Disk Usage) and remove files you no longer need, " .
                        "or contact your hosting provider for a bigger plan.\n";
                    if (send_mail($n['email'], "Your account {$n['domain']} has used {$n['pct']}% of its $what", $body)) {
                        $sent[] = $n['email'];
                    }
                }
            }
        }
    }
    write_json($stateFile, $state);
    $log = ['at' => time(), 'new' => count($new), 'sent' => $sent];
    write_json(data_dir() . '/last-check.json', $log);
    return $log;
}
