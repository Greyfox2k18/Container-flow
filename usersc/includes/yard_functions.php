<?php
/**
 * Yard Board — door & yard container tracking (the "T-Card" sheet, live).
 *
 * Replaces the shared Google Sheet the yard used to run on:
 *   - TODAY tab        → yard_board.php (doors + yard spots, one card each)
 *   - incoming columns → "Incoming" list (status = Expected, no location)
 *   - Move Sheet       → yard_events (every place/move/swap is logged)
 *   - Picked Up tabs   → yard_history.php (units with picked_up_at set)
 *   - Yard Check tab   → "Yard check" mode on the board (checked_at)
 *   - CUSTOMER COLORS  → customers.yard_color
 *
 * A yard unit is linked to its Container Flow record (containers table)
 * by container number, so the board can show photo-workflow status and
 * jump straight to container_view.php — and container_view.php can show
 * where the container is sitting in the yard.
 *
 * Tables are created on demand by ensureYardTables(), same as the SKU
 * scan tool, so there is no separate migration to forget. The same DDL is
 * in 13_yard_migration.sql (repo root) for anyone who prefers to run it by hand.
 */

const YARD_STATUSES = ['Expected', 'Empty', 'Loaded', 'Full', 'Working', 'Partial'];

// Status pill colours (bg, text). Expected = not on site yet.
const YARD_STATUS_COLORS = [
    'Expected' => ['#e5e7eb', '#374151'],
    'Empty'    => ['#fef3c7', '#92400e'],
    'Loaded'   => ['#dcfce7', '#166534'],
    'Full'     => ['#dbeafe', '#1e40af'],
    'Working'  => ['#ede9fe', '#5b21b6'],
    'Partial'  => ['#ffedd5', '#9a3412'],
];

function yardAddColumnIfMissing($table, $column, $definition) {
    $db = DB::getInstance();
    $exists = $db->query(
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    )->first();
    if (!$exists || (int) $exists->c === 0) {
        $db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

/**
 * Creates the yard tables if missing. Cheap after the first run (one
 * static flag per request, CREATE IF NOT EXISTS otherwise).
 */
function ensureYardTables() {
    static $done = false;
    if ($done) return;
    $done = true;

    $db = DB::getInstance();
    $db->query("CREATE TABLE IF NOT EXISTS yard_locations (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        warehouse_id  INT NULL,
        code          VARCHAR(20) NOT NULL,
        kind          ENUM('door','yard') NOT NULL DEFAULT 'yard',
        sort_order    INT NOT NULL DEFAULT 0,
        active        TINYINT(1) NOT NULL DEFAULT 1,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_wh (warehouse_id)
    )");

    $db->query("CREATE TABLE IF NOT EXISTS yard_units (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        warehouse_id       INT NULL,
        location_id        INT NULL,
        container_number   VARCHAR(50) NOT NULL,
        container_id       INT NULL,
        customer_id        INT NULL,
        account            VARCHAR(100) NULL,
        status             VARCHAR(20) NOT NULL DEFAULT 'Expected',
        hot                TINYINT(1) NOT NULL DEFAULT 0,
        date_in            DATE NULL,
        mt_date            DATE NULL,
        ld_date            DATE NULL,
        driver             VARCHAR(100) NULL,
        lfd                DATE NULL,
        drayman            VARCHAR(100) NULL,
        notes              TEXT NULL,
        eta                DATE NULL,
        last_location_code VARCHAR(20) NULL,
        picked_up_at       DATETIME NULL,
        picked_up_by       INT NULL,
        checked_at         DATETIME NULL,
        checked_by         INT NULL,
        created_by         INT NULL,
        updated_by         INT NULL,
        created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_location (location_id),
        INDEX idx_wh (warehouse_id),
        INDEX idx_number (container_number),
        INDEX idx_picked (picked_up_at)
    )");

    $db->query("CREATE TABLE IF NOT EXISTS yard_events (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        unit_id     INT NULL,
        warehouse_id INT NULL,
        user_id     INT NULL,
        action      VARCHAR(30) NOT NULL,
        container_number VARCHAR(50) NULL,
        from_code   VARCHAR(20) NULL,
        to_code     VARCHAR(20) NULL,
        details     TEXT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_unit (unit_id),
        INDEX idx_wh_created (warehouse_id, created_at)
    )");

    yardAddColumnIfMissing('customers', 'yard_color', 'VARCHAR(7) NULL');
}

// ── Helpers ─────────────────────────────────────────────────────────────────

function yardNormalizeNumber($raw) {
    $n = strtoupper(trim((string) $raw));
    // Sheets/Excel turn numeric trailer numbers into floats ("551303.0").
    $n = preg_replace('/^(\d+)\.0+$/', '$1', $n);
    return preg_replace('/\s+/', '', $n);
}

/** Accepts Y-m-d, m/d/Y, m/d/y, m/d (current year) etc. Returns Y-m-d or null. */
function yardParseDate($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) return substr($raw, 0, 10);
    if (preg_match('#^(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?#', $raw, $m)) {
        $y = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) date('Y');
        if ($y < 100) $y += 2000;
        if (checkdate((int) $m[1], (int) $m[2], $y)) return sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]);
        return null;
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Stable pastel fallback for an account with no colour configured. */
function yardFallbackColor($name) {
    $name = strtoupper(trim((string) $name));
    if ($name === '') return '#f3f4f6';
    $hue = hexdec(substr(md5($name), 0, 4)) % 360;
    // HSL(h, 65%, 85%) → hex
    $s = 0.65; $l = 0.85;
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
    $m = $l - $c / 2;
    [$r, $g, $b] = $hue < 60 ? [$c, $x, 0] : ($hue < 120 ? [$x, $c, 0] : ($hue < 180 ? [0, $c, $x]
        : ($hue < 240 ? [0, $x, $c] : ($hue < 300 ? [$x, 0, $c] : [$c, 0, $x]))));
    return sprintf('#%02x%02x%02x', ($r + $m) * 255, ($g + $m) * 255, ($b + $m) * 255);
}

