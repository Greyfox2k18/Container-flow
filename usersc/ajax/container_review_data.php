<?php
/**
 * Returns a container's photos + key info as JSON, for the "Next Status"
 * review popup on the dashboards (the view/edit pages already show this
 * inline, so they don't need this endpoint - only the two dashboards do).
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

ob_end_clean();
header('Content-Type: application/json');

if (!$user->isLoggedIn() || !isFloorWorker()) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$container_id = Input::get('container_id');
$container = getContainerById($container_id);
if (!$container) {
    echo json_encode(['success' => false, 'message' => 'Container not found']);
    exit;
}

$db = DB::getInstance();
$customer = $container->customer_id ? getCustomerById($container->customer_id) : null;
$creator = $db->query("SELECT fname, lname FROM users WHERE id = ?", [$container->created_by])->first();
$photos = getContainerPhotos($container_id);

$photo_list = array_map(function($p) use ($us_url_root) {
    return [
        'url' => $us_url_root . $p->file_path,
        'type' => $p->photo_type,
        'description' => $p->description,
    ];
}, $photos);

echo json_encode([
    'success' => true,
    'container' => [
        'id' => (int)$container->id,
        'container_number' => $container->container_number,
        'shipment_number' => $container->shipment_number,
        'seal_number' => $container->seal_number,
        'type' => $container->type,
        'status' => $container->status,
        'customer_name' => $customer ? $customer->name : null,
        'created_by_name' => $creator ? trim($creator->fname . ' ' . $creator->lname) : null,
        'notes' => $container->notes,
    ],
    'photos' => $photo_list,
]);
