<?php
/**
 * Google Drive API integration for the nightly backup system.
 *
 * Uses OAuth (authenticating as your own Google account) rather than a
 * Service Account, since service accounts have no storage quota of their
 * own and can't actually hold uploaded files on a free Google account.
 * A one-time "Connect Google Drive Account" flow (see
 * drive_oauth_connect.php / drive_oauth_callback.php) produces a
 * long-lived refresh token, which this file exchanges for a fresh
 * short-lived access token on every backup run - no repeated logins.
 *
 * Implemented with raw cURL rather than Google's official PHP client
 * library, to avoid adding a Composer dependency to a project that
 * doesn't otherwise have one.
 */

require_once __DIR__ . '/drive_backup_config.php';

function getGoogleOAuthCredentials() {
    if (!defined('DRIVE_OAUTH_CREDENTIALS_FILE') || !file_exists(DRIVE_OAUTH_CREDENTIALS_FILE)) {
        return null;
    }
    return require DRIVE_OAUTH_CREDENTIALS_FILE;
}

function getGoogleOAuthToken() {
    if (!defined('DRIVE_OAUTH_TOKEN_FILE') || !file_exists(DRIVE_OAUTH_TOKEN_FILE)) {
        return null;
    }
    return require DRIVE_OAUTH_TOKEN_FILE;
}

/**
 * Saves the refresh token after a successful connect. Called by
 * drive_oauth_callback.php - not meant to be called during a normal
 * backup run.
 */
function saveGoogleOAuthToken($refresh_token, $connected_email) {
    if (!defined('DRIVE_OAUTH_TOKEN_FILE')) {
        return false;
    }

    $content = "<?php\n";
    $content .= "/**\n * Auto-generated - do not edit by hand. See google_oauth_token.php's\n";
    $content .= " * original comment for details.\n */\n\n";
    $content .= "return [\n";
    $content .= "    'refresh_token' => " . var_export($refresh_token, true) . ",\n";
    $content .= "    'connected_email' => " . var_export($connected_email, true) . ",\n";
    $content .= "    'connected_at' => " . var_export(date('Y-m-d H:i:s'), true) . ",\n";
    $content .= "];\n";

    return file_put_contents(DRIVE_OAUTH_TOKEN_FILE, $content) !== false;
}

/**
 * Exchanges the saved refresh token for a short-lived access token.
 * Returns ['access_token' => ...] on success, ['error' => ...] on failure.
 */
function getDriveAccessToken() {
    $creds = getGoogleOAuthCredentials();
    if (!$creds || empty($creds['client_id']) || empty($creds['client_secret'])) {
        return ['error' => 'OAuth client credentials are not configured (check google_oauth_credentials.php)'];
    }

    $token_data = getGoogleOAuthToken();
    if (!$token_data || empty($token_data['refresh_token'])) {
        return ['error' => 'Google Drive is not connected yet - visit usersc/drive_backup_status.php and click "Connect Google Drive Account"'];
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $creds['client_id'],
        'client_secret' => $creds['client_secret'],
        'refresh_token' => $token_data['refresh_token'],
        'grant_type' => 'refresh_token',
    ]));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['error' => 'Connection error reaching Google: ' . $curl_error];
    }

    $data = json_decode($response, true);
    if ($http_code !== 200 || empty($data['access_token'])) {
        $detail = $data['error_description'] ?? $data['error'] ?? $response;
        return ['error' => "Token refresh failed (HTTP {$http_code}): {$detail}. You may need to reconnect via the Drive Backups page."];
    }

    return ['access_token' => $data['access_token']];
}

function driveApiGet($access_token, $url, $params = []) {
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Finds a folder by name inside a parent folder, or creates it if it
 * doesn't exist yet. Returns ['id' => ..., 'link' => ...] on success,
 * or ['error' => ...] on failure.
 */
function driveFindOrCreateFolder($access_token, $folder_name, $parent_id) {
    $escaped_name = str_replace("'", "\\'", $folder_name);
    $q = "name = '{$escaped_name}' and '{$parent_id}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";

    $result = driveApiGet($access_token, 'https://www.googleapis.com/drive/v3/files', [
        'q' => $q,
        'fields' => 'files(id,webViewLink)',
    ]);

    if (!empty($result['files'][0]['id'])) {
        return ['id' => $result['files'][0]['id'], 'link' => $result['files'][0]['webViewLink'] ?? null];
    }

    // If the lookup itself failed (e.g. invalid parent_id, no access),
    // the response carries an error block instead of a files list.
    if (!empty($result['error'])) {
        $detail = $result['error']['message'] ?? json_encode($result['error']);
        return ['error' => "Could not search for folder: {$detail}"];
    }

    $ch = curl_init('https://www.googleapis.com/drive/v3/files?fields=id,webViewLink');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'name' => $folder_name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parent_id],
    ]));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if (empty($data['id'])) {
        $detail = $data['error']['message'] ?? $response;
        return ['error' => "Folder creation failed (HTTP {$http_code}): {$detail}"];
    }
    return ['id' => $data['id'], 'link' => $data['webViewLink'] ?? null];
}