/**
 * Which warehouse the board should show for this user. Returns
 * [warehouse_id|null, warehouses[]]. With no warehouses configured the
 * whole yard lives under warehouse_id NULL.
 */
function yardResolveWarehouse($user_id, $requested = null) {
    $warehouses = getWarehousesForUser($user_id);
    if (empty($warehouses)) return [null, []];
    $ids = array_map(fn($w) => (int) $w->id, $warehouses);
    $requested = (int) $requested;
    if ($requested && in_array($requested, $ids, true)) return [$requested, $warehouses];
    return [$ids[0], $warehouses];
}

/** True if this user may see/edit the given warehouse's yard. */
function yardCanAccessWarehouse($user_id, $warehouse_id) {
    if (!$warehouse_id) return true;
    $allowed = getUserWarehouseIds($user_id);
    return empty($allowed) || in_array((int) $warehouse_id, $allowed, true);
}

function yardWarehouseWhere($column, $warehouse_id, &$params) {
    if ($warehouse_id) {
        $params[] = (int) $warehouse_id;
        return "{$column} = ?";
    }
    return "{$column} IS NULL";
}

// ── Locations ───────────────────────────────────────────────────────────────

function getYardLocations($warehouse_id, $active_only = true) {
    ensureYardTables();
    $params = [];
    $where = yardWarehouseWhere('warehouse_id', $warehouse_id, $params);
    if ($active_only) $where .= " AND active = 1";
    return DB::getInstance()->query(
        "SELECT * FROM yard_locations WHERE {$where} ORDER BY kind ASC, sort_order ASC, code ASC",
        $params
    )->results() ?: [];
}

function getYardLocationById($location_id) {
    if (!$location_id) return null;
    return DB::getInstance()->query("SELECT * FROM yard_locations WHERE id = ?", [(int) $location_id])->first();
}

function findYardLocationByCode($warehouse_id, $code) {
    $params = [strtoupper(trim($code))];
    $where = yardWarehouseWhere('warehouse_id', $warehouse_id, $params);
    return DB::getInstance()->query("SELECT * FROM yard_locations WHERE code = ? AND {$where} LIMIT 1", $params)->first();
}

/** Guess door vs yard spot from a code like DR05 / D5 / DOOR 5. */
function yardGuessKind($code) {
    return preg_match('/^(DR|DOOR|D)\s*\d/i', $code) ? 'door' : 'yard';
}

/** Sort key from the trailing number so DR2 sorts before DR10. */
function yardSortFromCode($code) {
    return preg_match('/(\d+)\s*$/', $code, $m) ? (int) $m[1] : 0;
}

