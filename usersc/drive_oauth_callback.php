<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

if (!isSupervisor()) {
    Redirect::to('container_dashboard.php');
}

require_once $abs_us_root.$us_url_root.'usersc/includes/drive_backup_config.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/google_drive.php';

// The user clicked "Cancel" / "Deny" on Google's consent screen
if (!empty($_GET['error'])) {
    Redirect::to('drive_backup_status.php?oauth_error=' . urlencode($_GET['error']));
}

$code = $_GET['code'] ?? null;
if (!$code) {
    Redirect::to('drive_backup_status.php?oauth_error=no_code_received');
}

$creds = getGoogleOAuthCredentials();
if (!$creds || empty($creds['client_id']) || empty($creds['client_secret'])) {
    Redirect::to('drive_backup_status.php?oauth_error=credentials_not_configured');
}

// Exchange the authorization code for an access token + refresh token
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id' => $creds['client_id'],
    'client_secret' => $creds['client_secret'],
    'code' => $code,
    'grant_type' => 'authorization_code',
    'redirect_uri' => DRIVE_OAUTH_REDIRECT_URI,
]));
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($http_code !== 200 || empty($data['refresh_token'])) {
    // A missing refresh_token (with an otherwise-200 response) usually
    // means Google already had one on file and didn't re-issue it -
    // this shouldn't happen since we request prompt=consent, but flag
    // it clearly if it does.
    $detail = $data['error_description'] ?? $data['error'] ?? 'No refresh token returned';
    Redirect::to('drive_backup_status.php?oauth_error=' . urlencode($detail));
}

$access_token = $data['access_token'] ?? null;
$refresh_token = $data['refresh_token'];

// Look up which Google account this is, just for display on the status page
$connected_email = null;
if ($access_token) {
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
    $userinfo_response = curl_exec($ch);
    curl_close($ch);
    $userinfo = json_decode($userinfo_response, true);
    $connected_email = $userinfo['email'] ?? null;
}

$saved = saveGoogleOAuthToken($refresh_token, $connected_email);

if (!$saved) {
    Redirect::to('drive_backup_status.php?oauth_error=' . urlencode('Connected successfully, but failed to save the token to disk - check that usersc/includes/google_oauth_token.php is writable by the web server (see Step 3 in the setup guide).'));
}

Redirect::to('drive_backup_status.php?oauth_connected=1');
