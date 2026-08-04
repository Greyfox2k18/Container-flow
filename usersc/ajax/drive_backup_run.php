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
    echo json_encode(['success' => false, 'message' => 'Only supervisors can run backups']);
    exit;
}

if (!Token::check(Input::get('csrf'))) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

require_once $abs_us_root.$us_url_root.'usersc/includes/drive_backup_config.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/drive_backup_core.php';

// Uploading many photos can take a while - give this request more room
// than the default timeout before the web server gives up on it.
set_time_limit(300);

try {
    $pdo = new PDO(
        'mysql:host=' . DRIVE_BACKUP_DB_HOST . ';dbname=' . DRIVE_BACKUP_DB_NAME . ';charset=utf8mb4',
        DRIVE_BACKUP_DB_USER,
        DRIVE_BACKUP_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $result = runDriveBackup($pdo, DRIVE_BACKUP_SITE_ROOT);
    writeDriveBackupLog($result['log']);

    echo json_encode([
        'success' => true,
        'message' => "Backup complete: {$result['success_count']} succeeded, {$result['fail_count']} failed",
        'log' => $result['log'],
    ]);
} catch (\Throwable $e) {
    error_log('drive_backup_run.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
    ]);
}
