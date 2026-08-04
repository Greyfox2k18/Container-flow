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

if (!isFloorWorker()) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (!Token::check(Input::get('csrf'))) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$container_id = Input::get('container_id');
if (!$container_id) {
    echo json_encode(['success' => false, 'message' => 'Container ID required']);
    exit;
}

$container = getContainerById($container_id);
if (!$container) {
    echo json_encode(['success' => false, 'message' => 'Container not found']);
    exit;
}

$container_number = trim(Input::get('container_number'));
if (empty($container_number)) {
    echo json_encode(['success' => false, 'message' => 'Container number is required']);
    exit;
}

$user_id = $user->data()->id;

try {
    $update_data = [
        'container_number' => $container_number,
        'shipment_number' => Input::get('shipment_number'),
        'seal_number' => Input::get('seal_number'),
        'customer_id' => Input::get('customer_id') ?: null,
        'type' => Input::get('type'),
        'status' => Input::get('status'),
        'notes' => Input::get('notes'),
    ];

    $updated = updateContainer($container_id, $update_data);

    if ($updated !== false) {
        logContainerActivity($container_id, $user_id, 'updated', 'Updated container information');

        // Return the fresh row so the dashboard can update in place without a reload
        $db = DB::getInstance();
        $fresh = getContainerById($container_id);
        $customer = $fresh->customer_id ? getCustomerById($fresh->customer_id) : null;
        $creator = $db->query("SELECT fname, lname FROM users WHERE id = ?", [$fresh->created_by])->first();

        echo json_encode([
            'success' => true,
            'message' => 'Container updated',
            'container' => [
                'id' => (int)$fresh->id,
                'container_number' => $fresh->container_number,
                'shipment_number' => $fresh->shipment_number,
                'seal_number' => $fresh->seal_number,
                'customer_id' => $fresh->customer_id,
                'customer_name' => $customer ? $customer->name : null,
                'type' => $fresh->type,
                'status' => $fresh->status,
                'notes' => $fresh->notes,
                'created_by_name' => $creator ? trim($creator->fname . ' ' . $creator->lname) : null,
                'created_at' => $fresh->created_at,
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No changes were saved']);
    }
} catch (\Throwable $e) {
    // Catches fatal-style errors too (e.g. "Call to a member function on null"),
    // so a future bug returns a readable JSON message instead of a blank HTTP 500.
    error_log('container_update.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')'
    ]);
}
