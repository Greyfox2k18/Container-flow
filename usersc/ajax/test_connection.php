<?php
// Test file to verify AJAX path is correct
// Place this in: usersc/ajax/test_connection.php
// Access: https://yourdomain.com/usersc/ajax/test_connection.php

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

ob_end_clean();

header('Content-Type: application/json');

$response = [
    'success' => true,
    'message' => 'AJAX path is working correctly!',
    'timestamp' => date('Y-m-d H:i:s'),
    'user_logged_in' => $user->isLoggedIn(),
    'user_id' => $user->data()->id ?? 'Not logged in',
    'is_floor_worker' => isFloorWorker(),
    'is_supervisor' => isSupervisor(),
    'server_path' => __FILE__,
    'upload_dir' => $abs_us_root.$us_url_root.'usersc/uploads/',
    'upload_dir_exists' => file_exists($abs_us_root.$us_url_root.'usersc/uploads/'),
    'upload_dir_writable' => is_writable($abs_us_root.$us_url_root.'usersc/uploads/')
];

echo json_encode($response, JSON_PRETTY_PRINT);
