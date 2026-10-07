<?php
/**
 * Yard Board writes. POST action=
 *   save     unit_id (0 = new), card fields, location_id (new only), version
 *   move     unit_id, location_id (0 = back to Incoming), swap=1 to trade places
 *   pickup   unit_id            — leaves the yard, goes to history
 *   restore  unit_id            — undo a pickup
 *   check    unit_id            — yard check: "it's really there"
 *   delete   unit_id            — anyone for Incoming entries, supervisors otherwise
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/container_functions.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/yard_functions.php';

ob_end_clean();
header('Content-Type: application/json');

function yard_respond($ok, $message = '', $extra = []) {
    echo json_encode(['success' => $ok, 'message' => $message] + $extra);
    exit;
}

if (!$user->isLoggedIn()) yard_respond(false, 'Not authenticated');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') yard_respond(false, 'POST required');
if (!Token::check(Input::get('csrf'))) yard_respond(false, 'Your session expired — reload the page.');

$user_id = (int) $user->data()->id;
$action  = Input::get('action');

try {
    ensureYardTables();

    $unit = null;
    $unit_id = (int) Input::get('unit_id');
    if ($unit_id) {
        $unit = getYardUnitById($unit_id);
        if (!$unit || !yardCanAccessWarehouse($user_id, $unit->warehouse_id)) yard_respond(false, 'That card no longer exists — the board will refresh.');
    }

    switch ($action) {
        case 'save':
            $fields = [];
            foreach (['container_number', 'status', 'account', 'driver', 'drayman', 'notes', 'date_in', 'mt_date', 'ld_date', 'lfd', 'eta', 'hot'] as $f) {
                if (isset($_POST[$f])) $fields[$f] = $_POST[$f];
            }
            [$data, $errors] = yardCleanFields($fields);
            if ($errors) yard_respond(false, implode(' ', $errors));

            if ($unit) {
                if ($unit->picked_up_at) yard_respond(false, 'This container was already picked up.');
                $version = (string) Input::get('version');
                if ($version !== '' && $version !== (string) $unit->updated_at) {
                    yard_respond(false, 'Someone else just changed ' . $unit->container_number . '. Your edits were not saved — reopen the card to see the latest.', ['conflict' => true]);
                }
                updateYardUnit($unit, $data, $user_id);
                yard_respond(true, 'Saved');
            }

            [$warehouse_id] = yardResolveWarehouse($user_id, Input::get('warehouse_id'));
            if (empty($data['container_number'])) yard_respond(false, 'Container number is required.');
            $params = [$data['container_number']];
            $where = yardWarehouseWhere('warehouse_id', $warehouse_id, $params);
            $dupe = DB::getInstance()->query(
                "SELECT yu.id, yl.code FROM yard_units yu LEFT JOIN yard_locations yl ON yl.id = yu.location_id
                 WHERE yu.container_number = ? AND yu.{$where} AND yu.picked_up_at IS NULL LIMIT 1", $params
            )->first();
            if ($dupe) yard_respond(false, $data['container_number'] . ' is already on the board' . ($dupe->code ? ' at ' . $dupe->code : ' (Incoming)') . '.');
            [$new, $err] = createYardUnit($warehouse_id, $data, (int) Input::get('location_id') ?: null, $user_id);
            if ($err) yard_respond(false, $err);
            yard_respond(true, 'Added', ['unit_id' => (int) $new->id]);

        case 'move':
            if (!$unit) yard_respond(false, 'Missing card.');
            if ($unit->picked_up_at) yard_respond(false, 'This container was already picked up.');
            $err = moveYardUnit($unit, (int) Input::get('location_id') ?: null, $user_id, Input::get('swap') == '1');
            if ($err) yard_respond(false, $err, ['occupied' => strpos($err, 'occupied by') !== false]);
            yard_respond(true, 'Moved');

        case 'pickup':
            if (!$unit) yard_respond(false, 'Missing card.');
            if ($unit->picked_up_at) yard_respond(true, 'Already picked up');
            pickUpYardUnit($unit, $user_id);
            yard_respond(true, $unit->container_number . ' marked picked up');

        case 'restore':
            if (!$unit) yard_respond(false, 'Missing card.');
            if (!$unit->picked_up_at) yard_respond(true, 'Already on the board');
            $params = [$unit->container_number, $unit->id];
            $where = yardWarehouseWhere('warehouse_id', $unit->warehouse_id, $params);
            $dupe = DB::getInstance()->query("SELECT id FROM yard_units WHERE container_number = ? AND id != ? AND {$where} AND picked_up_at IS NULL LIMIT 1", $params)->first();
            if ($dupe) yard_respond(false, $unit->container_number . ' is already back on the board.');
            restoreYardUnit($unit, $user_id);
            yard_respond(true, $unit->container_number . ' is back on the board');

        case 'check':
            if (!$unit) yard_respond(false, 'Missing card.');
            checkYardUnit($unit, $user_id);
            yard_respond(true, 'Checked');

        case 'delete':
            if (!$unit) yard_respond(false, 'Missing card.');
            $is_incoming = !$unit->location_id && !$unit->picked_up_at;
            if (!$is_incoming && !isSupervisor()) yard_respond(false, 'Only supervisors can delete containers that have been on site. Use "Picked up" instead.');
            logYardEvent($unit, $user_id, 'deleted', $unit->last_location_code, null, 'Deleted ' . $unit->container_number);
            DB::getInstance()->delete('yard_units', (int) $unit->id);
            yard_respond(true, 'Deleted');

        default:
            yard_respond(false, 'Unknown action');
    }
} catch (\Throwable $e) {
    error_log('yard_action: ' . $e->getMessage());
    yard_respond(false, 'Server error — nothing was changed.');
}
