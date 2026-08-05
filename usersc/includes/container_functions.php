<?php
// Container Tracking System Configuration
// This file should be placed in usersc/includes/ directory

if (!defined('CONTAINER_SITE_URL')) define('CONTAINER_SITE_URL', 'https://container-flow.com');

require_once __DIR__ . '/email_templates.php';

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
              cu.name as customer_name,
              (SELECT COUNT(*) FROM container_photos cp WHERE cp.container_id = c.id) AS photo_count
              FROM containers c
              LEFT JOIN users u1 ON c.created_by = u1.id
              LEFT JOIN customers cu ON c.customer_id = cu.id
              WHERE 1=1";
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
        'contact_name' => $data['contact_name'] ?: null,
        'email' => $data['email'] ?: null,
        'notification_emails_inbound' => $data['notification_emails_inbound'] ?: null,
        'notification_emails_outbound' => $data['notification_emails_outbound'] ?: null,
        'phone' => $data['phone'] ?: null,
        'notes' => $data['notes'] ?: null,
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
    
    $update_data = [];
    if (isset($data['container_number'])) $update_data['container_number'] = trim($data['container_number']);
    if (isset($data['shipment_number'])) $update_data['shipment_number'] = trim($data['shipment_number']) ?: null;
    if (array_key_exists('receipt_ship_date', $data)) $update_data['receipt_ship_date'] = $data['receipt_ship_date'] ?: null;
    if (isset($data['po_bol_number'])) $update_data['po_bol_number'] = trim($data['po_bol_number']) ?: null;
    if (isset($data['carrier'])) $update_data['carrier'] = trim($data['carrier']) ?: null;
    if (array_key_exists('piece_count', $data)) $update_data['piece_count'] = ($data['piece_count'] !== '' && $data['piece_count'] !== null) ? (int)$data['piece_count'] : null;
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
function getSupervisorEmails() {
    try {
        $perm_rows = fetchPermissionUsers(3);
        if (empty($perm_rows)) { error_log('getSupervisorEmails: 0 rows'); return []; }
        $ids = array_values(array_map('intval', array_column((array)$perm_rows,'user_id')));
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
        $supervisors=getSupervisorEmails();
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