function createYardLocation($warehouse_id, $code, $kind = null, $sort_order = null) {
    $code = strtoupper(trim($code));
    if ($code === '' || findYardLocationByCode($warehouse_id, $code)) return null;
    $db = DB::getInstance();
    $db->insert('yard_locations', [
        'warehouse_id' => $warehouse_id ?: null,
        'code'         => $code,
        'kind'         => $kind ?: yardGuessKind($code),
        'sort_order'   => $sort_order ?? yardSortFromCode($code),
    ]);
    return (int) $db->lastId();
}

/**
 * "DR" 1..14 width 2 → DR01..DR14. Skips codes that already exist.
 * Returns the number created.
 */
function createYardLocationRange($warehouse_id, $prefix, $start, $end, $kind, $pad = 2) {
    $created = 0;
    $start = max(0, (int) $start);
    $end = min($start + 500, (int) $end);
    for ($i = $start; $i <= $end; $i++) {
        $code = strtoupper(trim($prefix)) . str_pad((string) $i, max(1, (int) $pad), '0', STR_PAD_LEFT);
        if (createYardLocation($warehouse_id, $code, $kind, $i)) $created++;
    }
    return $created;
}

// ── Units ───────────────────────────────────────────────────────────────────

/**
 * Runs a write that may hit the one-unit-per-spot unique index. UserSpice's
 * DB class either throws or flags ->error() depending on install, so
 * normalise both to a bool.
 */
function yardTryWrite(callable $fn) {
    try {
        $ok = $fn(DB::getInstance());
        return $ok !== false && !DB::getInstance()->error();
    } catch (\Throwable $e) {
        return false;
    }
}

function getYardUnitById($unit_id) {
    if (!$unit_id) return null;
    return DB::getInstance()->query("SELECT * FROM yard_units WHERE id = ?", [(int) $unit_id])->first();
}

function getYardUnitAtLocation($location_id) {
    return DB::getInstance()->query("SELECT * FROM yard_units WHERE location_id = ?", [(int) $location_id])->first();
}

/** The open yard unit (not picked up) for a Container Flow container, if any. */
function getYardUnitForContainer($container) {
    try {
        ensureYardTables();
        return DB::getInstance()->query(
            "SELECT yu.*, yl.code AS location_code, yl.kind AS location_kind
             FROM yard_units yu LEFT JOIN yard_locations yl ON yl.id = yu.location_id
             WHERE yu.picked_up_at IS NULL AND (yu.container_id = ? OR yu.container_number = ?)
             ORDER BY yu.location_id IS NULL, yu.updated_at DESC LIMIT 1",
            [(int) $container->id, yardNormalizeNumber($container->container_number)]
        )->first();
    } catch (\Throwable $e) {
        return null;
    }
}

/** Most recent open Container Flow record with this number, or null. */
function yardFindContainerFlowId($container_number) {
    $row = DB::getInstance()->query(
        "SELECT id FROM containers WHERE container_number = ? AND archived_at IS NULL ORDER BY id DESC LIMIT 1",
        [$container_number]
    )->first();
    return $row ? (int) $row->id : null;
}

/** Match free-text account to a client (case-insensitive name). Returns the row or null. */
function yardMatchCustomer($account) {
    $account = trim((string) $account);
    if ($account === '') return null;
    return DB::getInstance()->query("SELECT id, name FROM customers WHERE UPPER(name) = UPPER(?) LIMIT 1", [$account])->first();
}

function logYardEvent($unit, $user_id, $action, $from_code = null, $to_code = null, $details = '') {
    DB::getInstance()->insert('yard_events', [
        'unit_id'          => !empty($unit->id) ? (int) $unit->id : null,
        'warehouse_id'     => $unit ? ($unit->warehouse_id ?: null) : null,
        'user_id'          => $user_id ?: null,
        'action'           => $action,
        'container_number' => $unit ? $unit->container_number : null,
        'from_code'        => $from_code,
        'to_code'          => $to_code,
        'details'          => $details,
    ]);
}

/**
 * Cleans posted card fields. Returns [data, errors]. Only keys present in
 * $input are touched, so partial updates (e.g. just status) work.
 */
