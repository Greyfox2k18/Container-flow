<?php
/**
 * SKU Lot / Expiration Scanner — Container Flow
 * ---------------------------------------------------------------
 * Helper functions used by usersc/sku_scan.php, usersc/ajax/sku_scan_ajax.php,
 * and usersc/sku_scan_export.php.
 *
 * Tables (created on first page load — see ensureSkuScanTables()):
 *
 *   sku_scan_sessions
 *     id, sku, container_id, customer_id, created_by, created_at, updated_at
 *     container_id/customer_id are NULL for standalone sessions (started
 *     from sku_scan.php with no container context).
 *
 *   sku_scan_lots
 *     id, session_id, lot_number, expiration_raw, expiration_date,
 *     quantity, created_by, scanned_at
 *
 * Also adds customers.sku_scan_enabled (see ensureCustomerScanColumn()) —
 * the per-client toggle checked on customer_edit.php and container_view.php.
 *
 * expiration_raw always stores whatever was scanned/typed, unmodified.
 * expiration_date stores the best-effort normalized Y-m-d value (or NULL
 * if it couldn't be parsed) so the export can give Excel a real date.
 * ---------------------------------------------------------------
 */

/**
 * Create the tables if they don't exist yet, and add any columns that
 * were introduced after the first release of this tool (container_id,
 * customer_id, customers.sku_scan_enabled) to installs that already have
 * the old tables. Safe to call on every page load — matches the pattern
 * used elsewhere in this app (see container_client_users.php).
 */
function ensureSkuScanTables()
{
    $db = DB::getInstance();

    $db->query("CREATE TABLE IF NOT EXISTS sku_scan_sessions (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        sku          VARCHAR(100) NOT NULL,
        container_id INT NULL,
        customer_id  INT NULL,
        created_by   INT NOT NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sku (sku),
        INDEX idx_container (container_id),
        INDEX idx_customer (customer_id)
    )");
    // Migration for installs from before container/customer linking existed.
    addColumnIfMissing('sku_scan_sessions', 'container_id', 'INT NULL');
    addColumnIfMissing('sku_scan_sessions', 'customer_id', 'INT NULL');

    $db->query("CREATE TABLE IF NOT EXISTS sku_scan_lots (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        session_id       INT NOT NULL,
        lot_number       VARCHAR(100) NOT NULL,
        expiration_raw   VARCHAR(50) NULL,
        expiration_date  DATE NULL,
        quantity         INT NOT NULL DEFAULT 1,
        created_by       INT NOT NULL,
        scanned_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_session (session_id),
        CONSTRAINT fk_scan_lots_session FOREIGN KEY (session_id)
            REFERENCES sku_scan_sessions(id) ON DELETE CASCADE
    )");

    ensureCustomerScanColumn();
}

/**
 * Add $column to $table if it isn't already there. Uses information_schema
 * instead of "ADD COLUMN IF NOT EXISTS" so it works on older MySQL/MariaDB
 * versions too.
 */
function addColumnIfMissing($table, $column, $definition)
{
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
 * Adds the "this client scans lots" toggle to the customers table. Called
 * from ensureSkuScanTables() and directly from customer_edit.php (which
 * needs the column to exist before it can read/save the checkbox).
 */
function ensureCustomerScanColumn()
{
    addColumnIfMissing('customers', 'sku_scan_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
}

/**
 * Start a new scan session for a SKU, optionally tied to a container.
 * When $container_id is given, the container's current customer_id is
 * copied onto the session so it stays correct even if the container's
 * customer assignment changes later. Returns the new session id.
 */
function createScanSession($sku, $user_id, $container_id = null)
{
    $db = DB::getInstance();

    $customer_id = null;
    if ($container_id) {
        $container = $db->query(
            "SELECT customer_id FROM containers WHERE id = ?",
            [$container_id]
        )->first();
        $customer_id = $container ? $container->customer_id : null;
    }

    $db->insert('sku_scan_sessions', [
        'sku'          => $sku,
        'container_id' => $container_id ?: null,
        'customer_id'  => $customer_id,
        'created_by'   => $user_id,
    ]);
    return (int) $db->lastId();
}

/**
 * Fetch a session row by id, or null.
 */
function getScanSession($session_id)
{
    $db = DB::getInstance();
    return $db->query(
        "SELECT * FROM sku_scan_sessions WHERE id = ?",
        [$session_id]
    )->first() ?: null;
}

/**
 * Save one scanned lot row against a session.
 * $expiration_date must already be normalized to Y-m-d or null — the
 * ajax endpoint validates that before calling this.
 */
function addScanLot($session_id, $lot_number, $expiration_raw, $expiration_date, $quantity, $user_id)
{
    $db = DB::getInstance();
    $db->insert('sku_scan_lots', [
        'session_id'      => $session_id,
        'lot_number'      => $lot_number,
        'expiration_raw'  => $expiration_raw,
        'expiration_date' => $expiration_date,
        'quantity'        => $quantity,
        'created_by'      => $user_id,
    ]);
    return getScanLotById((int) $db->lastId());
}

function getScanLotById($id)
{
    $db = DB::getInstance();
    return $db->query("SELECT * FROM sku_scan_lots WHERE id = ?", [$id])->first() ?: null;
}

/**
 * Remove a scanned row. Scoped to $session_id so one session can never
 * delete another session's rows even if an id gets guessed/tampered.
 */
function deleteScanLot($lot_id, $session_id)
{
    $db = DB::getInstance();
    $db->query(
        "DELETE FROM sku_scan_lots WHERE id = ? AND session_id = ?",
        [$lot_id, $session_id]
    );
    return true;
}

/**
 * All lots for a session, in the order they were scanned.
 */
function getScanLots($session_id)
{
    $db = DB::getInstance();
    return $db->query(
        "SELECT * FROM sku_scan_lots WHERE session_id = ? ORDER BY id ASC",
        [$session_id]
    )->results() ?: [];
}

/**
 * Every lot scanned for a container, across all its SKU sessions,
 * grouped by SKU (then scan order) for the combined container export.
 * Each row includes the session's sku.
 */
function getScanLotsByContainer($container_id)
{
    $db = DB::getInstance();
    return $db->query(
        "SELECT l.*, s.sku AS sku
         FROM sku_scan_lots l
         JOIN sku_scan_sessions s ON s.id = l.session_id
         WHERE s.container_id = ?
         ORDER BY s.sku ASC, l.id ASC",
        [$container_id]
    )->results() ?: [];
}

/**
 * Quick counts for the container_view.php sidebar panel: distinct SKUs,
 * total lots, total quantity. Returns an object with lot_count = 0 etc.
 * (never null) so callers can use it without an extra existence check.
 */
function getScanSummaryForContainer($container_id)
{
    $db = DB::getInstance();
    $row = $db->query(
        "SELECT COUNT(*) AS lot_count, COUNT(DISTINCT s.sku) AS sku_count, COALESCE(SUM(l.quantity), 0) AS qty_total
         FROM sku_scan_lots l
         JOIN sku_scan_sessions s ON s.id = l.session_id
         WHERE s.container_id = ?",
        [$container_id]
    )->first();
    return $row ?: (object) ['lot_count' => 0, 'sku_count' => 0, 'qty_total' => 0];
}
