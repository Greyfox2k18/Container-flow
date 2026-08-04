<?php
// Prevent any output before JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

// Clear any output that happened during includes
ob_end_clean();

header('Content-Type: application/json');

if (!$user->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
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

$photo_id = Input::get('photo_id');

if (!$photo_id) {
    echo json_encode(['success' => false, 'message' => 'Photo ID is required']);
    exit;
}

$db = DB::getInstance();
$photo = $db->query("SELECT * FROM container_photos WHERE id = ?", [$photo_id])->first();

if (!$photo) {
    echo json_encode(['success' => false, 'message' => 'Photo not found']);
    exit;
}

// Get container to check permissions
$container = getContainerById($photo->container_id);
if (!$container) {
    echo json_encode(['success' => false, 'message' => 'Container not found']);
    exit;
}

$user_id = $user->data()->id;

// Delete the physical file
$file_path = $abs_us_root.$us_url_root . $photo->file_path;
if (file_exists($file_path)) {
    unlink($file_path);
}

// Delete from database
$delete = $db->delete('container_photos', ['id' => $photo_id]);

if ($delete) {
    logContainerActivity($photo->container_id, $user_id, 'deleted_photo', "Deleted photo: {$photo->file_name}");

    // Deduct points for the deleted photo — mirrors the upload award to prevent gaming
    try {
        $pts         = (int) getContainerSetting('points_delete_photo', 10);
        $uploader_id = $photo->uploaded_by ?? $user_id;
        $uploader    = $db->query("SELECT username FROM users WHERE id = ?", [$uploader_id])->first();
        if ($pts > 0 && $uploader) {
            alterPoints($uploader->username, $pts, 'take',
                "Photo deleted from container {$container->container_number}");
        }
    } catch (\Throwable $e) { /* points unavailable, skip silently */ }

    echo json_encode(['success' => true, 'message' => 'Photo deleted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete photo from database']);
}
