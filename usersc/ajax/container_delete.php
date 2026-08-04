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

// Deleting is destructive (removes photos permanently) - supervisors only
if (!isSupervisor()) {
    echo json_encode(['success' => false, 'message' => 'Only supervisors can delete containers']);
    exit;
}

if (!Token::check(Input::get('csrf'))) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$user_id = $user->data()->id;

// Accept either a single id or a comma-separated list of ids (bulk delete)
$ids_raw = Input::get('ids');
if (!$ids_raw) {
    echo json_encode(['success' => false, 'message' => 'No container IDs provided']);
    exit;
}

$ids = array_filter(array_map('intval', explode(',', $ids_raw)));
if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No valid container IDs provided']);
    exit;
}

try {
    $deleted_count = 0;
    foreach ($ids as $id) {
        $container = getContainerById($id);
        if ($container) {
            if (deleteContainerCompletely($id)) {
                $deleted_count++;
            }
        }
    }

    if ($deleted_count > 0) {
        echo json_encode([
            'success' => true,
            'message' => "Deleted {$deleted_count} container(s)",
            'deleted_count' => $deleted_count
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No containers were deleted']);
    }
} catch (\Throwable $e) {
    error_log('container_delete.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')'
    ]);
}