function yardCleanFields(array $input) {
    $data = [];
    $errors = [];
    if (array_key_exists('container_number', $input)) {
        $data['container_number'] = yardNormalizeNumber($input['container_number']);
        if ($data['container_number'] === '') $errors[] = 'Container number is required.';
        elseif (strlen($data['container_number']) > 50) $errors[] = 'Container number is too long.';
    }
    if (array_key_exists('status', $input)) {
        $status = ucfirst(strtolower(trim((string) $input['status'])));
        if (!in_array($status, YARD_STATUSES, true)) $errors[] = 'Unknown status.';
        $data['status'] = $status;
    }
    foreach (['account' => 100, 'driver' => 100, 'drayman' => 100] as $f => $max) {
        if (array_key_exists($f, $input)) {
            $v = trim((string) $input[$f]);
            $data[$f] = $v === '' ? null : mb_substr($v, 0, $max);
        }
    }
    if (array_key_exists('notes', $input)) {
        $v = trim((string) $input['notes']);
        $data['notes'] = $v === '' ? null : $v;
    }
    foreach (['date_in', 'mt_date', 'ld_date', 'lfd', 'eta'] as $f) {
        if (array_key_exists($f, $input)) $data[$f] = yardParseDate($input[$f]);
    }
    if (array_key_exists('hot', $input)) $data['hot'] = $input['hot'] ? 1 : 0;
    if (array_key_exists('account', $data)) {
        $customer = yardMatchCustomer($data['account']);
        $data['customer_id'] = $customer ? (int) $customer->id : null;
        if ($customer) $data['account'] = $customer->name;
    }
    if (isset($data['container_number']) && $data['container_number'] !== '') {
        $data['container_id'] = yardFindContainerFlowId($data['container_number']);
    }
    return [$data, $errors];
}

/**
 * Auto-dating is for live board edits only — an import must keep the
 * sheet's blanks blank rather than stamp today's date on old containers.
 */
function yardAutoDatesEnabled($set = null) {
    static $enabled = true;
    if ($set !== null) $enabled = (bool) $set;
    return $enabled;
}

/**
 * Fills in the dates the sheet used to rely on people typing: date in when
 * it lands on site, MT date when it goes Empty, LD date when it's Loaded.
 * Never overwrites a date someone entered.
 */
function yardAutoDates(array $data, $existing, $placing) {
    if ($placing && (array_key_exists('status', $data) ? $data['status'] : ($existing->status ?? null)) === 'Expected') {
        $data['status'] = 'Full'; // it's on site now
    }
    if (!yardAutoDatesEnabled()) return $data;
    $get = fn($k) => array_key_exists($k, $data) ? $data[$k] : ($existing->$k ?? null);
    $today = date('Y-m-d');
    $status = $get('status');
    if ($placing && !$get('date_in')) $data['date_in'] = $today;
    if ($status === 'Empty' && !$get('mt_date') && ($existing->status ?? '') !== 'Empty') $data['mt_date'] = $today;
    if ($status === 'Loaded' && !$get('ld_date') && ($existing->status ?? '') !== 'Loaded') $data['ld_date'] = $today;
    return $data;
}

/**
 * Create a unit. $location_id null = incoming/expected list.
 * Returns [unit|null, error|null].
 */
function createYardUnit($warehouse_id, array $data, $location_id, $user_id) {
    $db = DB::getInstance();
    $location = null;
    if ($location_id) {
        $location = getYardLocationById($location_id);
        if (!$location || (int) $location->warehouse_id !== (int) $warehouse_id) return [null, 'Unknown location.'];
        if (getYardUnitAtLocation($location->id)) return [null, $location->code . ' is already occupied.'];
    }
    $data += ['status' => $location ? 'Full' : 'Expected'];
    $data = yardAutoDates($data, null, (bool) $location);
    $data['warehouse_id'] = $warehouse_id ?: null;
    $data['location_id']  = $location ? (int) $location->id : null;
    $data['created_by']   = $user_id;
    $data['updated_by']   = $user_id;
    if (!yardTryWrite(fn($db) => $db->insert('yard_units', $data))) {
        return [null, $location ? $location->code . ' was just taken by someone else.' : 'Could not save.'];
    }
    $unit = getYardUnitById($db->lastId());
    logYardEvent($unit, $user_id, $location ? 'placed' : 'expected', null, $location->code ?? null);
    return [$unit, null];
}

