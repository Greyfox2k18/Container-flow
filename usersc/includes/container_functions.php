<?php
// Container Tracking System Configuration
// This file should be placed in usersc/includes/ directory

if (!defined('CONTAINER_SITE_URL')) define('CONTAINER_SITE_URL', 'https://container-flow.com');

require_once __DIR__ . '/email_templates.php';
require_once __DIR__ . '/reports_functions.php';

function getContainerSetting($key, $default = null) {
    try {
        $row = DB::getInstance()->query("SELECT setting_value FROM container_settings WHERE setting_key = ? LIMIT 1",[$key])->first();
        if ($row && $row->setting_value !== null && $row->setting_value !== '') return $row->setting_value;
    } catch (\Throwable $e) {}
    return $default;
}
function setContainerSetting($key, $value) {
    DB::getInstance()->query("INSERT INTO container_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()",[$key,$value]);
}
function checkPointsReward($user_id, $pts) {
    try {
        $threshold = (int)getContainerSetting('points_reward_threshold',1000);
        if ($threshold <= 0) return false;
        $row = DB::getInstance()->query("SELECT plg_points FROM users WHERE id=?",[$user_id])->first();
        $current = $row ? (int)$row->plg_points : 0;
        if ($current >= $threshold) return ['threshold'=>$threshold,'review_url'=>getContainerSetting('points_review_url','')];
    } catch (\Throwable $e) {}
    return false;
}

// ── Carrier list ──────────────────────────────────────────────────────────────
function getCarrierList() {
    // Common freight/shipping carriers — users can type anything not in this list
    $predefined = [
        'BNSF Railway','COSCO Shipping','Crowley Maritime','Estes Express Lines',
        'Evergreen Line','FedEx Freight','Forward Air','Hapag-Lloyd',
        'J.B. Hunt Transport','Maersk','Matson Navigation','MSC',
        'Ocean Network Express (ONE)','Old Dominion Freight Line',
        'R+L Carriers','SAIA Motor Freight','Schneider National',
        'SEACOR Holdings','Saia Inc','Total Quality Logistics',
        'UPS Freight','Werner Enterprises','XPO Logistics',
        'Yang Ming','YRC Freight','ZIM Integrated Shipping',
    ];
    try {
        $used = DB::getInstance()->query(
            "SELECT DISTINCT carrier FROM containers WHERE carrier IS NOT NULL AND carrier != '' ORDER BY carrier"
        )->results() ?: [];
        $used_list = array_map(fn($r) => $r->carrier, $used);
        $merged = array_unique(array_merge($predefined, $used_list));
    } catch (\Throwable $e) {
        $merged = $predefined;
    }
    sort($merged);
    return $merged;
}

// ── Client portal token ───────────────────────────────────────────────────────
function getPortalSecret() {
    $secret = getContainerSetting('portal_secret','');
    if (!$secret) {
        $secret = bin2hex(random_bytes(32));
        setContainerSetting('portal_secret', $secret);
    }
    return $secret;
}
function generatePortalToken($container_id) {
    return substr(hash_hmac('sha256','portal:'.$container_id,getPortalSecret()),0,40);
}
function verifyPortalToken($container_id, $token) {
    return hash_equals(generatePortalToken($container_id), $token);
}
function getPortalUrl($container_id) {
    $base = rtrim(getContainerSetting('site_url', CONTAINER_SITE_URL),'/');
    return $base.'/usersc/container_portal.php?id='.$container_id.'&token='.generatePortalToken($container_id);
}

// Upload settings
define('UPLOAD_PATH', $abs_us_root.$us_url_root.'usersc/uploads/');
define('MAX_FILE_SIZE', 41943040); // 40MB in bytes
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif']);

// Photo types configuration
$photo_types = [
    'inbound' => [
        'inbound_seal' => 'Seal Photo',
        'inbound_closed' => 'Back of Container (Doors Closed)',
        'inbound_open' => 'Back of Container (Doors Open)',
        'inbound_empty' => 'Empty Container',
        'damage' => 'Damage Photo',
        'other' => 'Other'
    ],
    'outbound' => [
        'outbound_empty' => 'Empty Container',
        'outbound_loaded' => 'Loaded Container (Door Open)',
        'outbound_seal' => 'Seal Photo',
        'outbound_sealed_closed' => 'Back of Container (Sealed & Closed)',
        'damage' => 'Damage Photo',
        'other' => 'Other'
    ]
];

// Container statuses
$container_statuses = [
    'pending' => 'Pending',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
    'reviewed' => 'Reviewed'
];

/**
 * Returns the photo type config for a given customer and container type.
 * Falls back to the global $photo_types default if no client-specific config exists.
 *
 * @param int|null $customer_id
 * @param string   $container_type  'inbound' | 'outbound'
 * @return array   ['type_key' => 'Label', ...]
 */
// ── Missing-photos alerts ────────────────────────────────────────────────────
// Lets a supervisor ping a specific user (usually whoever created the
// container) about missing required photos. Built on the UserSpice
// "messaging" plugin's sendPlgMessage() — sent as an alert (type 1), which
// shows up in that plugin's badge/notification UI. This is a one-way
// alert, not a conversation.

/**
 * Required photo types for this container that don't have an uploaded
 * photo yet. Returns ['type_key' => 'Label', ...], same shape as getPhotoTypes().
 */
function getMissingPhotoTypes($container) {
    $required = getPhotoTypes($container->customer_id, $container->type);
    $existing = array_unique(array_map(fn($p) => $p->photo_type, getContainerPhotos($container->id)));

    $missing = [];
    foreach ($required as $key => $label) {
        if (!in_array($key, $existing, true)) {
            $missing[$key] = $label;
        }
    }
    return $missing;
}

/**
 * Sends a missing-photos alert via the Messages plugin. Returns
 * ['sent' => bool, 'reason' => string, 'missing' => array].
 * Fails gracefully (sent=false, clear reason) if the Messages plugin
 * isn't installed/enabled, rather than fataling.
 */