/**
 * Uploads a single file into the given Drive folder.
 * Returns ['id' => ..., 'name' => ...] on success, ['error' => ...] on failure.
 */
function driveUploadFile($access_token, $file_path, $file_name, $parent_folder_id, $mime_type) {
    if (!file_exists($file_path)) {
        return ['error' => 'File not found on disk: ' . $file_path];
    }

    $metadata = json_encode([
        'name' => $file_name,
        'parents' => [$parent_folder_id],
    ]);

    $boundary = '-------DriveBackup' . uniqid();
    $file_content = file_get_contents($file_path);

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= $metadata . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: {$mime_type}\r\n\r\n";
    $body .= $file_content . "\r\n";
    $body .= "--{$boundary}--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // photos can be several MB each
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: multipart/related; boundary=' . $boundary,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['error' => 'Connection error: ' . $curl_error];
    }

    $data = json_decode($response, true);
    if ($http_code !== 200 || empty($data['id'])) {
        $detail = $data['error']['message'] ?? $response;
        return ['error' => "Upload failed (HTTP {$http_code}): {$detail}"];
    }

    return ['id' => $data['id'], 'name' => $data['name'] ?? $file_name];
}

/**
 * Finds a file by name inside a parent folder (unlike
 * driveFindOrCreateFolder, not restricted to folders). Returns
 * ['id' => ..., 'link' => ...] if found, null if genuinely not found,
 * or ['error' => ...] if the search itself failed (these are distinct -
 * a failed search should never be treated the same as "not found",
 * since that would risk creating a duplicate instead of surfacing the
 * problem).
 */
function driveFindFile($access_token, $file_name, $parent_id) {
    $escaped_name = str_replace("'", "\\'", $file_name);
    $q = "name = '{$escaped_name}' and '{$parent_id}' in parents and trashed = false";

    $result = driveApiGet($access_token, 'https://www.googleapis.com/drive/v3/files', [
        'q' => $q,
        'fields' => 'files(id,webViewLink)',
    ]);

    if (!empty($result['error'])) {
        $detail = $result['error']['message'] ?? json_encode($result['error']);
        return ['error' => "Could not search for file: {$detail}"];
    }
    if (!empty($result['files'][0]['id'])) {
        return ['id' => $result['files'][0]['id'], 'link' => $result['files'][0]['webViewLink'] ?? null];
    }
    return null;
}

/**
 * Replaces the CONTENT of an existing Drive file in place - same file
 * ID, same webViewLink, same sharing settings. Used to push a
 * recompressed local photo up without creating a duplicate alongside
 * the old, larger copy.
 * Returns ['id' => ..., 'name' => ...] on success, ['error' => ...] on failure.
 */
function driveUpdateFileContent($access_token, $file_id, $file_path, $mime_type) {
    if (!file_exists($file_path)) {
        return ['error' => 'File not found on disk: ' . $file_path];
    }

    $ch = curl_init("https://www.googleapis.com/upload/drive/v3/files/{$file_id}?uploadType=media");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: ' . $mime_type,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($file_path));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['error' => 'Connection error: ' . $curl_error];
    }

    $data = json_decode($response, true);
    if ($http_code !== 200 || empty($data['id'])) {
        $detail = $data['error']['message'] ?? $response;
        return ['error' => "Update failed (HTTP {$http_code}): {$detail}"];
    }

    return ['id' => $data['id'], 'name' => $data['name'] ?? basename($file_path)];
}

/**
 * The safe entry point backups should use instead of calling
 * driveUploadFile() directly: looks for an existing file with this name
 * in the folder first, and updates it in place if found, instead of
 * blindly creating a new file every time - which Drive allows even for
 * an identical name, silently producing duplicates on any re-run (e.g.
 * re-backing-up a container after its local photos were recompressed).
 * Returns the same shape as driveUploadFile(), plus 'replaced' => bool.
 */
function driveUploadOrReplaceFile($access_token, $file_path, $file_name, $parent_folder_id, $mime_type) {
    $existing = driveFindFile($access_token, $file_name, $parent_folder_id);

    if (is_array($existing) && !empty($existing['error'])) {
        // The search itself failed - don't fall through to create, which
        // could produce a duplicate if the file actually does exist.
        return $existing;
    }

    if ($existing && !empty($existing['id'])) {
        $result = driveUpdateFileContent($access_token, $existing['id'], $file_path, $mime_type);
        if (!empty($result['error'])) return $result;
        return ['id' => $result['id'], 'name' => $result['name'], 'replaced' => true];
    }

    $result = driveUploadFile($access_token, $file_path, $file_name, $parent_folder_id, $mime_type);
    if (!empty($result['error'])) return $result;
    return ['id' => $result['id'], 'name' => $result['name'], 'replaced' => false];
}
