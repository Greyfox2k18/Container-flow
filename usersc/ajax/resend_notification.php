<?php
/**
 * Resend Photos — Container Tracking System
 *
 * Re-fires sendCompletionNotification() for a container that has already
 * been reviewed (or even one that hasn't been yet). Useful when a client
 * says they didn't receive the original email.
 *
 * Supervisor permission (10) required — floor workers cannot resend.
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/container_functions.php';

ob_end_clean();
header('Content-Type: application/json');

if (!$user->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if (!isSupervisor()) {
    echo json_encode(['success' => false, 'message' => 'Supervisor permission required']);
    exit;
}

if (!Token::check(Input::get('csrf'))) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

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

$user_id = $user->data()->id;
$result  = sendCompletionNotification($container_id, $user_id);

if ($result['sent']) {
    logContainerActivity($container_id, $user_id, 'notification_resent', 'Photos manually resent via resend button');
    echo json_encode(['success' => true, 'message' => 'Photos resent successfully']);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result['reason'] ?: 'Could not send — check that the client has notification emails configured',
    ]);
}
