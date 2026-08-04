<?php
// Container Tracking System Configuration
// This file should be placed in usersc/includes/ directory

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

// Helper functions
function isSupervisor($user_id = null) {
    global $user;
    if ($user_id === null) {
        $user_id = $user->data()->id;
    }
    return hasPerm([10], $user_id); // Permission ID 10 is for supervisors
}

function isFloorWorker($user_id = null) {
    global $user;
    if ($user_id === null) {
        $user_id = $user->data()->id;
    }
    return hasPerm([10, 11], $user_id); // Permission IDs 10 (supervisors) and 11 (floor workers)
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

function getAllContainers($type = null, $status = null) {
    $db = DB::getInstance();
    $query = "SELECT c.*, u1.fname as creator_fname, u1.lname as creator_lname,
              cu.name as customer_name
              FROM containers c 
              LEFT JOIN users u1 ON c.created_by = u1.id
              LEFT JOIN customers cu ON c.customer_id = cu.id WHERE 1=1";
    $params = [];
    
    if ($type) {
        $query .= " AND c.type = ?";
        $params[] = $type;
    }
    
    if ($status) {
        $query .= " AND c.status = ?";
        $params[] = $status;
    }
    
    $query .= " ORDER BY c.created_at DESC";
    
    return $db->query($query, $params)->results();
}

function getPhotoTypeLabel($photo_type, $container_type) {
    global $photo_types;
    return $photo_types[$container_type][$photo_type] ?? $photo_type;
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

function createCustomer($data) {
    $db = DB::getInstance();
    return $db->insert('customers', [
        'name' => $data['name'],
        'contact_name' => $data['contact_name'] ?: null,
        'email' => $data['email'] ?: null,
        'phone' => $data['phone'] ?: null,
        'notes' => $data['notes'] ?: null,
    ]);
}

function updateCustomer($customer_id, $data) {
    $db = DB::getInstance();
    return $db->update('customers', $customer_id, [
        'name' => $data['name'],
        'contact_name' => $data['contact_name'] ?: null,
        'email' => $data['email'] ?: null,
        'phone' => $data['phone'] ?: null,
        'notes' => $data['notes'] ?: null,
    ]);
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
    
    $update_data = [];
    if (isset($data['container_number'])) $update_data['container_number'] = trim($data['container_number']);
    if (isset($data['shipment_number'])) $update_data['shipment_number'] = trim($data['shipment_number']) ?: null;
    if (isset($data['seal_number'])) $update_data['seal_number'] = trim($data['seal_number']) ?: null;
    if (array_key_exists('customer_id', $data)) $update_data['customer_id'] = $data['customer_id'] ?: null;
    if (isset($data['type'])) $update_data['type'] = $data['type'];
    if (isset($data['status'])) $update_data['status'] = $data['status'];
    if (isset($data['notes'])) $update_data['notes'] = trim($data['notes']);
    
    if (empty($update_data)) {
        return false;
    }
    
    return $db->update('containers', $container_id, $update_data);
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
