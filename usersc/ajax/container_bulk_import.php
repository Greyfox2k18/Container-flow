<?php
/**
 * Bulk Import — Container Tracking System
 *
 * Creates multiple containers from a JSON array of row objects.
 * Called by container_bulk_import.php after the user has mapped columns
 * and confirmed the preview.
 *
 * Each row object should have:
 *   container_number  (required, will be uppercased)
 *   type              'inbound' | 'outbound'  (default: inbound)
 *   customer_name     matched against customers table by name (optional)
 *   shipment_number   (optional)
 *   receipt_ship_date YYYY-MM-DD (optional)
 *   po_bol_number     (optional)
 *   carrier           (optional)
 *   seal_number       (optional)
 *   piece_count       integer (optional)
 *
 * Returns per-row results so the frontend can show what succeeded / failed.
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

    exit;
}

if (!Token::check(Input::get('csrf'))) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$raw = Input::get('rows');
if (!$raw) {
    echo json_encode(['success' => false, 'message' => 'No row data provided']);
    exit;
}

$rows = json_decode($raw, true);
if (!is_array($rows) || empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or empty row data']);
    exit;
}

$db      = DB::getInstance();
$user_id = $user->data()->id;

// Pre-load customer name -> id map for fast matching
$customers_raw = getAllCustomers();
$customer_map  = [];
foreach ($customers_raw as $c) {
    $customer_map[strtolower(trim($c->name))] = (int) $c->id;
}

$results  = [];
$created  = 0;
$skipped  = 0;

foreach ($rows as $idx => $row) {
    $row_num = $idx + 1;

    // ── Sanitise / resolve fields ────────────────────────────────────────
    $container_number = strtoupper(trim($row['container_number'] ?? ''));
    if (empty($container_number)) {
        $results[] = ['row' => $row_num, 'success' => false, 'reason' => 'No container number'];
        $skipped++;
        continue;
    }

    $type = strtolower(trim($row['type'] ?? 'inbound'));
    if (!in_array($type, ['inbound', 'outbound'])) {
        $type = 'inbound';
    }

    // Duplicate check
    $exists = $db->query(
        "SELECT id FROM containers WHERE container_number = ?",
        [$container_number]
    )->first();
    if ($exists) {
        $results[] = [
            'row'     => $row_num,
            'success' => false,
            'reason'  => "Container number {$container_number} already exists",
            'container_number' => $container_number,
        ];
        $skipped++;
        continue;
    }

    // Customer name lookup (case-insensitive)
    $customer_id = null;
    $customer_name_raw = trim($row['customer_name'] ?? '');
    if ($customer_name_raw !== '') {
        $customer_id = $customer_map[strtolower($customer_name_raw)] ?? null;
    }

    // Date validation
    $receipt_ship_date = null;
    $date_raw = trim($row['receipt_ship_date'] ?? '');
    if ($date_raw !== '') {
        $parsed = date_create($date_raw);
        $receipt_ship_date = $parsed ? date_format($parsed, 'Y-m-d') : null;
    }

    $piece_count = null;
    if (isset($row['piece_count']) && $row['piece_count'] !== '') {
        $pc = filter_var($row['piece_count'], FILTER_VALIDATE_INT);
        if ($pc !== false && $pc >= 0) $piece_count = $pc;
    }

    // ── Insert ───────────────────────────────────────────────────────────
    try {
        $db->insert('containers', [
            'container_number'  => $container_number,
            'type'              => $type,
            'status'            => 'pending',
            'customer_id'       => $customer_id,
            'shipment_number'   => trim($row['shipment_number'] ?? '') ?: null,
            'receipt_ship_date' => $receipt_ship_date,
            'po_bol_number'     => trim($row['po_bol_number']   ?? '') ?: null,
            'carrier'           => trim($row['carrier']         ?? '') ?: null,
            'seal_number'       => trim($row['seal_number']     ?? '') ?: null,
            'piece_count'       => $piece_count,
            'created_by'        => $user_id,
        ]);

        $new_id = $db->lastId();
        logContainerActivity($new_id, $user_id, 'created', 'Created via bulk import');

        $results[] = [
            'row'              => $row_num,
            'success'          => true,
            'id'               => (int) $new_id,
            'container_number' => $container_number,
            'customer_matched' => $customer_id ? true : ($customer_name_raw !== ''),
        ];
        $created++;

    } catch (\Throwable $e) {
        error_log('container_bulk_import error row ' . $row_num . ': ' . $e->getMessage());
        $results[] = [
            'row'              => $row_num,
            'success'          => false,
            'reason'           => 'Server error — check for duplicate container number',
            'container_number' => $container_number,
        ];
        $skipped++;
    }
}

echo json_encode([
    'success' => true,
    'created' => $created,
    'skipped' => $skipped,
    'results' => $results,
    'message' => $created . ' container' . ($created !== 1 ? 's' : '') . ' created' .
                 ($skipped > 0 ? ', ' . $skipped . ' skipped' : ''),
]);
