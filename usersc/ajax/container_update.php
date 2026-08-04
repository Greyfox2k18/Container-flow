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

$container_number = strtoupper(trim(Input::get('container_number')));
if (empty($container_number)) {
    echo json_encode(['success' => false, 'message' => 'Container number is required']);
    exit;
}

$user_id = $user->data()->id;

try {
    $update_data = [
        'container_number' => $container_number,
        'shipment_number' => Input::get('shipment_number'),
        'receipt_ship_date' => Input::get('receipt_ship_date') ?: null,
        'po_bol_number' => Input::get('po_bol_number'),
        'carrier' => Input::get('carrier'),
        'piece_count' => Input::get('piece_count'),
        'seal_number' => Input::get('seal_number'),
        'customer_id' => Input::get('customer_id') ?: null,
        'type' => Input::get('type'),
        'status' => Input::get('status'),
        'notes' => Input::get('notes'),
    ];

    $was_reviewed_already = ($container->status === 'reviewed');
    $new_status = Input::get('status');

    $updated = updateContainer($container_id, $update_data);

    if ($updated !== false) {
        logContainerActivity($container_id, $user_id, 'updated', 'Updated container information');

        // Floor work being marked Completed no longer emails anyone - that
        // now happens when a supervisor reviews the photos and marks it
        // Reviewed. Only fires once per transition.
        if ($new_status === 'reviewed' && !$was_reviewed_already) {
            sendCompletionNotification($container_id, $user_id);
        }

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
                'receipt_ship_date' => $fresh->receipt_ship_date,
                'po_bol_number' => $fresh->po_bol_number,
                'carrier' => $fresh->carrier,
                'piece_count' => $fresh->piece_count !== null ? (int)$fresh->piece_count : null,
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
        $db_error = DB::getInstance()->errorString();
        $message = $db_error ? "Database rejected the update: {$db_error}" : 'No changes were saved';
        error_log('container_update.php: updateContainer failed - ' . ($db_error ?: 'no error string available'));
        echo json_encode(['success' => false, 'message' => $message]);
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
