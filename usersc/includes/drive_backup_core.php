<?php
/**
 * Core Google Drive backup logic - finds containers needing backup,
 * uploads their photos, and marks them as backed up on success.
 *
 * Used by both usersc/cron/drive_backup.php (the nightly cron job) and
 * usersc/ajax/drive_backup_run.php (the manual "Run Backup Now" button),
 * so the actual backup behavior only lives in one place.
 *
 * Takes a plain PDO connection rather than UserSpice's DB class, since
 * the cron entry point runs outside the normal web request lifecycle and
 * shouldn't depend on session/cookie bootstrap code.
 */

require_once __DIR__ . '/google_drive.php';

function getMimeTypeForPhoto($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'png': return 'image/png';
        case 'gif': return 'image/gif';
        case 'webp': return 'image/webp';
        default: return 'image/jpeg';
    }
}

/**
 * Runs one full backup pass: finds every container that either has never
 * been backed up, or has photos newer than its last backup, uploads
 * everything to Drive, and updates the database on success.
 *
 * A container is only marked as backed up if ALL of its photos uploaded
 * successfully - if any photo fails, the whole container is left pending
 * so the next run retries it rather than silently losing a photo.
 *
 * @param PDO $pdo
 * @param string $site_root Absolute filesystem path to the site root
 * @return array ['success' => bool, 'log' => string[], 'success_count' => int, 'fail_count' => int]
 */
function runDriveBackup($pdo, $site_root) {
    $log = [];
    $log[] = '[' . date('Y-m-d H:i:s') . '] Starting Drive backup run';

    $token_result = getDriveAccessToken();
    if (!empty($token_result['error'])) {
        $log[] = 'FATAL: ' . $token_result['error'];
        return ['success' => false, 'log' => $log, 'success_count' => 0, 'fail_count' => 0];
    }
    $access_token = $token_result['access_token'];

    $sql = "SELECT c.*,
            cu.name as customer_name,
            (SELECT MAX(uploaded_at) FROM container_photos WHERE container_id = c.id) as latest_photo_at
            FROM containers c
            LEFT JOIN customers cu ON c.customer_id = cu.id
            WHERE c.drive_backed_up_at IS NULL
               OR (SELECT MAX(uploaded_at) FROM container_photos WHERE container_id = c.id) > c.drive_backed_up_at";

    $stmt = $pdo->query($sql);
    $containers = $stmt->fetchAll(PDO::FETCH_OBJ);

    $log[] = 'Found ' . count($containers) . ' container(s) needing backup';

    $success_count = 0;
    $fail_count = 0;

    // Reused across containers within the same run so we don't repeatedly
    // hit the API to find a client folder we already found/created moments ago.
    $client_folder_cache = [];

    foreach ($containers as $container) {
        // Nothing to back up yet if there are no photos at all
        if (empty($container->latest_photo_at)) {
            continue;
        }

        $client_name = $container->customer_name ?: 'No Client';

        if (isset($client_folder_cache[$client_name])) {
            $client_folder = $client_folder_cache[$client_name];
        } else {
            $client_folder = driveFindOrCreateFolder($access_token, $client_name, DRIVE_BACKUP_ROOT_FOLDER_ID);
            $client_folder_cache[$client_name] = $client_folder;
        }

        if (!$client_folder || empty($client_folder['id'])) {
            $error_detail = $client_folder['error'] ?? 'Unknown error';
            $log[] = "FAILED: Could not create/find client folder \"{$client_name}\" for container #{$container->id} ({$container->container_number}): {$error_detail}";
            $fail_count++;
            continue;
        }

        $folder_name = strtoupper($container->type) . '_' . $container->container_number . '_' . $container->id;
        $folder = driveFindOrCreateFolder($access_token, $folder_name, $client_folder['id']);

        if (!$folder || empty($folder['id'])) {
            $error_detail = $folder['error'] ?? 'Unknown error';
            $log[] = "FAILED: Could not create/find Drive folder for container #{$container->id} ({$container->container_number}): {$error_detail}";
            $fail_count++;
            continue;
        }

        $photo_stmt = $pdo->prepare("SELECT * FROM container_photos WHERE container_id = ?");
        $photo_stmt->execute([$container->id]);
        $photos = $photo_stmt->fetchAll(PDO::FETCH_OBJ);

        $uploaded = 0;
        $errors = 0;

        foreach ($photos as $photo) {
            $file_path = rtrim($site_root, '/') . '/' . $photo->file_path;
            $mime = getMimeTypeForPhoto($photo->file_name);
            $result = driveUploadFile($access_token, $file_path, $photo->file_name, $folder['id'], $mime);

            if (!empty($result['error'])) {
                $log[] = "  - Photo upload failed ({$photo->file_name}) for container #{$container->id}: " . $result['error'];
                $errors++;
            } else {
                $uploaded++;
            }
        }

        // Small text summary alongside the raw photos, for context if
        // someone opens the Drive folder without the app in front of them
        $summary = "Container Number: {$container->container_number}\n";
        $summary .= "Type: " . ucfirst($container->type) . "\n";
        $summary .= "Status: " . ucfirst(str_replace('_', ' ', $container->status)) . "\n";
        $summary .= "Shipment Number: " . ($container->shipment_number ?: 'N/A') . "\n";
        $summary .= "Seal Number: " . ($container->seal_number ?: 'N/A') . "\n";
        $summary .= "PO/BOL Number: " . ($container->po_bol_number ?: 'N/A') . "\n";
        $summary .= "Carrier: " . ($container->carrier ?: 'N/A') . "\n";
        $summary .= "Notes: " . ($container->notes ?: 'None') . "\n";
        $summary .= "Backed up: " . date('Y-m-d H:i:s') . "\n";

        $tmp_path = sys_get_temp_dir() . '/container_' . $container->id . '_summary.txt';
        file_put_contents($tmp_path, $summary);
        driveUploadFile($access_token, $tmp_path, 'container_info.txt', $folder['id'], 'text/plain');
        @unlink($tmp_path);

        if ($errors === 0) {
            $update_stmt = $pdo->prepare("UPDATE containers SET drive_backed_up_at = NOW(), drive_folder_link = ? WHERE id = ?");
            $update_stmt->execute([$folder['link'], $container->id]);
            $log[] = "OK: Container #{$container->id} ({$container->container_number}) - {$uploaded} photo(s) backed up";
            $success_count++;
        } else {
            $log[] = "PARTIAL: Container #{$container->id} ({$container->container_number}) - {$uploaded} uploaded, {$errors} failed - will retry next run";
            $fail_count++;
        }
    }

    $log[] = '[' . date('Y-m-d H:i:s') . "] Backup run complete: {$success_count} succeeded, {$fail_count} failed/partial";

    return ['success' => true, 'log' => $log, 'success_count' => $success_count, 'fail_count' => $fail_count];
}

/**
 * Appends a backup run's log lines to the log file, creating the logs
 * directory first if it doesn't exist yet.
 */
function writeDriveBackupLog($log_lines) {
    $log_file = DRIVE_BACKUP_LOG_FILE;
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    $text = implode("\n", $log_lines) . "\n";
    $written = @file_put_contents($log_file, $text, FILE_APPEND);
    if ($written === false) {
        // Don't let a logging failure hide the real backup result - at
        // least get this into the PHP error log so it's not totally silent.
        error_log("drive_backup: could not write to log file {$log_file} - check directory permissions/ownership");
    }
    return $text;
}
