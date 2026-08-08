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
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$action = Input::get('action');
$container_id = (int) Input::get('container_id');
if (!$container_id) {
    echo json_encode(['success' => false, 'message' => 'Container ID required']);
    exit;
}

$container = getContainerById($container_id);
if (!$container) {
    echo json_encode(['success' => false, 'message' => 'Container not found']);
    exit;
}

try {
    if ($action === 'check') {
        // Read-only — no CSRF needed. Returns what's missing plus who to offer as recipients.
        $missing = getMissingPhotoTypes($container);
        $floor_workers = array_map(fn($u) => [
            'id' => (int) $u->id,
            'name' => trim($u->fname . ' ' . $u->lname),
        ], getFloorWorkers());

        echo json_encode([
            'success' => true,
            'missing' => array_values($missing),
            'default_recipient_id' => $container->created_by ? (int) $container->created_by : null,
            'floor_workers' => $floor_workers,
            'messages_plugin_enabled' => function_exists('sendPlgMessage'),
        ]);
        exit;
    }

    if ($action === 'send') {
        if (!Token::check(Input::get('csrf'))) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }

        $recipient_id = (int) Input::get('recipient_id');
        $custom_message = Input::get('message');
        $user_id = $user->data()->id;

        $result = notifyMissingPhotos($container_id, $recipient_id, $user_id, $custom_message);
        echo json_encode([
            'success' => $result['sent'],
            'message' => $result['sent'] ? 'Alert sent.' : $result['reason'],
            'missing' => array_values($result['missing']),
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
} catch (\Throwable $e) {
    error_log('container_notify_missing.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
