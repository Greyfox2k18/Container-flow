#!/usr/bin/env php
<?php
/**
 * Nightly Google Drive backup - run this via cron, not a browser.
 *
 * Example crontab entry (runs every night at 2am):
 *   0 2 * * * /usr/bin/php /var/www/container-flow.com/html/usersc/cron/drive_backup.php >> /var/www/container-flow.com/html/usersc/logs/cron_output.log 2>&1
 *
 * This deliberately opens its own database connection rather than going
 * through UserSpice's normal init.php chain, since that chain assumes a
 * web request (sessions, cookies, $_SERVER values) that don't exist when
 * PHP is invoked directly from cron.
 */

// This file lives under the web root for convenience, but must never run
// as a web request - it has no login/permission check of its own, since
// cron has no session to check. Refuse anything that isn't a CLI call.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/../includes/drive_backup_config.php';
require_once __DIR__ . '/../includes/drive_backup_core.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DRIVE_BACKUP_DB_HOST . ';dbname=' . DRIVE_BACKUP_DB_NAME . ';charset=utf8mb4',
        DRIVE_BACKUP_DB_USER,
        DRIVE_BACKUP_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $message = '[' . date('Y-m-d H:i:s') . '] FATAL: Database connection failed - ' . $e->getMessage();
    writeDriveBackupLog([$message]);
    fwrite(STDERR, $message . "\n");
    exit(1);
}

echo "Starting Drive backup run...\n\n";

$run_start_time = time();
$progress = function ($processed, $total, $success_count, $fail_count, $container) use ($run_start_time) {
    $elapsed = max(1, time() - $run_start_time);
    $rate = $processed / $elapsed; // containers per second
    $remaining = $total - $processed;
    $eta_seconds = $rate > 0 ? (int) round($remaining / $rate) : 0;
    $eta = $eta_seconds >= 60 ? round($eta_seconds / 60) . 'm' : $eta_seconds . 's';
    $pct = $total > 0 ? round(100 * $processed / $total) : 100;

    echo sprintf(
        "  [%3d%%] %d / %d containers - ok: %d, failed/partial: %d - last: %s - est. remaining: %s\n",
        $pct, $processed, $total, $success_count, $fail_count, $container->container_number, $eta
    );
};

$result = runDriveBackup($pdo, DRIVE_BACKUP_SITE_ROOT, $progress);
$log_text = writeDriveBackupLog($result['log']);

$total_elapsed = time() - $run_start_time;
echo "\nDone in " . ($total_elapsed >= 60 ? round($total_elapsed / 60, 1) . " minutes" : "{$total_elapsed} seconds") . ".\n\n";
echo $log_text;
exit($result['success'] ? 0 : 1);
