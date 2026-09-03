<?php
/**
 * Configuration for the local container cleanup cron.
 *
 * Reuses the DB credentials and site root already defined in
 * drive_backup_config.php rather than duplicating them here.
 */
require_once __DIR__ . '/drive_backup_config.php';

// SAFETY DEFAULT: false = dry run. The cron will log exactly what it
// WOULD delete without deleting anything. Once you've reviewed a few
// dry-run logs (or the preview page) and are confident it's picking the
// right containers, change this to true to actually start deleting.
//
// This is a deliberately hand-edited constant, not a database/web toggle -
// flipping on permanent, automatic deletion should take a conscious file
// edit, not one misclick in a browser.
define('CLEANUP_LIVE_MODE', false);

// Circuit breaker: max containers deleted in a single run, even in live
// mode. Protects against a config or logic mistake mass-deleting
// everything in one pass. Increase once you trust it's behaving correctly.
define('CLEANUP_MAX_PER_RUN', 25);

// Where the cleanup run log gets written. The cron and the manual
// "Run Cleanup Now" button both append to this same file.
define('CLEANUP_LOG_FILE', __DIR__ . '/../logs/container_cleanup.log');
