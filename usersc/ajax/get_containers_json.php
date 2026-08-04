<?php
/**
 * Get Containers JSON — Container Tracking System
 * Returns the same container dataset the pro dashboard uses on initial load.
 * Called every 60 seconds by both dashboards for auto-refresh.
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

$containers    = getAllContainers();
$container_ids = array_map(fn($c) => (int)$c->id, $containers);

$photos_by_container = [];
if (!empty($container_ids)) {
    $db           = DB::getInstance();
    $placeholders = implode(',', array_fill(0, count($container_ids), '?'));
    $all_photos   = $db->query(
        "SELECT * FROM container_photos WHERE container_id IN ($placeholders) ORDER BY uploaded_at ASC",
        $container_ids
    )->results() ?: [];
    foreach ($all_photos as $photo) {
        $photos_by_container[$photo->container_id][] = [
            'id'          => (int) $photo->id,
            'url'         => $us_url_root . $photo->file_path,
            'photo_type'  => $photo->photo_type,
            'description' => $photo->description,
        ];
    }
}

$data = [];
foreach ($containers as $c) {
    $data[] = [
        'id'               => (int) $c->id,
        'container_number' => $c->container_number,
        'shipment_number'  => $c->shipment_number,
        'receipt_ship_date'=> $c->receipt_ship_date,
        'po_bol_number'    => $c->po_bol_number,
        'carrier'          => $c->carrier,
        'piece_count'      => $c->piece_count !== null ? (int) $c->piece_count : null,
        'seal_number'      => $c->seal_number,
        'customer_id'      => $c->customer_id ? (int) $c->customer_id : null,
        'customer_name'    => $c->customer_name,
        'type'             => $c->type,
        'status'           => $c->status,
        'notes'            => $c->notes ?? null,
        'created_by_name'  => trim(($c->creator_fname ?? '') . ' ' . ($c->creator_lname ?? '')),
        'created_at'       => $c->created_at,
        'updated_at'       => $c->updated_at,
        'photo_count'      => (int) ($c->photo_count ?? 0),
        'photos'           => $photos_by_container[$c->id] ?? [],
    ];
}

echo json_encode(['success' => true, 'containers' => $data, 'ts' => time()]);