function notifyMissingPhotos($container_id, $recipient_user_id, $sender_user_id, $custom_message = '') {
    if (!function_exists('sendPlgMessage')) {
        return ['sent' => false, 'reason' => 'The UserSpice Messaging plugin isn\'t enabled — turn it on in the Plugin Manager first.', 'missing' => []];
    }

    $container = getContainerById($container_id);
    if (!$container) {
        return ['sent' => false, 'reason' => 'Container not found', 'missing' => []];
    }
    if (!$recipient_user_id) {
        return ['sent' => false, 'reason' => 'No recipient specified', 'missing' => []];
    }

    $missing = getMissingPhotoTypes($container);
    $missing_list = empty($missing) ? '(none flagged — sending as a general reminder)' : implode(', ', $missing);

    $subject = 'Missing photos — Container ' . $container->container_number;
    $body = 'Container ' . $container->container_number . ' is missing: ' . $missing_list . '.';
    if (trim((string) $custom_message) !== '') {
        $body .= "\n\n" . trim($custom_message);
    }
    $body .= "\n\nPlease upload as soon as possible.";

    sendPlgMessage((int) $recipient_user_id, $subject, $body, $sender_user_id, 1); // type 1 = alert
    logContainerActivity($container_id, $sender_user_id, 'missing_photos_alert', 'Notified user #' . $recipient_user_id . ' — missing: ' . $missing_list);

    return ['sent' => true, 'reason' => '', 'missing' => $missing];
}

function getPhotoTypes($customer_id = null, $container_type = 'inbound') {
    global $photo_types;
    $default = $photo_types[$container_type] ?? [];

    if (!$customer_id) return $default;

    try {
        $rows = DB::getInstance()->query(
            "SELECT photo_type_key, label FROM customer_photo_requirements
             WHERE customer_id = ? AND container_type = ?
             ORDER BY sort_order ASC",
            [(int)$customer_id, $container_type]
        )->results();

        if (empty($rows)) return $default;

        $result = [];
        foreach ($rows as $r) {
            $result[$r->photo_type_key] = $r->label;
        }
        return $result;
    } catch (\Throwable $e) {
        return $default;
    }
}

// Helper functions
function isSupervisor($user_id = null) {
    global $user;
    if ($user_id === null) {
        $user_id = $user->data()->id;
    }
    try {
        $perm_rows      = fetchPermissionUsers(3); // perm 3 = supervisor in this install
        $supervisor_ids = array_map('intval', array_column((array)$perm_rows, 'user_id'));
        return in_array((int)$user_id, $supervisor_ids);
    } catch (\Throwable $e) {
        return hasPerm([10], $user_id); // fallback
    }
}

function isFloorWorker($user_id = null) {
    global $user;
    if ($user_id === null) { return $user->isLoggedIn(); }
    return hasPerm([10, 11], $user_id);
}

/**
 * All active floor-worker users, for the "who should this missing-photos
 * alert go to" dropdown. Mirrors the permission IDs used by isFloorWorker().
 */
function getFloorWorkers() {
    try {
        $users = DB::getInstance()->query("SELECT id, fname, lname, email FROM users WHERE active = 1 ORDER BY lname, fname")->results() ?: [];
        return array_values(array_filter($users, fn($u) => isFloorWorker((int) $u->id)));
    } catch (\Throwable $e) {
        return [];
    }
}

function logContainerActivity($container_id, $user_id, $action, $details = '') {
    $db = DB::getInstance();
    $db->insert('container_activity_log', [
        'container_id' => $container_id,
        'user_id' => $user_id,
        'action' => $action,
        'details' => $details
    ]);
}

function sanitizeFileName($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    return $filename;
}

function getContainerPhotos($container_id, $photo_type = null) {
    $db = DB::getInstance();
    if ($photo_type) {
        return $db->query("SELECT * FROM container_photos WHERE container_id = ? AND photo_type = ? ORDER BY uploaded_at ASC", [$container_id, $photo_type])->results();
    }
    return $db->query("SELECT * FROM container_photos WHERE container_id = ? ORDER BY uploaded_at ASC", [$container_id])->results();
}

function getContainerById($container_id) {
    $db = DB::getInstance();
    $result = $db->query("SELECT * FROM containers WHERE id = ?", [$container_id])->first();
    return $result;
}

function getAllContainers($type = null, $status = null, $filter_by_access = true, $include_archived = false) {
    $db = DB::getInstance();
    $query = "SELECT c.*, u1.fname as creator_fname, u1.lname as creator_lname,
              cu.name as customer_name,
              wh.name as warehouse_name,
              (SELECT COUNT(*) FROM container_photos cp WHERE cp.container_id = c.id) AS photo_count
              FROM containers c
              LEFT JOIN users u1 ON c.created_by = u1.id
              LEFT JOIN customers cu ON c.customer_id = cu.id
              LEFT JOIN warehouses wh ON c.warehouse_id = wh.id
              WHERE 1=1";
    $params = [];

    if (!$include_archived) {
        $query .= " AND c.archived_at IS NULL";
    }
    
    if ($type) {
        $query .= " AND c.type = ?";
        $params[] = $type;
    }
    
    if ($status) {
        $query .= " AND c.status = ?";
        $params[] = $status;
    }
    
    $query .= " ORDER BY c.created_at DESC";
    
    $results = $db->query($query, $params)->results() ?: [];

    if ($filter_by_access) {
        $results = filterContainersByWarehouseAccess($results);
    }

    return $results;
}

/**
 * Server-side paginated + filtered container query, for the simple
 * dashboard (which was previously loading and rendering every container
 * ever created, then hiding most of them client-side - slow once the
 * table grows). Warehouse access is applied as a real SQL WHERE clause
 * here (not the post-fetch PHP filtering getAllContainers uses), since
 * post-filtering after a SQL LIMIT would silently return fewer rows than
 * requested and break page counts.
 *
 * $filters keys (all optional): search, type, status, customer_name,
 * warehouse_name, show_reviewed (bool - false hides status=reviewed
 * unless status filter explicitly asks for it).
 * $sort: container|client|warehouse|shipment|pobol|carrier|seal|type|
 *        eventdate|status|creator|date  $dir: asc|desc
 *
 * @return array ['rows' => object[], 'total' => int, 'total_pages' => int, 'page' => int]
 */
