#!/usr/bin/env php
<?php
/**
 * Nightly local cleanup - run this via cron, not a browser.
 *
 * Two phases: ARCHIVES containers for real (safe, reversible - hides
 * them from the simple dashboard, keeps them on the Pro dashboard) once
 * they're Reviewed, backed up to Drive, and past their client's "archive
 * after" days. Then DELETES anything already archived and past its
 * client's "delete after archiving" days - gated by CLEANUP_LIVE_MODE in
 * usersc/includes/cleanup_config.php (defaults to dry run for the delete
 * phase only; archiving always runs for real).
 *
 * Example crontab entry (runs every night at 3am, after the 2am Drive
 * backup so anything newly eligible has already been backed up):
 *   0 3 * * * /usr/bin/php /var/www/container-flow.com/html/usersc/cron/container_cleanup.php >> /var/www/container-flow.com/html/usersc/logs/cron_output.log 2>&1
 *
 * Same reasoning as drive_backup.php for the raw PDO connection - this
 * runs outside the normal web request lifecycle.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/../includes/cleanup_config.php';
require_once __DIR__ . '/../includes/cleanup_core.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DRIVE_BACKUP_DB_HOST . ';dbname=' . DRIVE_BACKUP_DB_NAME . ';charset=utf8mb4',
        DRIVE_BACKUP_DB_USER,
        DRIVE_BACKUP_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $message = '[' . date('Y-m-d H:i:s') . '] FATAL: Database connection failed - ' . $e->getMessage();
    writeCleanupLog([$message]);
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$result = runContainerCleanup($pdo, DRIVE_BACKUP_SITE_ROOT, !CLEANUP_LIVE_MODE, CLEANUP_MAX_PER_RUN);
$log_text = writeCleanupLog($result['log']);

echo $log_text;
exit($result['success'] ? 0 : 1);
