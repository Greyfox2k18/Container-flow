<?php
// Prevent any output before JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in output

require_once '../../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

// Clear any output that happened during includes
ob_end_clean();

header('Content-Type: application/json');

if (!$user->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if (!isFloorWorker()) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!Token::check(Input::get('csrf'))) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$container_id = Input::get('container_id');
$photo_type = Input::get('photo_type');
$photo_description = Input::get('photo_description');

if (!$container_id || !$photo_type) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$container = getContainerById($container_id);
if (!$container) {
    echo json_encode(['success' => false, 'message' => 'Container not found']);
    exit;
}

$user_id = $user->data()->id;

// Validate photo type
global $photo_types;
if (!isset($photo_types[$container->type][$photo_type])) {
    echo json_encode(['success' => false, 'message' => 'Invalid photo type']);
    exit;
}

// Check if files were uploaded
if (!isset($_FILES['photo_files']) || empty($_FILES['photo_files']['name'][0])) {
    echo json_encode(['success' => false, 'message' => 'No files uploaded']);
    exit;
}

$uploaded_count = 0;
$errors = [];

// Create upload directory if it doesn't exist
$upload_dir = $abs_us_root.$us_url_root.'usersc/uploads/' . $container->type . '/' . $container_id . '/';

if (!file_exists($upload_dir)) {
    $mkdir_result = @mkdir($upload_dir, 0775, true);
    if (!$mkdir_result) {
        $last_error = error_get_last();
        $reason = $last_error ? $last_error['message'] : 'unknown error';
        echo json_encode([
            'success' => false,
            'message' => "Could not create upload directory. This is a permissions issue on the server - the web server user needs write access to usersc/uploads/{$container->type}/. Details: {$reason}",
            'attempted_path' => $upload_dir
        ]);
        exit;
    }
}

if (!is_writable($upload_dir)) {
    echo json_encode([
        'success' => false,
        'message' => "Upload directory exists but is not writable by the web server. Run: chown -R www-data:www-data usersc/uploads && chmod -R 775 usersc/uploads",
        'attempted_path' => $upload_dir
    ]);
    exit;
}

$db = DB::getInstance();

// Process each file
foreach ($_FILES['photo_files']['name'] as $key => $filename) {
    if ($_FILES['photo_files']['error'][$key] !== UPLOAD_ERR_OK) {
        $err_code = $_FILES['photo_files']['error'][$key];
        $err_reasons = [
            UPLOAD_ERR_INI_SIZE => 'the file exceeds the server\'s upload_max_filesize setting (php.ini)',
            UPLOAD_ERR_FORM_SIZE => 'the file exceeds the form\'s MAX_FILE_SIZE setting',
            UPLOAD_ERR_PARTIAL => 'the file was only partially uploaded (connection interrupted)',
            UPLOAD_ERR_NO_FILE => 'no file was actually received',
            UPLOAD_ERR_NO_TMP_DIR => 'missing a temporary folder on the server',
            UPLOAD_ERR_CANT_WRITE => 'the server failed to write the file to disk',
            UPLOAD_ERR_EXTENSION => 'a PHP extension stopped the upload',
        ];
        $reason = $err_reasons[$err_code] ?? "unknown upload error (PHP code {$err_code})";
        $errors[] = "Failed to upload {$filename}: {$reason}";
        continue;
    }
    
    $file_size = $_FILES['photo_files']['size'][$key];
    $file_tmp = $_FILES['photo_files']['tmp_name'][$key];
    
    // Check file size
    if ($file_size > MAX_FILE_SIZE) {
        $errors[] = "{$filename} exceeds maximum file size (40MB)";
        continue;
    }
    
    // Check file extension
    $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($file_ext, ALLOWED_EXTENSIONS)) {
        $errors[] = "{$filename} has an invalid file type";
        continue;
    }
    
    // Generate unique filename
    $new_filename = uniqid() . '_' . sanitizeFileName($filename);
    $file_path = $upload_dir . $new_filename;
    $relative_path = 'usersc/uploads/' . $container->type . '/' . $container_id . '/' . $new_filename;
    
    // Move uploaded file
    if (@move_uploaded_file($file_tmp, $file_path)) {
        // Insert into database
        $insert = $db->insert('container_photos', [
            'container_id' => $container_id,
            'photo_type' => $photo_type,
            'file_path' => $relative_path,
            'file_name' => $filename,
            'description' => $photo_description,
            'uploaded_by' => $user_id
        ]);
        
        if ($insert) {
            $uploaded_count++;
        } else {
            $errors[] = "Failed to save {$filename} to database";
            unlink($file_path); // Delete the file
        }
    } else {
        $last_error = error_get_last();
        $reason = $last_error ? $last_error['message'] : 'unknown error';
        $errors[] = "Failed to move {$filename} to upload directory ({$reason})";
    }
}

// Log activity
if ($uploaded_count > 0) {
    logContainerActivity($container_id, $user_id, 'uploaded_photos', "Uploaded {$uploaded_count} photo(s) of type: {$photo_type}");
}

// Update container status if it was pending
if ($container->status == 'pending') {
    $db->update('containers', $container_id, ['status' => 'in_progress']);
}

if ($uploaded_count > 0) {
    $message = "{$uploaded_count} photo(s) uploaded successfully";
    if (!empty($errors)) {
        $message .= ". Some uploads failed: " . implode(', ', $errors);
    }
    echo json_encode(['success' => true, 'message' => $message, 'uploaded' => $uploaded_count]);
} else {
    echo json_encode(['success' => false, 'message' => 'No photos were uploaded. ' . implode(', ', $errors)]);
}
