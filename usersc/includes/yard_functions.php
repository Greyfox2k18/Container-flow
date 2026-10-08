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
 *   - CUSTOMER COLORS  → customers.yard_color (Container Flow's clients)
 *
 * The Yard Board is its own product that shares Container Flow's client
 * list: ACCOUNT is a Container Flow client (customer_id), and each client
 * can have a board colour. Yard containers are NOT linked to Container Flow
 * photo records and don't need one. When a photo record with the same
 * container number happens to exist, the board shows a marker linking to
 * it and container_view.php shows the yard spot; nothing is stored.
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
        on_list            TINYINT(1) NOT NULL DEFAULT 0,
        list_note          VARCHAR(100) NULL,
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

    $stints_existed = (bool) $db->query(
        "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'yard_stints'"
    )->first()->c;
    // One row per stay at a door or yard spot: when it went in, when it came
    // out, and who moved it. Door rows are the door in/out times.
    $db->query("CREATE TABLE IF NOT EXISTS yard_stints (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        unit_id          INT NOT NULL,
        warehouse_id     INT NULL,
        container_number VARCHAR(50) NOT NULL,
        account          VARCHAR(100) NULL,
        location_code    VARCHAR(20) NOT NULL,
        location_kind    ENUM('door','yard') NOT NULL DEFAULT 'yard',
        in_at            DATETIME NOT NULL,
        out_at           DATETIME NULL,
        in_by            INT NULL,
        out_by           INT NULL,
        in_estimated     TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_unit (unit_id),
        INDEX idx_wh_in (warehouse_id, in_at),
        INDEX idx_open (out_at)
    )");

    // ACCOUNT = a Container Flow client; the board colour lives on the client.
    yardAddColumnIfMissing('yard_units', 'customer_id', 'INT NULL');
    yardAddColumnIfMissing('customers', 'yard_color', 'VARCHAR(7) NULL');    // Incoming list (right-hand block of the sheet). A container stays on it
    // after it arrives, with LOC showing where it went, until someone clears it.
    yardAddColumnIfMissing('yard_units', 'on_list', 'TINYINT(1) NOT NULL DEFAULT 0');
    yardAddColumnIfMissing('yard_units', 'list_note', 'VARCHAR(100) NULL');
    // Gate in: the moment it first landed on site (picked_up_at is gate out).
    yardAddColumnIfMissing('yard_units', 'arrived_at', 'DATETIME NULL');

    if (!$stints_existed) {
        // Containers already on the board when tracking started: open a stay
        // for each, dated from DATE IN, flagged as an estimate.
        $db->query(
            "INSERT INTO yard_stints (unit_id, warehouse_id, container_number, account, location_code, location_kind, in_at, in_estimated)
             SELECT yu.id, yu.warehouse_id, yu.container_number, yu.account, yl.code, yl.kind,
                    COALESCE(yu.date_in, DATE(yu.created_at)), 1
             FROM yard_units yu JOIN yard_locations yl ON yl.id = yu.location_id
             WHERE yu.picked_up_at IS NULL"
        );
        $db->query(
            "UPDATE yard_units yu JOIN yard_stints s ON s.unit_id = yu.id
             SET yu.arrived_at = s.in_at, yu.updated_at = yu.updated_at
             WHERE yu.arrived_at IS NULL"
        );
    }
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
        $has_year = isset($m[3]) && $m[3] !== '';
        $y = $has_year ? (int) $m[3] : (int) date('Y');
        if ($y < 100) $y += 2000;
        if (!checkdate((int) $m[1], (int) $m[2], $y)) return null;
        $date = sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]);
        // "1/3" typed in December means next January, "12/30" typed in January last December.
        if (!$has_year) {
            if ($date < date('Y-m-d', strtotime('-6 months'))) $date = sprintf('%04d-%02d-%02d', $y + 1, $m[1], $m[2]);
            elseif ($date > date('Y-m-d', strtotime('+6 months'))) $date = sprintf('%04d-%02d-%02d', $y - 1, $m[1], $m[2]);
        }
        return $date;
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
        // Read-only: viewing a photo record never sets up the yard tables.
        return DB::getInstance()->query(
            "SELECT yu.*, yl.code AS location_code, yl.kind AS location_kind
             FROM yard_units yu LEFT JOIN yard_locations yl ON yl.id = yu.location_id
             WHERE yu.picked_up_at IS NULL AND yu.container_number = ?
             ORDER BY yu.location_id IS NULL, yu.updated_at DESC LIMIT 1",
            [yardNormalizeNumber($container->container_number)]
        )->first();
    } catch (\Throwable $e) {
        return null;
    }
}

