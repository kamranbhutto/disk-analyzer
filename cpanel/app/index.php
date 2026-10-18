<?php
// Advanced Disk Analyzer: the dashboard page (and its sign-in screen).

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/auth.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Referrer-Policy: same-origin");

if (!plugin_mode()) {
    if (isset($_GET['logout'])) {
        sign_out();
        header('Location: ./');
        exit;
    }
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user'])) {
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin';
        $error = in_array($site, ['same-origin', 'none'], true)
            ? try_sign_in(trim((string) $_POST['user']), (string) ($_POST['pass'] ?? ''))
            : 'Please sign in from this page.';
        if ($error === null) {
            header('Location: ./');
            exit;
        }
    }
    if (!signed_in()) {
        login_page($error);
        exit;
    }
}

$boot = ['user' => account_user(), 'csrf' => csrf_token(), 'api' => plugin_mode() ? 'api.live.php' : 'api.php', 'plugin' => plugin_mode()];
$html = file_get_contents(__DIR__ . '/ui.html');
echo str_replace('/*DA_BOOT*/{ user: "", csrf: "", api: "api.php", plugin: false }', json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG), $html);

function login_page(?string $error): void
{
    $user = htmlspecialchars((string) ($_POST['user'] ?? ''), ENT_QUOTES);
    $err = $error ? '<p class="err">' . htmlspecialchars($error, ENT_QUOTES) . '</p>' : '';
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Advanced Disk Analyzer · Sign in</title>
<style>
:root { --bg: #1e1e1e; --panel: #252526; --line: #3a3a3c; --text: #e6e6e6; --muted: #a0a0a5; --accent: #0a84ff; }
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--text); font: 14px/1.4 -apple-system, "Segoe UI", Ubuntu, sans-serif; padding: 16px; }
form { width: min(360px, 100%); background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 24px; }
h1 { font-size: 18px; margin: 12px 0 4px; } p { color: var(--muted); margin: 0 0 16px; }
label { display: block; margin: 12px 0 4px; color: var(--muted); }
input { width: 100%; height: 36px; border-radius: 7px; border: 1px solid var(--line); background: #2f2f32; color: var(--text); padding: 0 10px; font: inherit; }
button { width: 100%; height: 38px; margin-top: 18px; border: 0; border-radius: 7px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
.err { color: #ff8a8a; margin: 12px 0 0; }
svg { width: 44px; height: 44px; }
</style>
</head>
<body>
<form method="post" autocomplete="on">
  <svg viewBox="0 0 64 64" aria-hidden="true"><rect width="64" height="64" rx="14" fill="#2c313c"/><rect x="10" y="10" width="26" height="44" rx="4" fill="#a3e07a"/><rect x="40" y="10" width="14" height="20" rx="4" fill="#7fb6e0"/><rect x="40" y="34" width="14" height="9" rx="3" fill="#b9a0ea"/><rect x="40" y="46" width="14" height="8" rx="3" fill="#efb878"/></svg>
  <h1>Advanced Disk Analyzer</h1>
  <p>Sign in with your cPanel username and password.</p>
  <label for="user">cPanel username</label><input id="user" name="user" value="$user" autocomplete="username" required autofocus>
  <label for="pass">Password</label><input id="pass" name="pass" type="password" autocomplete="current-password" required>
  $err
  <button>Sign in</button>
</form>
</body>
</html>
HTML;
}