function updateYardUnit($unit, array $data, $user_id) {
    $data = yardAutoDates($data, $unit, false);
    if ($unit->location_id && ($data['status'] ?? '') === 'Expected') $data['status'] = $unit->status;
    $changes = [];
    foreach ($data as $k => $v) {
        if ((string) ($unit->$k ?? '') !== (string) ($v ?? '') && !in_array($k, ['container_id', 'customer_id'], true)) {
            $changes[] = $k . ': ' . ($unit->$k ?? '—') . ' → ' . ($v ?? '—');
        }
    }
    if (empty($changes)) return getYardUnitById($unit->id);
    $data['updated_by'] = $user_id;
    DB::getInstance()->update('yard_units', $unit->id, $data);
    $fresh = getYardUnitById($unit->id);
    logYardEvent($fresh, $user_id, 'updated', null, null, implode('; ', $changes));
    return $fresh;
}

/**
 * Move a unit to a location (or null = back to the incoming list).
 * If the target is occupied and $swap is set, the two trade places.
 * Returns error string or null.
 */
function moveYardUnit($unit, $to_location_id, $user_id, $swap = false) {
    $db = DB::getInstance();
    $from = getYardLocationById($unit->location_id);
    $from_code = $from->code ?? null;

    if (!$to_location_id) {
        if (!$from) return null;
        $db->update('yard_units', $unit->id, ['location_id' => null, 'last_location_code' => $from_code, 'updated_by' => $user_id]);
        logYardEvent($unit, $user_id, 'moved', $from_code, 'INCOMING');
        return null;
    }

    $to = getYardLocationById($to_location_id);
    if (!$to || (int) $to->warehouse_id !== (int) $unit->warehouse_id) return 'Unknown location.';
    if ($from && (int) $from->id === (int) $to->id) return null;

    $occupant = getYardUnitAtLocation($to->id);
    if ($occupant && !$swap) return $to->code . ' is occupied by ' . $occupant->container_number . '.';
    if ($occupant && !$from) return $to->code . ' is occupied — move ' . $occupant->container_number . ' out first.';

    $placing = !$from;
    if ($occupant) {
        // Unique index on location_id: park the occupant first.
        $db->update('yard_units', $occupant->id, ['location_id' => null]);
    }
    $update = yardAutoDates(['location_id' => (int) $to->id, 'updated_by' => $user_id], $unit, $placing);
    if (!yardTryWrite(fn($db) => $db->update('yard_units', $unit->id, $update))) {
        if ($occupant) $db->update('yard_units', $occupant->id, ['location_id' => (int) $to->id]);
        return $to->code . ' was just taken by someone else.';
    }
    if ($occupant) {
        $db->update('yard_units', $occupant->id, ['location_id' => (int) $from->id, 'last_location_code' => $to->code, 'updated_by' => $user_id]);
        logYardEvent($occupant, $user_id, 'swapped', $to->code, $from->code);
    }
    $db->update('yard_units', $unit->id, ['last_location_code' => $from_code]);
    logYardEvent($unit, $user_id, $occupant ? 'swapped' : ($placing ? 'placed' : 'moved'), $from_code ?: 'INCOMING', $to->code);
    return null;
}

function pickUpYardUnit($unit, $user_id) {
    $from = getYardLocationById($unit->location_id);
    DB::getInstance()->update('yard_units', $unit->id, [
        'location_id'        => null,
        'last_location_code' => $from->code ?? $unit->last_location_code,
        'picked_up_at'       => date('Y-m-d H:i:s'),
        'picked_up_by'       => $user_id,
        'updated_by'         => $user_id,
    ]);
    logYardEvent($unit, $user_id, 'picked_up', $from->code ?? null, null);
}

/** Undo a pickup. Goes back to its old spot if still free, else Incoming. */
function restoreYardUnit($unit, $user_id) {
    $loc = $unit->last_location_code ? findYardLocationByCode($unit->warehouse_id, $unit->last_location_code) : null;
    $location_id = ($loc && !getYardUnitAtLocation($loc->id)) ? (int) $loc->id : null;
    DB::getInstance()->update('yard_units', $unit->id, [
        'picked_up_at' => null,
        'picked_up_by' => null,
        'location_id'  => $location_id,
        'updated_by'   => $user_id,
    ]);
    logYardEvent($unit, $user_id, 'restored', null, $location_id ? $loc->code : 'INCOMING');
}

