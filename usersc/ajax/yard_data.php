<?php
/**
 * Yard Board live data. Polled every few seconds by yard_board.php.
 *   ?warehouse_id=N&since=<version>  → {unchanged:true} if nothing moved,
 *                                     otherwise the full board payload.
 *   ?unit_history=ID                  → recent events for one card.
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/container_functions.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/yard_functions.php';

ob_end_clean();
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!$user->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}
$user_id = (int) $user->data()->id;

try {
    ensureYardTables();

    $history_id = (int) Input::get('unit_history');
    if ($history_id) {
        $unit = getYardUnitById($history_id);
        if (!$unit || !yardCanAccessWarehouse($user_id, $unit->warehouse_id)) {
            echo json_encode(['success' => false, 'message' => 'Not found']);
            exit;
        }
        $rows = DB::getInstance()->query(
            "SELECT e.action, e.from_code, e.to_code, e.details, e.created_at, u.fname, u.lname
             FROM yard_events e LEFT JOIN users u ON u.id = e.user_id
             WHERE e.unit_id = ? ORDER BY e.id DESC LIMIT 25",
            [$history_id]
        )->results() ?: [];
        echo json_encode(['success' => true, 'events' => array_map(fn($r) => [
            'action'  => $r->action,
            'from'    => $r->from_code,
            'to'      => $r->to_code,
            'details' => $r->details,
            'at'      => $r->created_at,
            'by'      => trim(($r->fname ?? '') . ' ' . substr((string) ($r->lname ?? ''), 0, 1)),
        ], $rows)]);
        exit;
    }

    [$warehouse_id] = yardResolveWarehouse($user_id, Input::get('warehouse_id'));
    $since = (string) Input::get('since');
    if ($since !== '' && $since === getYardVersion($warehouse_id)) {
        echo json_encode(['success' => true, 'unchanged' => true]);
        exit;
    }
    echo json_encode(['success' => true] + getYardBoard($warehouse_id));
} catch (\Throwable $e) {
    error_log('yard_data: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error loading the yard.']);
}
