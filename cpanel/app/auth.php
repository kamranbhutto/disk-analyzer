<?php
// Advanced Disk Analyzer: sign-in. Users sign in with their cPanel username and
// password, which are checked by cPanel on this same server and never stored.
// If that check is unavailable, a password hash in ~/.diskanalyzer/password.hash is
// used instead (see INSTALL.md).

declare(strict_types=1);

const MAX_FAILS = 5;            // per IP address ...
const FAIL_WINDOW = 900;        // ... in 15 minutes
const SESSION_HOURS = 8;

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
    session_name('DISKANALYZER');
    session_save_path(data_dir()); // private folder, not the shared /tmp
    session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function signed_in(): bool
{
    if (plugin_mode()) {
        return getenv('REMOTE_USER') === account_user();
    }
    start_session();
    return ($_SESSION['user'] ?? null) === account_user() && ($_SESSION['since'] ?? 0) > time() - SESSION_HOURS * 3600;
}

function csrf_token(): string
{
    if (plugin_mode()) {
        // cPanel's own security token (also in the /cpsessNNN/ URL) protects plugin pages.
        return hash('sha256', 'diskanalyzer' . getenv('cp_security_token') . getenv('REMOTE_USER'));
    }
    start_session();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_ok(): bool
{
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin';
    return in_array($site, ['same-origin', 'none'], true) && hash_equals(csrf_token(), (string) ($_SERVER['HTTP_X_CSRF'] ?? $_POST['csrf'] ?? ''));
}

function fail_file(): string { return data_dir() . '/login-fails.json'; }

function too_many_fails(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $recent = array_filter(read_json(fail_file(), [])[$ip] ?? [], fn($t) => $t > time() - FAIL_WINDOW);
    return count($recent) >= MAX_FAILS;
}

function note_fail(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $all = read_json(fail_file(), []);
    foreach ($all as $k => $times) {
        $all[$k] = array_values(array_filter($times, fn($t) => $t > time() - FAIL_WINDOW));
        if (!$all[$k]) unset($all[$k]);
    }
    $all[$ip][] = time();
    write_json(fail_file(), $all);
}

/** Returns an error message, or null when signed in. */
function try_sign_in(string $user, string $pass): ?string
{
    if (too_many_fails()) {
        return 'Too many failed attempts. Try again in 15 minutes.';
    }
    $ok = false;
    if (strcasecmp($user, account_user()) === 0 && $pass !== '') {
        $hashFile = data_dir() . '/password.hash';
        $ok = cpanel_login_ok(account_user(), $pass);
        if ($ok !== true && is_file($hashFile)) {
            $ok = password_verify($pass, trim((string) file_get_contents($hashFile)));
        }
        if ($ok === null) {
            return 'Could not check the password with cPanel on this server. Create ~/.diskanalyzer/password.hash as described in INSTALL.md.';
        }
    }
    if (!$ok) {
        note_fail();
        return 'Wrong username or password.';
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION = ['user' => account_user(), 'since' => time(), 'csrf' => bin2hex(random_bytes(32))];
    return null;
}

function sign_out(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}