/**
 * Yard check tick. Leaves updated_at alone so it doesn't look like an edit
 * to someone with the card open; the event row is what tells other boards.
 */
function checkYardUnit($unit, $user_id) {
    DB::getInstance()->query(
        "UPDATE yard_units SET checked_at = NOW(), checked_by = ?, updated_at = updated_at WHERE id = ?",
        [$user_id, (int) $unit->id]
    );
    logYardEvent($unit, $user_id, 'checked', null, null);
}

// ── Board payload ───────────────────────────────────────────────────────────

/** Bumps whenever anything on this warehouse's board changes. */
function getYardVersion($warehouse_id) {
    $params = [];
    $where = yardWarehouseWhere('warehouse_id', $warehouse_id, $params);
    $db = DB::getInstance();
    $e = $db->query("SELECT MAX(id) AS m FROM yard_events WHERE {$where}", $params)->first();
    $params = [];
    $where = yardWarehouseWhere('warehouse_id', $warehouse_id, $params);
    $u = $db->query("SELECT MAX(updated_at) AS m, COUNT(*) AS c FROM yard_units WHERE {$where}", $params)->first();
    $params = [];
    $where = yardWarehouseWhere('warehouse_id', $warehouse_id, $params);
    $l = $db->query("SELECT COUNT(*) AS c, SUM(active) AS a, MAX(id) AS m FROM yard_locations WHERE {$where}", $params)->first();
    return md5(implode('|', [$e->m ?? 0, $u->m ?? '', $u->c ?? 0, $l->c ?? 0, $l->a ?? 0, $l->m ?? 0, date('Y-m-d')]));
}

function yardUserNames(array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = DB::getInstance()->query("SELECT id, fname, lname FROM users WHERE id IN ({$ph})", $ids)->results() ?: [];
    $out = [];
    foreach ($rows as $r) $out[(int) $r->id] = trim($r->fname . ' ' . substr((string) $r->lname, 0, 1));
    return $out;
}

/** Shapes one unit row for the board's JS. */
function yardUnitToArray($u, $names, $today) {
    $lfd_state = '';
    if ($u->lfd && !$u->picked_up_at) {
        if ($u->lfd < $today) $lfd_state = 'overdue';
        elseif ($u->lfd <= date('Y-m-d', strtotime($today . ' +1 day'))) $lfd_state = 'soon';
    }
    $days = $u->date_in ? (int) floor((strtotime($today) - strtotime($u->date_in)) / 86400) : null;
    $account = $u->account ?: ($u->customer_name ?? '');
    return [
        'id'               => (int) $u->id,
        'location_id'      => $u->location_id ? (int) $u->location_id : null,
        'container_number' => $u->container_number,
        'status'           => $u->status,
        'hot'              => (bool) $u->hot,
        'account'          => $account,
        'customer_id'      => $u->customer_id ? (int) $u->customer_id : null,
        'color'            => $u->yard_color ?: yardFallbackColor($account),
        'driver'           => $u->driver,
        'drayman'          => $u->drayman,
        'notes'            => $u->notes,
        'date_in'          => $u->date_in,
        'mt_date'          => $u->mt_date,
        'ld_date'          => $u->ld_date,
        'lfd'              => $u->lfd,
        'lfd_state'        => $lfd_state,
        'eta'              => $u->eta,
        'days_in'          => $days,
        'checked_today'    => $u->checked_at && substr($u->checked_at, 0, 10) === $today,
        'cf'               => $u->cf_id ? ['id' => (int) $u->cf_id, 'status' => $u->cf_status, 'type' => $u->cf_type] : null,
        'updated_at'       => $u->updated_at,
        'updated_by'       => $names[(int) $u->updated_by] ?? '',
    ];
}

