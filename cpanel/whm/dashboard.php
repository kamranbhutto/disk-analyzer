<?php
// Advanced Disk Analyzer for WHM: admin dashboard with every account's disk and
// inode usage, growth, server disks and quota alert settings. Root only.

declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// WHM signs the admin in; only root may use this page. (PHP's built-in server is
// only used by the local tests.)
$isRoot = PHP_SAPI === 'cli-server' || getenv('REMOTE_USER') === 'root';
if (!$isRoot) {
    http_response_code(403);
    exit('Advanced Disk Analyzer for WHM is available to root only.');
}

$action = (string) ($_GET['a'] ?? '');
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($post) {
            $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin';
            if (!in_array($site, ['same-origin', 'none'], true) || !hash_equals(settings()['token'], (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
                throw new DomainException('This request did not come from the dashboard.');
            }
        }
        $body = $post ? (json_decode((string) file_get_contents('php://input'), true) ?? []) : [];
        switch ($action) {
            case 'data':
                $accounts = accounts();
                $growth = growth($accounts);
                foreach ($accounts as &$a) {
                    $a['pct'] = percent($a['used'], $a['limit']);
                    $a['ipct'] = percent($a['inodes'], $a['inode_limit']);
                    $a['growth'] = $growth[$a['user']];
                }
                unset($a);
                $s = settings();
                unset($s['token']);
                echo json_encode([
                    'accounts' => $accounts, 'disks' => server_disks(), 'history' => history_series(),
                    'settings' => $s + ['contact' => server_contact()], 'last_check' => read_json(data_dir() . '/last-check.json'),
                    'host' => host_name(), 'version' => ADA_VERSION,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                break;
            case 'settings':
                $post || throw new DomainException('Use POST.');
                $s = save_settings($body);
                unset($s['token']);
                echo json_encode(['ok' => true, 'settings' => $s]);
                break;
            case 'check':
                $post || throw new DomainException('Use POST.');
                $accounts = accounts();
                record_snapshot($accounts);
                echo json_encode(check_alerts($accounts));
                break;
            case 'csv':
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="accounts-disk-usage.csv"');
                echo "User,Domain,Owner,Plan,Suspended,Disk used bytes,Disk limit bytes,Disk %,Inodes,Inode limit,Inode %\r\n";
                foreach (accounts() as $a) {
                    $cells = [$a['user'], $a['domain'], $a['owner'], $a['plan'], $a['suspended'] ? 'yes' : 'no', $a['used'], $a['limit'] ?? 'unlimited',
                        percent($a['used'], $a['limit']) ?? '', $a['inodes'] ?? '', $a['inode_limit'] ?? 'unlimited', percent($a['inodes'], $a['inode_limit']) ?? ''];
                    echo implode(',', array_map(fn($c) => '"' . (preg_match('/^[=+\-@]/', (string) $c) ? "'" : '') . str_replace('"', '""', (string) $c) . '"', $cells)), "\r\n";
                }
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Unknown action.']);
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (DomainException $e) {
        http_response_code(403);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$html = file_get_contents(__DIR__ . '/ui.html');
echo str_replace('/*ADA_TOKEN*/""', json_encode(settings()['token']), $html);