function getPaginatedContainers($filters, $user_id, $page = 1, $per_page = 50, $sort = 'date', $dir = 'desc') {
    $db = DB::getInstance();
    $page = max(1, (int) $page);
    $per_page = max(1, min(200, (int) $per_page));

    $where = ["c.archived_at IS NULL"]; // simple dashboard never shows archived containers
    $params = [];

    $allowed_warehouse_ids = getUserWarehouseIds($user_id);
    if (!empty($allowed_warehouse_ids)) {
        $ph = implode(',', array_fill(0, count($allowed_warehouse_ids), '?'));
        $where[] = "(c.warehouse_id IS NULL OR c.warehouse_id IN ({$ph}))";
        $params = array_merge($params, $allowed_warehouse_ids);
    }

    if (!empty($filters['type'])) {
        $where[] = "c.type = ?";
        $params[] = $filters['type'];
    }
    if (!empty($filters['status'])) {
        $where[] = "c.status = ?";
        $params[] = $filters['status'];
    } elseif (empty($filters['show_reviewed'])) {
        $where[] = "c.status != 'reviewed'";
    }
    if (!empty($filters['customer_name'])) {
        $where[] = "cu.name = ?";
        $params[] = $filters['customer_name'];
    }
    if (!empty($filters['warehouse_name'])) {
        $where[] = "wh.name = ?";
        $params[] = $filters['warehouse_name'];
    }
    if (!empty($filters['search'])) {
        $term = '%' . $filters['search'] . '%';
        $where[] = "(c.container_number LIKE ? OR c.seal_number LIKE ? OR c.shipment_number LIKE ? OR c.po_bol_number LIKE ? OR c.carrier LIKE ? OR cu.name LIKE ? OR wh.name LIKE ?)";
        array_push($params, $term, $term, $term, $term, $term, $term, $term);
    }

    $where_sql = implode(' AND ', $where);

    $sort_columns = [
        'container' => 'c.container_number', 'client' => 'cu.name', 'warehouse' => 'wh.name',
        'shipment' => 'c.shipment_number', 'pobol' => 'c.po_bol_number', 'carrier' => 'c.carrier',
        'seal' => 'c.seal_number', 'type' => 'c.type', 'eventdate' => 'c.receipt_ship_date',
        'status' => 'c.status', 'creator' => 'u1.fname', 'date' => 'c.created_at',
    ];
    $order_col = $sort_columns[$sort] ?? 'c.created_at';
    $order_dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

    $count_sql = "SELECT COUNT(*) AS cnt FROM containers c
                  LEFT JOIN customers cu ON c.customer_id = cu.id
                  LEFT JOIN warehouses wh ON c.warehouse_id = wh.id
                  WHERE {$where_sql}";
    $total = (int) $db->query($count_sql, $params)->first()->cnt;
    $total_pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $sql = "SELECT c.*, u1.fname as creator_fname, u1.lname as creator_lname,
            cu.name as customer_name, wh.name as warehouse_name,
            (SELECT COUNT(*) FROM container_photos cp WHERE cp.container_id = c.id) AS photo_count
            FROM containers c
            LEFT JOIN users u1 ON c.created_by = u1.id
            LEFT JOIN customers cu ON c.customer_id = cu.id
            LEFT JOIN warehouses wh ON c.warehouse_id = wh.id
            WHERE {$where_sql}
            ORDER BY {$order_col} {$order_dir}
            LIMIT {$per_page} OFFSET {$offset}";

    $rows = $db->query($sql, $params)->results() ?: [];

    return ['rows' => $rows, 'total' => $total, 'total_pages' => $total_pages, 'page' => $page];
}

/**
 * Fast aggregate counts for the dashboard stat cards - a single query
 * instead of loading every container into PHP just to count() them.
 * Respects the same warehouse-access + non-archived scoping as
 * getPaginatedContainers(), so the numbers always match what's browsable.
 */
function getContainerStats($user_id) {
    $db = DB::getInstance();
    $where = ["c.archived_at IS NULL"];
    $params = [];

    $allowed_warehouse_ids = getUserWarehouseIds($user_id);
    if (!empty($allowed_warehouse_ids)) {
        $ph = implode(',', array_fill(0, count($allowed_warehouse_ids), '?'));
        $where[] = "(c.warehouse_id IS NULL OR c.warehouse_id IN ({$ph}))";
        $params = array_merge($params, $allowed_warehouse_ids);
    }
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT
            COUNT(*) AS total,
            SUM(c.status = 'pending') AS pending,
            SUM(c.status = 'in_progress') AS in_progress,
            SUM(c.status = 'completed') AS completed,
            SUM(c.status = 'reviewed') AS reviewed,
            SUM(c.type = 'inbound') AS inbound,
            SUM(c.type = 'outbound') AS outbound
            FROM containers c WHERE {$where_sql}";

    $row = $db->query($sql, $params)->first();

    return [
        'total' => (int) $row->total, 'pending' => (int) $row->pending,
        'in_progress' => (int) $row->in_progress, 'completed' => (int) $row->completed,
        'reviewed' => (int) $row->reviewed, 'inbound' => (int) $row->inbound,
        'outbound' => (int) $row->outbound,
    ];
}

/**
 * Archives a container (hides it from the simple dashboard, keeps it on
 * the Pro dashboard) - reversible, doesn't touch photos or the record
 * itself. Local deletion is a separate, later, much more cautious step -
 * see cleanup_core.php.
 */
function archiveContainer($container_id, $user_id = null) {
    $db = DB::getInstance();
    $result = $db->update('containers', $container_id, ['archived_at' => date('Y-m-d H:i:s')]);
    if ($result !== false && $user_id) {
        logContainerActivity($container_id, $user_id, 'archived', 'Container archived (hidden from simple dashboard)');
    }
    return $result;
}

function unarchiveContainer($container_id, $user_id = null) {
    $db = DB::getInstance();
    $result = $db->update('containers', $container_id, ['archived_at' => null]);
    if ($result !== false && $user_id) {
        logContainerActivity($container_id, $user_id, 'unarchived', 'Container restored from archive');
    }
    return $result;
}

