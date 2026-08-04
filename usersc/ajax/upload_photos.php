<?php
/**
 * Upload Photos — Container Tracking System
 * Handles both AJAX (returns JSON) and direct form POST (redirects).
 * Photo type validation uses getPhotoTypes() — respects per-client config.
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

ob_end_clean();

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($is_ajax) header('Content-Type: application/json');

function upload_respond($success, $message, $container_id = null, $uploaded = 0, $reward_info = false, $points_earned = 0) {
    global $is_ajax, $us_url_root;
    if ($is_ajax) {
        echo json_encode([
            'success'       => $success,
            'message'       => $message,
            'uploaded'      => $uploaded,
            'points_earned' => $points_earned,
            'reward_earned' => $reward_info ? true : false,
            'reward_info'   => $reward_info ?: null,
        ]);
    } else {
        $type   = $success ? 'success' : 'error';
        $params = http_build_query(['id' => $container_id, 'upload' => $type, 'upload_msg' => $message, 'reward_earned' => $reward_info ? 1 : 0]);
        Redirect::to($us_url_root . 'usersc/container_view.php?' . $params);
    }
    exit;
}

if (!$user->isLoggedIn()) upload_respond(false, 'Not authenticated');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') upload_respond(false, 'Invalid request method');
if (!Token::check(Input::get('csrf'))) upload_respond(false, 'Invalid CSRF token — please refresh and try again');

$container_id      = Input::get('container_id');
$photo_type        = Input::get('photo_type');
$photo_description = Input::get('photo_description');

if (!$container_id || !$photo_type) upload_respond(false, 'Missing required fields', $container_id);

$container = getContainerById($container_id);
if (!$container) upload_respond(false, 'Container not found', $container_id);

$user_id = $user->data()->id;

// Validate photo type against the client-specific config (or default)
$allowed_types = getPhotoTypes($container->customer_id ?? null, $container->type);
if (!isset($allowed_types[$photo_type])) {
    upload_respond(false, 'Invalid photo type', $container_id);
}

if (!isset($_FILES['photo_files']) || empty($_FILES['photo_files']['name'][0])) {
    upload_respond(false, 'No files uploaded', $container_id);
}

$uploaded_count = 0;
$errors         = [];
$upload_dir     = $abs_us_root . $us_url_root . 'usersc/uploads/' . $container->type . '/' . $container_id . '/';

if (!file_exists($upload_dir)) {
    $res = @mkdir($upload_dir, 0775, true);
    if (!$res) {
        $err = error_get_last();
        upload_respond(false, 'Could not create upload directory. Run: chown -R www-data:www-data usersc/uploads — ' . ($err['message'] ?? ''), $container_id);
    }
}

if (!is_writable($upload_dir)) {
    upload_respond(false, 'Upload directory not writable. Run: chown -R www-data:www-data usersc/uploads && chmod -R 775 usersc/uploads', $container_id);
}

$db = DB::getInstance();

/**
 * Reads EXIF orientation from a JPEG and rotates the physical pixels to match,
 * then strips the orientation tag by saving clean. This ensures all stored
 * photos display correctly everywhere — browser, email, portal, PDF.
 */
function autoRotateImage($file_path) {
    if (!function_exists('imagecreatefromjpeg') || !function_exists('exif_read_data')) return;
    if (strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) === 'gif') return;

    $mime = @mime_content_type($file_path);
    if ($mime !== 'image/jpeg') return;

    $exif = @exif_read_data($file_path);
    if (!$exif || !isset($exif['Orientation']) || $exif['Orientation'] == 1) return;

    $src = @imagecreatefromjpeg($file_path);
    if (!$src) return;

    $rotated = match ((int) $exif['Orientation']) {
        2 => (imageflip($src, IMG_FLIP_HORIZONTAL) ? $src : $src),
        3 => imagerotate($src, 180, 0),
        4 => (imageflip($src, IMG_FLIP_VERTICAL) ? $src : $src),
        6 => imagerotate($src, -90, 0),
        8 => imagerotate($src, 90, 0),
        default => $src,
    };

    // Save back with orientation baked in at high quality (95%)
    imagejpeg($rotated, $file_path, 95);
    imagedestroy($rotated);
    if ($rotated !== $src) imagedestroy($src);
}

foreach ($_FILES['photo_files']['name'] as $key => $filename) {
    if ($_FILES['photo_files']['error'][$key] !== UPLOAD_ERR_OK) {
        $err_map = [
            UPLOAD_ERR_INI_SIZE   => 'exceeds server upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE  => 'exceeds form MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL    => 'only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'no file received',
            UPLOAD_ERR_NO_TMP_DIR => 'missing temporary folder on server',
            UPLOAD_ERR_CANT_WRITE => 'server failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'a PHP extension stopped the upload',
        ];
        $errors[] = "{$filename}: " . ($err_map[$_FILES['photo_files']['error'][$key]] ?? 'unknown error');
        continue;
    }

    $file_size = $_FILES['photo_files']['size'][$key];
    $file_tmp  = $_FILES['photo_files']['tmp_name'][$key];
    $file_ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if ($file_size > MAX_FILE_SIZE)                         { $errors[] = "{$filename} exceeds 40MB limit"; continue; }
    if (!in_array($file_ext, ALLOWED_EXTENSIONS))           { $errors[] = "{$filename} invalid file type"; continue; }

    $new_filename  = uniqid() . '_' . sanitizeFileName($filename);
    $file_path     = $upload_dir . $new_filename;
    $relative_path = 'usersc/uploads/' . $container->type . '/' . $container_id . '/' . $new_filename;

    if (@move_uploaded_file($file_tmp, $file_path)) {
        // Auto-rotate JPEG based on EXIF orientation so stored files are always upright
        autoRotateImage($file_path);
        $insert = $db->insert('container_photos', [
            'container_id' => $container_id,
            'photo_type'   => $photo_type,
            'file_path'    => $relative_path,
            'file_name'    => $filename,
            'description'  => $photo_description,
            'uploaded_by'  => $user_id,
        ]);
        if ($insert) { $uploaded_count++; }
        else { $errors[] = "DB insert failed for {$filename}"; unlink($file_path); }
    } else {
        $err   = error_get_last();
        $errors[] = "Move failed for {$filename}: " . ($err['message'] ?? 'unknown');
    }
}

if ($uploaded_count > 0) {
    logContainerActivity($container_id, $user_id, 'uploaded_photos',
        "Uploaded {$uploaded_count} photo(s) of type: {$photo_type}");

    // Auto-advance pending → in_progress on first upload
    if ($container->status === 'pending') {
        $db->update('containers', $container_id, ['status' => 'in_progress']);
    }
}

// Award points per photo
$reward_info   = false;
$points_earned = 0;
try {
    $pts = (int) getContainerSetting('points_upload_photo', 10);
    if ($pts > 0 && $uploaded_count > 0) {
        $points_earned = $uploaded_count * $pts;
        alterPoints($user->data()->username, $points_earned, 'give',
            "Uploaded {$uploaded_count} photo(s) to container {$container->container_number}");
        $reward_info = checkPointsReward($user_id, $points_earned);
    }
} catch (\Throwable $e) { /* points unavailable */ }

if ($uploaded_count > 0) {
    $msg = "{$uploaded_count} photo(s) uploaded successfully";
    if (!empty($errors)) $msg .= '. Some failed: ' . implode(', ', $errors);
    upload_respond(true, $msg, $container_id, $uploaded_count, $reward_info, $points_earned);
} else {
    upload_respond(false, 'No photos uploaded. ' . implode(', ', $errors), $container_id);
}