function getYardBoard($warehouse_id) {
    ensureYardTables();
    $db = DB::getInstance();
    $today = date('Y-m-d');

    $locations = array_map(fn($l) => [
        'id' => (int) $l->id, 'code' => $l->code, 'kind' => $l->kind,
    ], getYardLocations($warehouse_id));

    $params = [];
    $where = yardWarehouseWhere('yu.warehouse_id', $warehouse_id, $params);
    $units = $db->query(
        "SELECT yu.*, cu.name AS customer_name, cu.yard_color,
                c.id AS cf_id, c.status AS cf_status, c.type AS cf_type
         FROM yard_units yu
         LEFT JOIN customers cu ON cu.id = yu.customer_id
         LEFT JOIN containers c ON c.id = COALESCE(yu.container_id, (
             SELECT c2.id FROM containers c2
             WHERE c2.container_number = yu.container_number AND c2.archived_at IS NULL
             ORDER BY c2.id DESC LIMIT 1))
         WHERE {$where} AND yu.picked_up_at IS NULL
         ORDER BY yu.hot DESC, yu.eta IS NULL, yu.eta ASC, yu.created_at ASC",
        $params
    )->results() ?: [];

    $names = yardUserNames(array_map(fn($u) => $u->updated_by, $units));
    $placed = [];
    $incoming = [];
    foreach ($units as $u) {
        $row = yardUnitToArray($u, $names, $today);
        if ($u->location_id) $placed[] = $row; else $incoming[] = $row;
    }

    return [
        'version'   => getYardVersion($warehouse_id),
        'today'     => $today,
        'locations' => $locations,
        'units'     => $placed,
        'incoming'  => $incoming,
    ];
}

/** Account → colour map for the legend and the settings page. */
function getYardCustomerColors() {
    ensureYardTables();
    $rows = DB::getInstance()->query("SELECT id, name, yard_color FROM customers ORDER BY name")->results() ?: [];
    foreach ($rows as $r) $r->effective_color = $r->yard_color ?: yardFallbackColor($r->name);
    return $rows;
}

// ── CSV import (Google Sheet "TODAY" tab → File → Download → CSV) ───────────

/**
 * Imports the board from the T-Card sheet's CSV export. Recognises the
 * main block (DR, CONTAINER, STATUS, DATE IN, MT DATE, LD DATE, DRIVER,
 * ACCOUNT, LFD, Drayman, DC NOTES) and the incoming block to its right
 * (Container, Customer, STATUS, ETA). Missing door/yard codes are created.
 * Rows whose spot already holds a different container are skipped and
 * reported, never overwritten. $dry_run reports without writing.
 */