/**
 * Restricts a container list (as returned by getAllContainers) to the ones
 * the current user is allowed to see, based on warehouse tags:
 *   - A container with no warehouse_id is visible to everyone.
 *   - A user with NO warehouse tags at all is unrestricted (sees
 *     everything) — tagging is opt-in restriction, so nobody gets
 *     locked out just because this feature exists.
 *   - A user with one or more warehouse tags only sees containers in
 *     those warehouses (plus unassigned ones).
 * Pass $user_id explicitly for a non-session context; otherwise uses the
 * logged-in user, and skips filtering entirely if there is no session
 * (e.g. a cron script) rather than risk hiding everything.
 */
function filterContainersByWarehouseAccess($containers, $user_id = null) {
    global $user;
    if ($user_id === null) {
        if (!isset($user) || !$user || !$user->isLoggedIn()) return $containers;
        $user_id = $user->data()->id;
    }

    $allowed = getUserWarehouseIds($user_id);
    if (empty($allowed)) return $containers;

    return array_values(array_filter($containers, function($c) use ($allowed) {
        return empty($c->warehouse_id) || in_array((int)$c->warehouse_id, $allowed, true);
    }));
}

function getPhotoTypeLabel($photo_type, $container_type) {
    global $photo_types;
    return $photo_types[$container_type][$photo_type] ?? $photo_type;
}

/**
 * One date field, two meanings: "Receipt Date" for inbound containers
 * (when it arrived at the warehouse) and "Ship Date" for outbound
 * containers (when it left). Keeps the schema simple - just pick the
 * right label based on type wherever this is shown.
 */
function getDateFieldLabel($type) {
    return $type === 'inbound' ? 'Receipt Date' : 'Ship Date';
}

/**
 * Customers / Clients
 * Managed as a simple settings-style list - supervisors create/edit,
 * everyone can select a customer when creating or editing a container.
 */
function getAllCustomers() {
    $db = DB::getInstance();
    return $db->query("SELECT * FROM customers ORDER BY name ASC")->results() ?: [];
}

function getCustomerById($customer_id) {
    $db = DB::getInstance();
    return $db->query("SELECT * FROM customers WHERE id = ?", [$customer_id])->first();
}

// ── Unique identifier handling ──────────────────────────────────────────────
// containers.container_number used to have a hard DB-level UNIQUE constraint,
// which permanently blocked reusing a number even long after that container
// was done — a real problem for clients whose freight rides on their own
// reused trailers. That constraint is now dropped (see
// 12_identifier_migration.sql) and replaced with these app-level checks,
// scoped to currently-open containers only (anything not yet Reviewed) —
// and, for flagged clients, checking Shipment Number instead of Container
// Number as the thing that actually has to be unique.

/**
 * Which field is the real unique identifier for this customer:
 * 'shipment_number' if their profile is flagged to reuse container/trailer
 * numbers, otherwise the default 'container_number'. Customer-less
 * containers always use the default.
 */
function getIdentifierField($customer_id) {
    if (!$customer_id) return 'container_number';
    $customer = getCustomerById($customer_id);
    return ($customer && !empty($customer->use_shipment_number_as_id)) ? 'shipment_number' : 'container_number';
}

/**
 * Whether $value already exists in $field among currently-OPEN containers
 * (status != 'reviewed'). Pass $exclude_container_id when editing so a
 * container doesn't collide with itself. Returns the conflicting
 * container row, or null if there's no conflict.
 */
function findDuplicateIdentifier($field, $value, $exclude_container_id = null) {
    $value = trim((string) $value);
    if ($value === '' || !in_array($field, ['container_number', 'shipment_number'], true)) return null;

    $sql = "SELECT id, container_number, shipment_number, status FROM containers WHERE {$field} = ? AND status != 'reviewed'";
    $params = [$value];
    if ($exclude_container_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_container_id;
    }
    $sql .= " LIMIT 1";

    return DB::getInstance()->query($sql, $params)->first() ?: null;
}

function createCustomer($data) {
    $db = DB::getInstance();
    return $db->insert('customers', [
        'name' => $data['name'],
        'contact_name' => $data['contact_name'] ?: null,
        'email' => $data['email'] ?: null,
        'notification_emails_inbound' => $data['notification_emails_inbound'] ?: null,
        'notification_emails_outbound' => $data['notification_emails_outbound'] ?: null,
        'phone' => $data['phone'] ?: null,
        'notes' => $data['notes'] ?: null,
    ]);
}

function updateCustomer($customer_id, $data) {
    $db = DB::getInstance();
    return $db->update('customers', $customer_id, [
        'name' => $data['name'],
		'sku_scan_enabled' => $data['sku_scan_enabled'] ?? 0,
        'contact_name' => $data['contact_name'] ?: null,
        'email' => $data['email'] ?: null,
        'notification_emails_inbound' => $data['notification_emails_inbound'] ?: null,
        'notification_emails_outbound' => $data['notification_emails_outbound'] ?: null,
        'phone' => $data['phone'] ?: null,
        'notes' => $data['notes'] ?: null,
        'retention_days' => array_key_exists('retention_days', $data) && $data['retention_days'] !== '' ? (int) $data['retention_days'] : 90,
        'delete_after_days' => array_key_exists('delete_after_days', $data) && $data['delete_after_days'] !== '' ? (int) $data['delete_after_days'] : 30,
    ]);
}

/**
 * Splits a comma/newline-separated block of text into a clean list of
 * email addresses. Used for the customer "Notification Emails" fields.
 */
function parseEmailList($raw) {
    if (!$raw) return [];
    // Splits on commas, semicolons, and/or any whitespace (spaces, tabs,
    // newlines) - people separate emails with whatever feels natural
    // (comma, semicolon, just hitting space or Enter), so this needs to
    // be forgiving about the exact format rather than silently dropping
    // anything that doesn't match one specific style.
    $parts = preg_split('/[,;\s]+/', $raw);
    $emails = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $emails[] = $part;
        }
    }
    return $emails;
}

/**
 * Returns only the entries from a parsed email list that are NOT valid
 * email addresses - used to show a clear validation error.
 */
