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
    echo json_encode(['success' => false, 'message' => 'Only supervisors can archive containers']);
    exit;
}
if (!Token::check(Input::get('csrf'))) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$container_id = (int) Input::get('container_id');
$action = Input::get('action');

if (!$container_id || !in_array($action, ['archive', 'unarchive'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$container = getContainerById($container_id);
if (!$container) {
    echo json_encode(['success' => false, 'message' => 'Container not found']);
    exit;
}

try {
    $user_id = $user->data()->id;
    if ($action === 'archive') {
        archiveContainer($container_id, $user_id);
    } else {
        unarchiveContainer($container_id, $user_id);
    }

    $fresh = getContainerById($container_id);
    echo json_encode(['success' => true, 'archived_at' => $fresh->archived_at]);
} catch (\Throwable $e) {
    error_log('container_archive_toggle.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
