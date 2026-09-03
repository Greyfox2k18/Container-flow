<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

ob_end_clean();
header('Content-Type: application/json');

if (!$user->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}
if (!isSupervisor()) {
    echo json_encode(['success' => false, 'message' => 'Only supervisors can run cleanup']);
    exit;
}
if (!Token::check(Input::get('csrf'))) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

require_once $abs_us_root.$us_url_root.'usersc/includes/cleanup_config.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/cleanup_core.php';

set_time_limit(120);

try {
    $pdo = new PDO(
        'mysql:host=' . DRIVE_BACKUP_DB_HOST . ';dbname=' . DRIVE_BACKUP_DB_NAME . ';charset=utf8mb4',
        DRIVE_BACKUP_DB_USER,
        DRIVE_BACKUP_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Respects the same CLEANUP_LIVE_MODE switch as the cron - this
    // button can't bypass dry-run mode, it just runs the same logic on demand.
    $result = runContainerCleanup($pdo, DRIVE_BACKUP_SITE_ROOT, !CLEANUP_LIVE_MODE, CLEANUP_MAX_PER_RUN);
    writeCleanupLog($result['log']);

    echo json_encode([
        'success' => true,
        'dry_run' => $result['dry_run'],
        'message' => $result['dry_run']
            ? "{$result['archived_count']} archived. Delete dry run: {$result['delete_candidate_count']} container(s) would be deleted"
            : "{$result['archived_count']} archived. {$result['deleted_count']} container(s) permanently deleted",
        'log' => $result['log'],
    ]);
} catch (\Throwable $e) {
    error_log('container_cleanup_run.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