function getInvalidEmails($raw) {
    $emails = parseEmailList($raw);
    $invalid = [];
    foreach ($emails as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $invalid[] = $email;
        }
    }
    return $invalid;
}

/**
 * Returns only the VALID notification emails for a customer, for the given
 * container type ('inbound' or 'outbound') - clients can have a different
 * list of people notified depending on the direction of the container.
 */
function getCustomerNotificationEmails($customer, $type = 'inbound') {
    if (!$customer) {
        return [];
    }
    $field = $type === 'outbound' ? 'notification_emails_outbound' : 'notification_emails_inbound';
    if (empty($customer->$field)) {
        return [];
    }
    $emails = parseEmailList($customer->$field);
    return array_values(array_filter($emails, function($e) {
        return filter_var($e, FILTER_VALIDATE_EMAIL);
    }));
}

function isCustomerNameTaken($name, $exclude_id = null) {
    $db = DB::getInstance();
    if ($exclude_id) {
        $result = $db->query("SELECT COUNT(*) as count FROM customers WHERE name = ? AND id != ?", [$name, $exclude_id])->first();
    } else {
        $result = $db->query("SELECT COUNT(*) as count FROM customers WHERE name = ?", [$name])->first();
    }
    return $result->count > 0;
}

/**
 * Update a container's editable fields. Used by the modal-based "Pro"
 * dashboard so saves go through one shared, validated code path.
 */
function updateContainer($container_id, $data) {
    $db = DB::getInstance();

    $old_status = null;
    if (isset($data['status'])) {
        $existing = $db->query("SELECT status FROM containers WHERE id = ?", [$container_id])->first();
        $old_status = $existing ? $existing->status : null;
    }
    
    $update_data = [];
    if (isset($data['container_number'])) $update_data['container_number'] = trim($data['container_number']);
    if (isset($data['shipment_number'])) $update_data['shipment_number'] = trim($data['shipment_number']) ?: null;
    if (array_key_exists('receipt_ship_date', $data)) $update_data['receipt_ship_date'] = $data['receipt_ship_date'] ?: null;
    if (isset($data['po_bol_number'])) $update_data['po_bol_number'] = trim($data['po_bol_number']) ?: null;
    if (isset($data['carrier'])) $update_data['carrier'] = trim($data['carrier']) ?: null;
    if (array_key_exists('piece_count', $data)) $update_data['piece_count'] = ($data['piece_count'] !== '' && $data['piece_count'] !== null) ? (int)$data['piece_count'] : null;
    if (isset($data['seal_number'])) $update_data['seal_number'] = trim($data['seal_number']) ?: null;
    if (array_key_exists('customer_id', $data)) $update_data['customer_id'] = $data['customer_id'] ?: null;
    if (array_key_exists('warehouse_id', $data)) $update_data['warehouse_id'] = $data['warehouse_id'] ?: null;
    if (isset($data['type'])) $update_data['type'] = $data['type'];
    if (isset($data['status'])) $update_data['status'] = $data['status'];
    if (isset($data['notes'])) $update_data['notes'] = trim($data['notes']);
    
    if (empty($update_data)) {
        return false;
    }
    
    $result = $db->update('containers', $container_id, $update_data);

    if ($result !== false && isset($update_data['status']) && $update_data['status'] !== $old_status) {
        sendEventTriggeredReports($container_id, $update_data['status']); // fails silently, logs only — see reports_functions.php
    }

    return $result;
}

/**
 * Recursively delete a directory and its contents.
 * Used when deleting a container, to clean up its uploaded photos on disk
 * (the DB rows are removed automatically via ON DELETE CASCADE).
 */