/** The Container Flow client with this name (case-insensitive), or null. */
function yardFindCustomerByName($name) {
    $name = trim((string) $name);
    if ($name === '') return null;
    return DB::getInstance()->query("SELECT id, name FROM customers WHERE name = ? LIMIT 1", [$name])->first();
}

/**
 * Container Flow (photos) lives on the same site but is optional for the
 * yard: everything that reads its tables checks this first.
 */
function yardHasContainerFlow() {
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool) DB::getInstance()->query(
                "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'containers'"
            )->first()->c;
        } catch (\Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** Timestamps come from PHP so events, stays and pickups share one clock. */
function yardNow() {
    return date('Y-m-d H:i:s');
}

function logYardEvent($unit, $user_id, $action, $from_code = null, $to_code = null, $details = '') {
    DB::getInstance()->insert('yard_events', [
        'created_at'       => yardNow(),
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
    foreach (['account' => 100, 'driver' => 100, 'drayman' => 100, 'list_note' => 100] as $f => $max) {
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
    if (array_key_exists('on_list', $input)) $data['on_list'] = $input['on_list'] ? 1 : 0;
    // ACCOUNT: pick a client by id (the board's dropdown) or by name (import).
    // The client's name is copied to `account` so history reads well; a name
    // that isn't a client (from an old sheet) is kept as plain text.
    if (array_key_exists('customer_id', $input)) {
        $cid = (int) $input['customer_id'];
        $customer = $cid ? getCustomerById($cid) : null;
        if ($cid && !$customer) $errors[] = 'That client no longer exists.';
        $data['customer_id'] = $customer ? (int) $customer->id : null;
        $data['account'] = $customer ? $customer->name : null;
    } elseif (array_key_exists('account', $data)) {
        $customer = yardFindCustomerByName($data['account']);
        $data['customer_id'] = $customer ? (int) $customer->id : null;
        if ($customer) $data['account'] = $customer->name;
    }
    return [$data, $errors];
}

/**
 * HOT is whatever the sheet says: "HOT" anywhere in driver, DC notes or the
 * incoming STATUS turns the container's cells yellow.
 */
function yardApplyHot(array $data, $existing) {
    $text = '';
    foreach (['driver', 'notes', 'list_note'] as $f) {
        $text .= ' ' . (array_key_exists($f, $data) ? $data[$f] : ($existing->$f ?? ''));
    }
    $data['hot'] = preg_match('/\bHOT\b/i', $text) ? 1 : 0;
    return $data;
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
    $data += ['status' => $location ? 'Full' : 'Expected', 'on_list' => $location ? 0 : 1];
    $data = yardAutoDates($data, null, (bool) $location);
    $data = yardApplyHot($data, null);
    $data['warehouse_id'] = $warehouse_id ?: null;
    $data['location_id']  = $location ? (int) $location->id : null;
    $data['created_by']   = $user_id;
    $data['updated_by']   = $user_id;
    if (!yardTryWrite(fn($db) => $db->insert('yard_units', $data))) {
        return [null, $location ? $location->code . ' was just taken by someone else.' : 'Could not save.'];
    }
    $unit = getYardUnitById($db->lastId());
    if ($location) yardOpenStint($unit, $location, $user_id);
    logYardEvent($unit, $user_id, $location ? 'placed' : 'expected', null, $location->code ?? null);
    return [$unit, null];
}

function updateYardUnit($unit, array $data, $user_id) {
    $data = yardAutoDates($data, $unit, false);
    $data = yardApplyHot($data, $unit);
    if ($unit->location_id && ($data['status'] ?? '') === 'Expected') $data['status'] = $unit->status;
    $changes = [];
    foreach ($data as $k => $v) {
        if ((string) ($unit->$k ?? '') !== (string) ($v ?? '') && !in_array($k, ['hot', 'container_number', 'customer_id'], true)) {
            $changes[] = yardFieldLabel($k) . ': ' . yardShowValue($k, $unit->$k ?? null) . ' → ' . yardShowValue($k, $v);
        }
    }
    $renamed = isset($data['container_number']) && $data['container_number'] !== $unit->container_number;
    if (empty($changes) && !$renamed && (int) ($data['hot'] ?? $unit->hot) === (int) $unit->hot) return getYardUnitById($unit->id);
    $data['updated_by'] = $user_id;
    DB::getInstance()->update('yard_units', $unit->id, $data);
    $fresh = getYardUnitById($unit->id);
    if ($renamed) {
        // Keep the stays findable under the corrected number.
        DB::getInstance()->query("UPDATE yard_stints SET container_number = ? WHERE unit_id = ?", [$fresh->container_number, (int) $unit->id]);
        logYardEvent($fresh, $user_id, 'renamed', null, null, $unit->container_number . ' → ' . $fresh->container_number);
    }
    if ($changes) logYardEvent($fresh, $user_id, 'updated', null, null, implode('; ', $changes));
    return $fresh;
}

/** Column names as the sheet shows them, for the change log. */
function yardFieldLabel($field) {
    return [
        'status' => 'STATUS', 'date_in' => 'DATE IN', 'mt_date' => 'MT DATE', 'ld_date' => 'LD DATE',
        'driver' => 'DRIVER', 'account' => 'ACCOUNT', 'lfd' => 'LFD', 'drayman' => 'Drayman', 'notes' => 'DC NOTES',
        'eta' => 'ETA', 'list_note' => 'Incoming STATUS', 'on_list' => 'On incoming list',
    ][$field] ?? $field;
}

function yardShowValue($field, $value) {
    if ($value === null || $value === '') return '(blank)';
    if (in_array($field, ['date_in', 'mt_date', 'ld_date', 'lfd', 'eta'], true)) return date('n/j', strtotime($value));
    if ($field === 'on_list') return $value ? 'yes' : 'no';
    return (string) $value;
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
        $db->update('yard_units', $unit->id, ['location_id' => null, 'on_list' => 1, 'last_location_code' => $from_code, 'updated_by' => $user_id]);
        yardCloseStint($unit->id, $user_id);
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
        yardCloseStint($occupant->id, $user_id);
        yardOpenStint(getYardUnitById($occupant->id), $from, $user_id);
        logYardEvent($occupant, $user_id, 'swapped', $to->code, $from->code);
    }
    $db->update('yard_units', $unit->id, ['last_location_code' => $from_code]);
    yardCloseStint($unit->id, $user_id);
    yardOpenStint(getYardUnitById($unit->id), $to, $user_id);
    logYardEvent($unit, $user_id, $occupant ? 'swapped' : ($placing ? 'placed' : 'moved'), $from_code ?: 'INCOMING', $to->code);
    return null;
}

/**
 * Opens a stay at $location for $unit. During an import the real arrival
 * time isn't known, so the stay starts at DATE IN and is marked estimated.
 */
function yardOpenStint($unit, $location, $user_id) {
    $estimated = !yardAutoDatesEnabled();
    $in_at = $estimated ? (($unit->date_in ?? null) ?: date('Y-m-d')) . ' 00:00:00' : yardNow();
    DB::getInstance()->insert('yard_stints', [
        'unit_id'          => (int) $unit->id,
        'warehouse_id'     => $unit->warehouse_id ?: null,
        'container_number' => $unit->container_number,
        'account'          => $unit->account ?? null,
        'location_code'    => $location->code,
        'location_kind'    => $location->kind,
        'in_at'            => $in_at,
        'in_by'            => $user_id ?: null,
        'in_estimated'     => $estimated ? 1 : 0,
    ]);
    if (empty($unit->arrived_at)) {
        DB::getInstance()->query("UPDATE yard_units SET arrived_at = ?, updated_at = updated_at WHERE id = ? AND arrived_at IS NULL",
            [$in_at, (int) $unit->id]);
    }
}

/** Closes whatever stay is open for this unit (it left its spot). */
function yardCloseStint($unit_id, $user_id) {
    DB::getInstance()->query(
        "UPDATE yard_stints SET out_at = ?, out_by = ? WHERE unit_id = ? AND out_at IS NULL",
        [yardNow(), $user_id ?: null, (int) $unit_id]
    );
}

function pickUpYardUnit($unit, $user_id) {
    yardCloseStint($unit->id, $user_id);
    $from = getYardLocationById($unit->location_id);
    DB::getInstance()->update('yard_units', $unit->id, [
        'location_id'        => null,
        'last_location_code' => $from->code ?? $unit->last_location_code,
        'picked_up_at'       => yardNow(),
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
    if ($location_id) yardOpenStint(getYardUnitById($unit->id), $loc, $user_id);
    logYardEvent($unit, $user_id, 'restored', null, $location_id ? $loc->code : 'INCOMING');
}

/**
 * Yard check tick. Leaves updated_at alone so it doesn't look like an edit
 * to someone with the card open; the event row is what tells other boards.
 */
function checkYardUnit($unit, $user_id) {
    DB::getInstance()->query(
        "UPDATE yard_units SET checked_at = ?, checked_by = ?, updated_at = updated_at WHERE id = ?",
        [yardNow(), $user_id, (int) $unit->id]
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
    $account = (string) ($u->customer_name ?? $u->account);
    return [
        'id'               => (int) $u->id,
        'location_id'      => $u->location_id ? (int) $u->location_id : null,
        'container_number' => $u->container_number,
        'status'           => $u->status,
        'hot'              => (bool) $u->hot,
        'account'          => $account,
        'customer_id'      => $u->customer_id ? (int) $u->customer_id : null,
        'color'            => $u->account_color ?: yardFallbackColor($account),
        'driver'           => $u->driver,
        'drayman'          => $u->drayman,
        'notes'            => $u->notes,
        'date_in'          => $u->date_in,
        'mt_date'          => $u->mt_date,
        'ld_date'          => $u->ld_date,
        'lfd'              => $u->lfd,
        'lfd_state'        => $lfd_state,
        'eta'              => $u->eta,
        'on_list'          => (bool) $u->on_list,
        'list_note'        => $u->list_note,
        'location_code'    => $u->location_code,
        'arrived_at'       => $u->arrived_at,
        'spot_since'       => $u->spot_since,
        'spot_estimated'   => (bool) $u->spot_estimated,
        'days_in'          => $days,
        'checked_today'    => $u->checked_at && substr($u->checked_at, 0, 10) === $today,
        'cf'               => !empty($u->cf_id) ? ['id' => (int) $u->cf_id, 'status' => $u->cf_status, 'type' => $u->cf_type] : null,
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
    // Photo record link: only when a Container Flow record has this number.
    $cf_select = $cf_join = '';
    if (yardHasContainerFlow()) {
        $cf_select = ", c.id AS cf_id, c.status AS cf_status, c.type AS cf_type";
        $cf_join = "LEFT JOIN containers c ON c.id = (
             SELECT c2.id FROM containers c2
             WHERE c2.container_number = yu.container_number AND c2.archived_at IS NULL
             ORDER BY c2.id DESC LIMIT 1)";
    }
    $units = $db->query(
        "SELECT yu.*, cu.name AS customer_name, cu.yard_color AS account_color, yl.code AS location_code,
                st.in_at AS spot_since, st.in_estimated AS spot_estimated {$cf_select}
         FROM yard_units yu
         LEFT JOIN customers cu ON cu.id = yu.customer_id
         LEFT JOIN yard_locations yl ON yl.id = yu.location_id
         LEFT JOIN yard_stints st ON st.unit_id = yu.id AND st.out_at IS NULL
         {$cf_join}
         WHERE {$where} AND yu.picked_up_at IS NULL
         ORDER BY yu.eta IS NULL, yu.eta ASC, yu.id ASC",
        $params
    )->results() ?: [];

    $names = yardUserNames(array_map(fn($u) => $u->updated_by, $units));
    $placed = [];
    $incoming = [];
    foreach ($units as $u) {
        $row = yardUnitToArray($u, $names, $today);
        if ($u->location_id) $placed[] = $row;
        if (!$u->location_id || $u->on_list) $incoming[] = $row;
    }

    return [
        'version'   => getYardVersion($warehouse_id),
        'today'     => $today,
        'locations' => $locations,
        'units'     => $placed,
        'incoming'  => $incoming,
    ];
}

/**
 * Everything recorded for one container: gate in/out, each stay at a door
 * or yard spot with in/out times, and every edit with who and when.
 */
function getYardUnitTimeline($unit) {
    $db = DB::getInstance();
    $stints = $db->query(
        "SELECT s.*, ui.fname AS in_fname, ui.lname AS in_lname, uo.fname AS out_fname, uo.lname AS out_lname
         FROM yard_stints s
         LEFT JOIN users ui ON ui.id = s.in_by
         LEFT JOIN users uo ON uo.id = s.out_by
         WHERE s.unit_id = ? ORDER BY s.in_at ASC, s.id ASC",
        [(int) $unit->id]
    )->results() ?: [];
    $events = $db->query(
        "SELECT e.action, e.from_code, e.to_code, e.details, e.created_at, u.fname, u.lname
         FROM yard_events e LEFT JOIN users u ON u.id = e.user_id
         WHERE e.unit_id = ? AND e.action != 'checked' ORDER BY e.id DESC LIMIT 200",
        [(int) $unit->id]
    )->results() ?: [];
    $name = fn($f, $l) => trim(($f ?? '') . ' ' . substr((string) ($l ?? ''), 0, 1));
    $location = getYardLocationById($unit->location_id);
    return [
        'container_number' => $unit->container_number,
        'location'         => $location->code ?? null,
        'date_in'          => $unit->date_in,
        'arrived_at'       => $unit->arrived_at,
        'picked_up_at'     => $unit->picked_up_at,
        'now'              => yardNow(),
        'stints'           => array_map(fn($s) => [
            'code'      => $s->location_code,
            'kind'      => $s->location_kind,
            'in_at'     => $s->in_at,
            'out_at'    => $s->out_at,
            'estimated' => (bool) $s->in_estimated,
            'in_by'     => $name($s->in_fname, $s->in_lname),
            'out_by'    => $name($s->out_fname, $s->out_lname),
        ], $stints),
        'events'           => array_map(fn($r) => [
            'action'  => $r->action,
            'from'    => $r->from_code,
            'to'      => $r->to_code,
            'details' => $r->details,
            'at'      => $r->created_at,
            'by'      => $name($r->fname, $r->lname),
        ], $events),
    ];
}

/**
 * Container Flow clients for the ACCOUNT dropdown (scoped to the user's
 * warehouses the same way Container Flow does), with board colours.
 */
function getYardCustomers($user_id = null) {
    ensureYardTables();
    $rows = $user_id ? getCustomersForUser($user_id) : getAllCustomers();
    foreach ($rows as $r) $r->effective_color = ($r->yard_color ?? null) ?: yardFallbackColor($r->name);
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
    $report = ['placed' => 0, 'incoming' => 0, 'locations' => 0, 'skipped' => [], 'lines' => [], 'unknown_accounts' => []];
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

    // Account names on the sheet that aren't Container Flow clients: imported
    // as plain text, listed in the preview so they can be added as clients first.
    foreach (array_merge(array_column($main_rows, 'account'), array_column($incoming_rows, 'account')) as $name) {
        $name = trim((string) $name);
        if ($name !== '' && !yardFindCustomerByName($name)) $report['unknown_accounts'][strtoupper($name)] = $name;
    }
    $report['unknown_accounts'] = array_values($report['unknown_accounts']);

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

    // Incoming block: expected containers, plus hot ones already on site.
    $listed = [];
    foreach ($incoming_rows as $r) {
        $number = yardNormalizeNumber($r['container_number']);
        if ($number === '' || isset($listed[$number])) continue;
        $listed[$number] = true;
        [$data] = yardCleanFields([
            'container_number' => $number,
            'account'          => $r['account'],
            'eta'              => $r['eta'],
            'list_note'        => $r['label'],
            'status'           => 'Expected',
        ]);
        $existing = $find_open($number);
        if ($existing || isset($on_board[$number])) {
            // Already in the yard: keep it on the incoming list with its LOC.
            $report['lines'][] = "Incoming list ← {$number} (already on site)";
            if (!$dry_run && $existing) {
                updateYardUnit($existing, ['on_list' => 1, 'list_note' => $data['list_note'], 'eta' => $data['eta']]
                    + ($existing->account ? [] : ['account' => $data['account'], 'customer_id' => $data['customer_id']]), $user_id);
            }
            continue;
        }
        $report['incoming']++;
        $report['lines'][] = "Incoming ← {$number}" . ($data['eta'] ? " (ETA {$data['eta']})" : '');
        if (!$dry_run) createYardUnit($warehouse_id, $data, null, $user_id);
    }

    yardAutoDatesEnabled(true);
    if (!$dry_run) logYardEvent((object) ['id' => null, 'warehouse_id' => $warehouse_id, 'container_number' => null], $user_id, 'import', null, null,
        "{$report['placed']} placed, {$report['incoming']} incoming, {$report['locations']} new locations");
    return $report;
}
