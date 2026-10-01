<?php
/**
 * SKU Lot / Expiration Scanner — AJAX endpoint
 * Actions (POST): start_session, save_lot, delete_lot
 *
 * Follows the same shutdown-catches-fatals-as-JSON pattern used in
 * usersc/ajax/trigger_digest.php so a PHP error never breaks the
 * scan page's fetch().json() parsing.
 */
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'PHP Fatal Error: ' . $err['message'] . ' in ' . basename($err['file']) . ' line ' . $err['line'],
        ]);
    }
});

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/sku_scan_functions.php';

ob_end_clean();
header('Content-Type: application/json');

if (!$user->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if (!Token::check(Input::get('csrf'))) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF token — refresh the page and try again.']);
    exit;
}

$user_id = $user->data()->id;
$action  = Input::get('action');

try {
    ensureSkuScanTables();

    if ($action === 'start_session') {
        $sku          = trim((string) Input::get('sku'));
        $container_id = (int) Input::get('container_id');
        if ($sku === '') {
            echo json_encode(['success' => false, 'message' => 'SKU is required.']);
            exit;
        }
        $session_id = createScanSession($sku, $user_id, $container_id ?: null);
        echo json_encode(['success' => true, 'session_id' => $session_id]);
        exit;
    }

    if ($action === 'save_lot') {
        $session_id      = (int) Input::get('session_id');
        $lot_number      = trim((string) Input::get('lot_number'));
        $expiration_raw  = trim((string) Input::get('expiration_raw'));
        $expiration_date = trim((string) Input::get('expiration_date'));
        $quantity        = (int) Input::get('quantity');

        if (!$session_id || !getScanSession($session_id)) {
            echo json_encode(['success' => false, 'message' => 'Session not found — start a new SKU.']);
            exit;
        }
        if ($lot_number === '') {
            echo json_encode(['success' => false, 'message' => 'Lot number is required.']);
            exit;
        }
        if ($quantity < 1) {
            $quantity = 1;
        }

        // Re-validate the normalized date server-side rather than trusting the browser.
        $exp_date_clean = null;
        if ($expiration_date !== '') {
            $d = \DateTime::createFromFormat('Y-m-d', $expiration_date);
            if ($d && $d->format('Y-m-d') === $expiration_date) {
                $exp_date_clean = $expiration_date;
            }
        }

        $lot = addScanLot($session_id, $lot_number, $expiration_raw ?: null, $exp_date_clean, $quantity, $user_id);
        echo json_encode(['success' => true, 'lot' => [
            'id'              => $lot->id,
            'lot_number'      => $lot->lot_number,
            'expiration_raw'  => $lot->expiration_raw,
            'expiration_date' => $lot->expiration_date,
            'quantity'        => $lot->quantity,
            'scanned_at'      => $lot->scanned_at,
        ]]);
        exit;
    }

    if ($action === 'delete_lot') {
        $session_id = (int) Input::get('session_id');
        $lot_id     = (int) Input::get('lot_id');
        if (!$session_id || !$lot_id) {
            echo json_encode(['success' => false, 'message' => 'Missing session or lot id.']);
            exit;
        }
        deleteScanLot($lot_id, $session_id);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (\Throwable $e) {
    error_log('sku_scan_ajax error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
