<?php
/**
 * Configuration for photo compression - used by both the retroactive
 * scan (usersc/cron/photo_compression_scan.php) and the live upload
 * handler (usersc/ajax/upload_photos.php), so both compress to the same
 * target with one shared source of truth.
 *
 * Reuses the DB credentials and site root already defined in
 * drive_backup_config.php rather than duplicating them here.
 */
require_once __DIR__ . '/drive_backup_config.php';

// Target for compression - longest dimension in pixels, and JPEG quality.
define('PHOTO_COMPRESSION_MAX_DIM', 2000);
define('PHOTO_COMPRESSION_QUALITY', 85);

// SAFETY DEFAULT: false = dry run. The scan will measure and report what
// it WOULD save without touching any files, until you deliberately turn
// this on. Same reasoning as CLEANUP_LIVE_MODE in cleanup_config.php -
// a hand-edited constant, not a web toggle, since this is a one-way,
// lossy operation on your only local copy of every existing photo.
define('PHOTO_COMPRESSION_LIVE_MODE', true);

define('PHOTO_COMPRESSION_REPORT_FILE', __DIR__ . '/../logs/photo_compression_report.json');
