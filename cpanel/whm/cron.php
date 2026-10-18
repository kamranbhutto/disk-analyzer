<?php
// Advanced Disk Analyzer for WHM: hourly job (see /etc/cron.d/advanced_disk_analyzer).
// Saves one usage snapshot per day and emails quota alerts.

declare(strict_types=1);

require __DIR__ . '/lib.php';

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
$lock = fopen(data_dir() . '/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // the previous run is still busy
}
$accounts = accounts();
record_snapshot($accounts);
$r = check_alerts($accounts);
echo date('c'), ' checked ', count($accounts), ' accounts, ', $r['new'], ' new alert(s), emailed: ', implode(', ', $r['sent']) ?: 'nobody', "\n";