function deleteDirectoryRecursive($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            deleteDirectoryRecursive($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Fully delete a container: removes the DB row (cascades to photos,
 * notifications, and activity log) and cleans up its upload folder on disk.
 */
function deleteContainerCompletely($container_id) {
    global $abs_us_root, $us_url_root;
    
    $container = getContainerById($container_id);
    if (!$container) {
        return false;
    }
    
    $upload_dir = $abs_us_root . $us_url_root . 'usersc/uploads/' . $container->type . '/' . $container_id;
    deleteDirectoryRecursive($upload_dir);
    
    $db = DB::getInstance();
    return $db->delete('containers', $container_id);
}

/**
 * Sends the container's photos to its client's notification email list.
 * Called automatically whenever a container's status changes TO "reviewed"
 * (see callers in container_edit.php, ajax/container_update.php, and
 * container_dashboard_pro.php - all three compare old vs new status before
 * calling this, so it only fires once per review, not on every subsequent
 * edit). Marking a container "completed" (floor work finished, awaiting
 * supervisor review) does NOT trigger this - only the review step does.
 *
 * Photos are attached directly to the email where the combined size allows;
 * if attaching everything would make the email too large to send reliably,
 * this falls back to a link-only email instead of attempting a huge send.
 */
// ── Warehouses (multi-warehouse via UserSpice tags) ─────────────────────────
/**
 * A "warehouse" links a friendly name to an existing UserSpice tag.
 * Tag a user with that tag (via UserSpice's normal tag UI) to scope them
 * to that warehouse. See usersc/warehouses.php for the admin page that
 * manages these, and 05_warehouses_migration.sql for the schema.
 */
function getWarehouses($active_only = true) {
    try {
        $sql = "SELECT * FROM warehouses" . ($active_only ? " WHERE active = 1" : "") . " ORDER BY sort_order ASC, name ASC";
        return DB::getInstance()->query($sql)->results() ?: [];
    } catch (\Throwable $e) {
        return []; // migration not run yet — fail open, no warehouses configured
    }
}

function getWarehouseById($warehouse_id) {
    if (!$warehouse_id) return null;
    try {
        return DB::getInstance()->query("SELECT * FROM warehouses WHERE id = ?", [$warehouse_id])->first();
    } catch (\Throwable $e) {
        return null;
    }
}

function createWarehouse($tag_id, $name, $sort_order = 0) {
    return DB::getInstance()->insert('warehouses', [
        'tag_id'     => (int) $tag_id,
        'name'       => trim($name),
        'sort_order' => (int) $sort_order,
    ]);
}

function updateWarehouse($warehouse_id, $data) {
    return DB::getInstance()->update('warehouses', $warehouse_id, $data);
}

function deleteWarehouse($warehouse_id) {
    return DB::getInstance()->delete('warehouses', $warehouse_id);
}

/**
 * All UserSpice tags, for the "pick a tag to register as a warehouse"
 * dropdown. This install's tags plugin uses `plg_tags` (id, tag, descrip).
 */
function getAllUserSpiceTags() {
    try {
        return DB::getInstance()->query("SELECT id, tag FROM plg_tags ORDER BY tag ASC")->results() ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Warehouse ids (our warehouses.id, not the raw UserSpice tag id) the
 * given user has access to. Empty array means "no warehouse tags" —
 * callers treat that as unrestricted, see filterContainersByWarehouseAccess().
 */
function getUserWarehouseIds($user_id) {
    static $cache = [];
    if (isset($cache[$user_id])) return $cache[$user_id];

    $ids = [];
    try {
        foreach (getWarehouses(false) as $w) {
            if (function_exists('hasTag') && hasTag((int) $w->tag_id, $user_id)) {
                $ids[] = (int) $w->id;
            }
        }
    } catch (\Throwable $e) {
        return []; // tags plugin unavailable — fail open, don't lock everyone out
    }

    $cache[$user_id] = $ids;
    return $ids;
}

/**
 * Warehouses to offer in a picker (create/edit container forms) for this
 * user: their own tagged warehouse(s) if they have any, otherwise every
 * warehouse — so an untagged floor worker/supervisor can still assign one.
 */
function getWarehousesForUser($user_id) {
    $all = getWarehouses();
    $allowed_ids = getUserWarehouseIds($user_id);
    if (empty($allowed_ids)) return $all;
    return array_values(array_filter($all, fn($w) => in_array((int) $w->id, $allowed_ids, true)));
}


function getSupervisorEmails($warehouse_id = null) {
    try {
        $perm_rows = fetchPermissionUsers(3);
        if (empty($perm_rows)) { error_log('getSupervisorEmails: 0 rows'); return []; }
        $ids = array_values(array_map('intval', array_column((array)$perm_rows,'user_id')));

        // Scope to supervisors tagged for this warehouse, if one was
        // given and at least one supervisor is actually tagged for it.
        // If nobody's been tagged yet, fall back to ALL supervisors
        // rather than silently sending the review email to nobody.
        if ($warehouse_id) {
            $warehouse = getWarehouseById($warehouse_id);
            if ($warehouse && function_exists('hasTag')) {
                $tagged_ids = array_values(array_filter($ids, fn($uid) => hasTag((int) $warehouse->tag_id, $uid)));
                if (!empty($tagged_ids)) {
                    $ids = $tagged_ids;
                } else {
                    error_log('getSupervisorEmails: no supervisor tagged for warehouse "' . $warehouse->name . '" — notifying all supervisors instead');
                }
            }
        }

        $ph  = implode(',',array_fill(0,count($ids),'?'));
        return DB::getInstance()->query(
            "SELECT id,email,fname,lname FROM users WHERE id IN ({$ph}) AND active=1 AND email IS NOT NULL AND email!='' ORDER BY lname,fname",
            $ids
        )->results() ?: [];
    } catch (\Throwable $e) { error_log('getSupervisorEmails: '.$e->getMessage()); return []; }
}

function sendReadyForReviewNotification($container_id, $submitted_by_user_id) {
    try {
        require_once __DIR__ . '/sparkpost_email.php';
        $db=$db=DB::getInstance(); $container=getContainerById($container_id);
        if (!$container) return ['sent'=>false,'reason'=>'Container not found'];
        $supervisors=getSupervisorEmails($container->warehouse_id ?? null);
        if (empty($supervisors)) return ['sent'=>false,'reason'=>'No supervisors found'];
        $to_emails=array_values(array_filter(array_map(fn($s)=>trim($s->email),$supervisors),fn($e)=>filter_var($e,FILTER_VALIDATE_EMAIL)));
        if (empty($to_emails)) return ['sent'=>false,'reason'=>'No valid supervisor emails'];
        $sub=$db->query("SELECT fname,lname FROM users WHERE id=?",[$submitted_by_user_id])->first();
        $sub_name=$sub?trim(($sub->fname??'').' '.($sub->lname??'')):'A floor worker';
        $base=rtrim(getContainerSetting('site_url',CONTAINER_SITE_URL),'/');
        $subject='Ready for Review: '.($container->container_number??$container_id);
        $customer=!empty($container->customer_id)?getCustomerById($container->customer_id):null;
        $html='<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;">';
        $html.='<div style="background:#1e3a5f;padding:20px 24px;"><h2 style="margin:0;color:#fff;font-size:18px;">Container Ready for Review</h2><p style="margin:4px 0 0;color:#9bbdd6;font-size:12px;">'.date('l, F j, Y \\a\\t g:i A').'</p></div>';
        $html.='<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;padding:20px 24px;"><p style="font-size:14px;color:#374151;margin:0 0 16px;"><strong>'.htmlspecialchars($sub_name).'</strong> has finished photographing container <strong style="font-family:monospace;">'.htmlspecialchars($container->container_number??'').'</strong>.</p>';
        $html.='<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">';
        foreach(['Type'=>ucfirst($container->type??''),'Client'=>$customer?($customer->name??'—'):'—','Carrier'=>$container->carrier??'—'] as $k=>$v)
            $html.='<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:7px 0;color:#6b7280;width:100px;">'.$k.'</td><td style="padding:7px 0;font-weight:600;">'.htmlspecialchars((string)$v).'</td></tr>';
        $html.='</table><a href="'.htmlspecialchars($base.'/usersc/container_view.php?id='.$container_id).'" style="display:inline-block;background:#1e3a5f;color:#fff;padding:11px 24px;text-decoration:none;font-weight:700;font-size:13px;">Review &amp; Send Photos</a></div></div>';
        $result=sendSparkPostEmail($to_emails,$subject,$html);
        if ($result['success']) logContainerActivity($container_id,$submitted_by_user_id,'review_notification_sent','Email sent to: '.implode(', ',$to_emails));
        else error_log('sendReadyForReviewNotification failed: '.($result['message']??''));
        return ['sent'=>$result['success'],'reason'=>$result['message']??''];
    } catch (\Throwable $e) {
        error_log('sendReadyForReviewNotification exception: '.$e->getMessage());
        return ['sent'=>false,'reason'=>'Exception: '.$e->getMessage()];
    }
}

/**
 * Resizes and compresses an image for email attachment using PHP GD.
 * Returns compressed JPEG data ready for base64 encoding, or false if GD
 * is unavailable or the file can't be processed.
 *
 * @param string $file_path  Absolute path to the image
 * @param int    $max_dim    Maximum width OR height in pixels (default 1600)
 * @param int    $quality    JPEG quality 0-100 (default 80)
 * @return array|false  ['data' => string (raw bytes), 'size' => int] or false
 */
function resizeImageForEmail($file_path, $max_dim = 1600, $quality = 80) {
    if (!file_exists($file_path) || !function_exists('imagecreatefromjpeg')) return false;

    $info = @getimagesize($file_path);
    if (!$info) return false;

    [$orig_w, $orig_h] = $info;
    $mime = $info['mime'];

    // Read EXIF orientation before loading (JPEG only)
    $orientation = 1;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file_path);
        if ($exif && isset($exif['Orientation'])) {
            $orientation = (int) $exif['Orientation'];
        }
    }

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file_path),
        'image/png'  => @imagecreatefrompng($file_path),
        'image/gif'  => @imagecreatefromgif($file_path),
        default      => false,
    };
    if (!$src) return false;

    // Apply EXIF rotation to the source before resizing
    // Orientations 5,6,7,8 mean the image is physically 90° or 270° rotated
    $src = match ($orientation) {
        2 => (imageflip($src, IMG_FLIP_HORIZONTAL) ? $src : $src),
        3 => imagerotate($src, 180, 0),
        4 => (imageflip($src, IMG_FLIP_VERTICAL) ? $src : $src),
        5 => (imageflip(imagerotate($src, -90, 0), IMG_FLIP_HORIZONTAL) ? imagerotate($src, -90, 0) : imagerotate($src, -90, 0)),
        6 => imagerotate($src, -90, 0),
        7 => (imageflip(imagerotate($src, 90, 0), IMG_FLIP_HORIZONTAL) ? imagerotate($src, 90, 0) : imagerotate($src, 90, 0)),
        8 => imagerotate($src, 90, 0),
        default => $src,
    };

    // After rotation, actual dimensions may have swapped
    $actual_w = imagesx($src);
    $actual_h = imagesy($src);

    if ($actual_w > $max_dim || $actual_h > $max_dim) {
        $ratio = min($max_dim / $actual_w, $max_dim / $actual_h);
        $new_w = (int) round($actual_w * $ratio);
        $new_h = (int) round($actual_h * $ratio);
    } else {
        $new_w = $actual_w;
        $new_h = $actual_h;
    }

    $dst = imagecreatetruecolor($new_w, $new_h);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $new_w, $new_h, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $actual_w, $actual_h);

    ob_start();
    imagejpeg($dst, null, $quality);
    $data = ob_get_clean();

    imagedestroy($src);
    imagedestroy($dst);

    return ['data' => $data, 'size' => strlen($data)];
}

