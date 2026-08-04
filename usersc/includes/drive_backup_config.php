<?php
/**
 * Configuration for the Google Drive nightly backup system.
 * Fill in the values below - see GOOGLE_DRIVE_SETUP.md for how to get each one.
 */

// Absolute filesystem path to your site root (the folder that CONTAINS
// usersc/, users/, etc). Run `pwd` from that folder via SSH to confirm.
// Must end with a trailing slash.
define('DRIVE_BACKUP_SITE_ROOT', '/var/www/container-flow.com/html/');

// Database credentials - the SAME ones your UserSpice site already uses
// (check usersc/init.php or users/init.php on your server if unsure).
define('DRIVE_BACKUP_DB_HOST', 'localhost');
define('DRIVE_BACKUP_DB_NAME', ' container-flow');
define('DRIVE_BACKUP_DB_USER', 'robinsservices');
define('DRIVE_BACKUP_DB_PASS', 'x4f8x6hXAPn]Ax8V');
define('DRIVE_OAUTH_CREDENTIALS_FILE', __DIR__ . '/google_oauth_credentials.php');
define('DRIVE_OAUTH_TOKEN_FILE', __DIR__ . '/google_oauth_token.php');
define('DRIVE_OAUTH_REDIRECT_URI', 'https://container-flow.com/usersc/drive_oauth_callback.php');

// The Google Drive folder ID where backups should be created. This is the
// long string in the folder's URL after /folders/, e.g. for
// https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQrStUvWxYz
// the ID is: 1AbCdEfGhIjKlMnOpQrStUvWxYz
// This folder must be shared with your service account's email address
// (found in the credentials file) with "Editor" access.
define('DRIVE_BACKUP_ROOT_FOLDER_ID', '1b2dCNy6fYMhD-B8dgZmIqJdxYNvhh4ya');

// Path to the file holding your service account credentials (see
// google_drive_credentials.php in this same folder) - shouldn't need to
// change this unless you move the file.
define('DRIVE_BACKUP_CREDENTIALS_FILE', __DIR__ . '/google_drive_credentials.php');

// Where the backup run log gets written. The cron script and the manual
// "Run Backup Now" button both append to this same file.
define('DRIVE_BACKUP_LOG_FILE', __DIR__ . '/../logs/drive_backup.log');
