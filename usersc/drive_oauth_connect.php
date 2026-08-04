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

$creds = require DRIVE_OAUTH_CREDENTIALS_FILE;
if (empty($creds['client_id']) || $creds['client_id'] === 'YOUR_CLIENT_ID_HERE.apps.googleusercontent.com') {
    die('OAuth client credentials are not configured yet. Fill in usersc/includes/google_oauth_credentials.php first - see GOOGLE_DRIVE_OAUTH_SETUP.md.');
}

$params = [
    'client_id' => $creds['client_id'],
    'redirect_uri' => DRIVE_OAUTH_REDIRECT_URI,
    'response_type' => 'code',
    // drive: full Drive access, needed to create/manage backup folders.
    // userinfo.email: lets us show which account got connected.
    'scope' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/userinfo.email',
    // offline + consent together guarantee Google issues a refresh token
    // every time, even if this app was already authorized before.
    'access_type' => 'offline',
    'prompt' => 'consent',
];

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit;