function cfLog($message, $context = []) {
    $log_file = __DIR__ . '/../logs/email_debug.log';
    $ts       = date('Y-m-d H:i:s');
    $ctx      = empty($context) ? '' : ' ' . json_encode($context);
    file_put_contents($log_file, "[{$ts}] {$message}{$ctx}\n", FILE_APPEND | LOCK_EX);
}

function sendCompletionNotification($container_id, $triggered_by_user_id = 0) {
    global $abs_us_root, $us_url_root;

    cfLog("sendCompletionNotification START", ['container_id' => $container_id, 'triggered_by' => $triggered_by_user_id]);

    $container = getContainerById($container_id);
    if (!$container) {
        cfLog("ABORT: container not found", ['container_id' => $container_id]);
        return ['sent' => false, 'reason' => 'Container not found'];
    }
    if (!$container->customer_id) {
        cfLog("ABORT: no customer assigned", ['container_number' => $container->container_number]);
        return ['sent' => false, 'reason' => 'No client assigned to this container'];
    }

    cfLog("Container found", ['number' => $container->container_number, 'type' => $container->type, 'customer_id' => $container->customer_id]);

    $customer = getCustomerById($container->customer_id);
    if (!$customer) {
        cfLog("ABORT: customer record not found", ['customer_id' => $container->customer_id]);
        return ['sent' => false, 'reason' => 'Customer record not found'];
    }

    cfLog("Customer found", ['name' => $customer->name]);

    $emails = getCustomerNotificationEmails($customer, $container->type);
    if (empty($emails)) {
        cfLog("ABORT: no notification emails", ['customer' => $customer->name, 'type' => $container->type, 'inbound_raw' => $customer->inbound_emails ?? '', 'outbound_raw' => $customer->outbound_emails ?? '']);
        return ['sent' => false, 'reason' => 'Client has no ' . $container->type . ' notification emails on file'];
    }

    cfLog("Recipients found", ['emails' => $emails]);

    require_once $abs_us_root . $us_url_root . 'usersc/includes/sparkpost_email.php';

    $photos = getContainerPhotos($container_id);
    cfLog("Photos found", ['count' => count($photos)]);

    $max_total_bytes = 15 * 1024 * 1024;
    $attachments     = [];
    $total_bytes     = 0;
    $skipped_photos  = 0;
    $gd_available    = function_exists('imagecreatefromjpeg');

    cfLog("GD available", ['gd' => $gd_available]);

    foreach ($photos as $photo) {
        $file_path = $abs_us_root . $us_url_root . $photo->file_path;
        if (!file_exists($file_path)) {
            cfLog("Photo file missing — skipping", ['path' => $file_path, 'file_name' => $photo->file_name]);
            continue;
        }

        $original_size = filesize($file_path);
        $attach_name   = pathinfo($photo->file_name, PATHINFO_FILENAME) . '.jpg';
        $attach_data   = false;

        if ($gd_available) {
            $resized = resizeImageForEmail($file_path, 1600, 80);
            if ($resized) {
                $attach_data = $resized['data'];
                $attach_size = $resized['size'];
                cfLog("Photo resized", ['file' => $photo->file_name, 'original_kb' => round($original_size/1024), 'resized_kb' => round($attach_size/1024)]);
            } else {
                cfLog("GD resize failed — falling back to raw", ['file' => $photo->file_name]);
            }
        }

        if ($attach_data === false) {
            if ($total_bytes + $original_size > $max_total_bytes) {
                cfLog("Photo skipped — would exceed 15MB cap", ['file' => $photo->file_name, 'size_kb' => round($original_size/1024), 'total_so_far_kb' => round($total_bytes/1024)]);
                $skipped_photos++; continue;
            }
            $attach_data = file_get_contents($file_path);
            $attach_size = $original_size;
            $attach_name = $photo->file_name;
        } else {
            if ($total_bytes + $attach_size > $max_total_bytes) {
                cfLog("Resized photo still exceeds cap — skipping", ['file' => $photo->file_name]);
                $skipped_photos++; continue;
            }
        }

        $ext  = strtolower(pathinfo($attach_name, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : 'image/jpeg');

        $attachments[] = ['name' => $attach_name, 'type' => $mime, 'data' => base64_encode($attach_data)];
        $total_bytes += $attach_size;
        cfLog("Photo attached", ['file' => $attach_name, 'size_kb' => round($attach_size/1024), 'running_total_kb' => round($total_bytes/1024)]);
    }

    cfLog("Attachment summary", ['attached' => count($attachments), 'skipped' => $skipped_photos, 'total_kb' => round($total_bytes/1024)]);
    $date_label  = getDateFieldLabel($container->type);
    $client_name = $customer ? $customer->name : 'No Client';
    $portal_url  = getPortalUrl($container_id); // was previously used below but never set — pre-existing bug, fixed here

    // Pre-build the optional row/notice blocks — these stay fixed HTML,
    // the editable template just decides where the {{...}} tokens for
    // them go (see usersc/includes/email_templates.php).
    $shipment_row = $container->shipment_number
        ? '<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:7px 0;color:#6b7280;">Shipment #</td><td style="padding:7px 0;font-weight:600;">' . htmlspecialchars($container->shipment_number) . '</td></tr>'
        : '';

    $po_bol_row = $container->po_bol_number
        ? '<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:7px 0;color:#6b7280;">PO / BOL</td><td style="padding:7px 0;font-weight:600;">' . htmlspecialchars($container->po_bol_number) . '</td></tr>'
        : '';

    $date_row = $container->receipt_ship_date
        ? '<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:7px 0;color:#6b7280;">' . htmlspecialchars($date_label) . '</td><td style="padding:7px 0;font-weight:600;">' . date('M d, Y', strtotime($container->receipt_ship_date)) . '</td></tr>'
        : '';

    $skipped_notice = $skipped_photos > 0
        ? '<div style="background:#fef3c7;border-left:3px solid #f59e0b;padding:10px 14px;margin-bottom:16px;font-size:13px;">'
          . $skipped_photos . ' photo(s) were too large to attach even after compression. '
          . '<a href="' . htmlspecialchars($portal_url) . '" style="color:#92400e;font-weight:700;">View all photos online</a></div>'
        : '';

    $vars = [
        'client_name'      => htmlspecialchars($client_name),
        'container_number' => htmlspecialchars($container->container_number),
        'date'             => date('l, F j, Y'),
        'photo_count'      => count($photos),
        'portal_url'       => htmlspecialchars($portal_url),
        'shipment_row'     => $shipment_row,
        'po_bol_row'       => $po_bol_row,
        'date_row'         => $date_row,
        'skipped_notice'   => $skipped_notice,
    ];

    $template = getEmailTemplate($container->type); // 'inbound' or 'outbound'
    $subject  = renderEmailTemplate($template['subject'], $vars);
    $html     = renderEmailTemplate($template['html_body'], $vars);

    cfLog("Calling SparkPost", ['subject' => $subject, 'to' => $emails, 'attachments' => count($attachments)]);

    $result = sendSparkPostEmail($emails, $subject, $html, null, null, $attachments);

    cfLog("SparkPost result", ['success' => $result['success'], 'message' => $result['message'] ?? '(none)']);

    if ($result['success']) {
        logContainerActivity($container_id, $triggered_by_user_id, 'notification_sent',
            'Sent completion notification to: ' . implode(', ', $emails));
    }

    return ['sent' => $result['success'], 'reason' => $result['message'] ?? ''];
}

// ── Client portal helpers ─────────────────────────────────────────────────────

function isClient($user_id = null) {
    global $user;
    $perm = (int) getContainerSetting('client_permission_id', 4);
    if ($user_id === null) return hasPerm([$perm]);
    return hasPerm([$perm], $user_id);
}

function getClientCustomer($user_id = null) {
    global $user;
    if ($user_id === null) $user_id = $user->data()->id;
    try {
        return DB::getInstance()->query(
            "SELECT cu.* FROM customers cu
             INNER JOIN container_client_users ccu ON ccu.customer_id = cu.id
             WHERE ccu.user_id = ? LIMIT 1",
            [(int)$user_id]
        )->first();
    } catch (\Throwable $e) { return null; }
}

function linkClientUser($user_id, $customer_id) {
    DB::getInstance()->query(
        "INSERT INTO container_client_users (user_id, customer_id)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id)",
        [(int)$user_id, (int)$customer_id]
    );
}

function unlinkClientUser($user_id) {
    DB::getInstance()->query(
        "DELETE FROM container_client_users WHERE user_id = ?",
        [(int)$user_id]
    );
}

function ensureClientUsersTable() {
    try {
        DB::getInstance()->query(
            "CREATE TABLE IF NOT EXISTS container_client_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                customer_id INT NOT NULL,
                created_at DATETIME DEFAULT NOW(),
                UNIQUE KEY uq_user (user_id),
                INDEX idx_customer (customer_id)
            )"
        );
    } catch (\Throwable $e) {
        error_log('ensureClientUsersTable: ' . $e->getMessage());
    }
}