function importYardCsv($path, $warehouse_id, $user_id, $dry_run = true) {
    ensureYardTables();
    $report = ['placed' => 0, 'incoming' => 0, 'locations' => 0, 'skipped' => [], 'lines' => []];
    $fh = fopen($path, 'r');
    if (!$fh) return $report + ['error' => 'Could not read the uploaded file.'];

    $header = null;
    $col = [];
    $inc = [];
    $main_rows = [];
    $incoming_rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if ($header === null) {
            $norm = array_map(fn($h) => strtoupper(trim((string) $h)), $row);
            if (!in_array('CONTAINER', $norm, true) || !in_array('STATUS', $norm, true)) continue;
            $header = $norm;
            foreach ($norm as $i => $h) {
                $key = ['DR' => 'loc', 'DOOR' => 'loc', 'LOCATION' => 'loc', 'CONTAINER' => 'container_number',
                        'STATUS' => 'status', 'DATE IN' => 'date_in', 'MT DATE' => 'mt_date', 'LD DATE' => 'ld_date',
                        'DRIVER' => 'driver', 'ACCOUNT' => 'account', 'LFD' => 'lfd', 'DRAYMAN' => 'drayman',
                        'DC NOTES' => 'notes', 'NOTES' => 'notes', 'CUSTOMER' => 'customer', 'ETA' => 'eta'][$h] ?? null;
                if (!$key) continue;
                if (!isset($col[$key])) $col[$key] = $i;
                elseif (in_array($key, ['container_number', 'status'], true) && !isset($inc[$key])) $inc[$key] = $i;
            }
            if (isset($col['customer'])) $inc['account'] = $col['customer'];
            if (isset($col['eta'])) $inc['eta'] = $col['eta'];
            if (!isset($col['loc'])) $col['loc'] = 0;
            continue;
        }
        $cell = fn($i) => ($i !== null && isset($row[$i])) ? trim((string) $row[$i]) : '';

        $code = strtoupper($cell($col['loc']));
        if ($code !== '' && preg_match('/^[A-Z]{1,4}\s*-?\d{1,4}$/', $code)) {
            $fields = ['code' => $code];
            foreach (['container_number', 'status', 'date_in', 'mt_date', 'ld_date', 'driver', 'account', 'lfd', 'drayman', 'notes'] as $k) {
                $fields[$k] = $cell($col[$k] ?? null);
            }
            $main_rows[] = $fields;
        }
        if (isset($inc['container_number']) && $cell($inc['container_number']) !== '') {
            $incoming_rows[] = [
                'container_number' => $cell($inc['container_number']),
                'account'          => $cell($inc['account'] ?? null),
                'eta'              => $cell($inc['eta'] ?? null),
                'label'            => $cell($inc['status'] ?? null),
            ];
        }
    }
    fclose($fh);
    if ($header === null) return $report + ['error' => 'No header row with CONTAINER and STATUS columns was found.'];

    $db = DB::getInstance();
    yardAutoDatesEnabled(false);
    $find_open = function ($number) use ($db, $warehouse_id) {
        $params = [$number];
        $where = yardWarehouseWhere('warehouse_id', $warehouse_id, $params);
        return $db->query("SELECT * FROM yard_units WHERE container_number = ? AND {$where} AND picked_up_at IS NULL
                           ORDER BY location_id IS NULL LIMIT 1", $params)->first();
    };

    // Main block: one door/yard spot per row.
    $on_board = [];
    foreach ($main_rows as $fields) {
        $code = $fields['code'];
        unset($fields['code']);
        $loc = findYardLocationByCode($warehouse_id, $code);
        if (!$loc) {
            $report['locations']++;
            if (!$dry_run) $loc = getYardLocationById(createYardLocation($warehouse_id, $code));
        }
        $number = yardNormalizeNumber($fields['container_number']);
        if ($number === '') continue;
        $on_board[$number] = true;
        $status = ucfirst(strtolower($fields['status']));
        $fields['status'] = in_array($status, YARD_STATUSES, true) && $status !== 'Expected' ? $status : 'Full';
        $fields['hot'] = stripos($fields['driver'] . ' ' . $fields['notes'], 'HOT') !== false ? 1 : 0;

        $occupant = $loc ? getYardUnitAtLocation($loc->id) : null;
        if ($occupant && $occupant->container_number === $number) continue; // already on the board
        if ($occupant) {
            $report['skipped'][] = "{$code}: board has {$occupant->container_number}, sheet has {$number}";
            continue;
        }
        [$data, $errors] = yardCleanFields($fields);
        if ($errors) {
            $report['skipped'][] = "{$code} {$number}: " . implode(' ', $errors);
            continue;
        }
        $existing = $find_open($number);
        if ($existing && $existing->location_id) {
            $report['skipped'][] = "{$code} {$number}: already on the board at another spot";
            continue;
        }
        $report['placed']++;
        $report['lines'][] = "{$code} ← {$number} ({$data['status']})" . ($existing ? ' — from Incoming' : '');
        if ($dry_run) continue;
        if ($existing) {
            $existing = updateYardUnit($existing, $data, $user_id);
            $err = moveYardUnit($existing, $loc->id, $user_id);
        } else {
            [, $err] = createYardUnit($warehouse_id, $data, $loc->id, $user_id);
        }
        if ($err) $report['skipped'][] = "{$code} {$number}: {$err}";
    }

    // Incoming block: expected containers not yet on site.
    foreach ($incoming_rows as $r) {
        $number = yardNormalizeNumber($r['container_number']);
        if ($number === '' || isset($on_board[$number]) || $find_open($number)) continue;
        $on_board[$number] = true;
        [$data] = yardCleanFields([
            'container_number' => $number,
            'account'          => $r['account'],
            'eta'              => $r['eta'],
            'notes'            => $r['label'],
            'hot'              => stripos($r['label'], 'HOT') !== false,
            'status'           => 'Expected',
        ]);
        $report['incoming']++;
        $report['lines'][] = "Incoming ← {$number}" . ($data['eta'] ? " (ETA {$data['eta']})" : '');
        if (!$dry_run) createYardUnit($warehouse_id, $data, null, $user_id);
    }

    yardAutoDatesEnabled(true);
    if (!$dry_run) logYardEvent((object) ['id' => null, 'warehouse_id' => $warehouse_id, 'container_number' => null], $user_id, 'import', null, null,
        "{$report['placed']} placed, {$report['incoming']} incoming, {$report['locations']} new locations");
    return $report;
}
