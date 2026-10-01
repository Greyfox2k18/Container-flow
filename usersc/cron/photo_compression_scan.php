#!/usr/bin/env php
<?php
/**
 * Photo compression scan - run this via SSH/CLI, not a browser.
 *
 * Scans every container photo that hasn't been compressed yet
 * (container_photos.compressed_at IS NULL). Defaults to DRY RUN (see
 * PHOTO_COMPRESSION_LIVE_MODE in usersc/includes/photo_compression_config.php) -
 * it measures what compression WOULD save and writes a report, without
 * touching a single file, until you deliberately turn that on.
 *
 * This can take a while over a large photo catalog (each photo has to be
 * decoded and re-encoded in memory to measure it) - that's exactly why
 * this is a CLI script rather than a button on a web page that could
 * time out partway through.
 *
 * Usage:
 *   php usersc/cron/photo_compression_scan.php
 *
 * After a dry run, check usersc/photo_compression_preview.php (or the
 * JSON report directly) to see projected savings before ever
 * considering flipping PHOTO_COMPRESSION_LIVE_MODE to true.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/../includes/photo_compression_config.php';
require_once __DIR__ . '/../includes/photo_compression_core.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DRIVE_BACKUP_DB_HOST . ';dbname=' . DRIVE_BACKUP_DB_NAME . ';charset=utf8mb4',
        DRIVE_BACKUP_DB_USER,
        DRIVE_BACKUP_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    fwrite(STDERR, "FATAL: Database connection failed - " . $e->getMessage() . "\n");
    exit(1);
}

set_time_limit(0); // this can genuinely take a while over a large catalog - let it run

echo "Starting photo compression scan (" . (PHOTO_COMPRESSION_LIVE_MODE ? "LIVE" : "DRY RUN") . ")...\n";
echo "Target: max " . PHOTO_COMPRESSION_MAX_DIM . "px, quality " . PHOTO_COMPRESSION_QUALITY . "\n\n";

$scan_start_time = time();
$progress = function ($scanned, $total, $saved_bytes) use ($scan_start_time) {
    $elapsed = max(1, time() - $scan_start_time);
    $rate = $scanned / $elapsed; // photos per second
    $remaining = $total - $scanned;
    $eta_seconds = $rate > 0 ? (int) round($remaining / $rate) : 0;
    $eta = $eta_seconds >= 60 ? round($eta_seconds / 60) . 'm' : $eta_seconds . 's';
    $pct = $total > 0 ? round(100 * $scanned / $total) : 100;

    echo sprintf(
        "  [%3d%%] %d / %d photos - saved so far: %.1f MB - est. remaining: %s\n",
        $pct, $scanned, $total, $saved_bytes / 1024 / 1024, $eta
    );
};

$report = runPhotoCompressionScan(
    $pdo,
    DRIVE_BACKUP_SITE_ROOT,
    PHOTO_COMPRESSION_MAX_DIM,
    PHOTO_COMPRESSION_QUALITY,
    !PHOTO_COMPRESSION_LIVE_MODE,
    $progress
);
writePhotoCompressionReport($report);

$total_elapsed = time() - $scan_start_time;
echo "\nDone in " . ($total_elapsed >= 60 ? round($total_elapsed / 60, 1) . " minutes" : "{$total_elapsed} seconds") . ".\n\n";
echo "Scanned: {$report['photos_scanned']}\n";
echo "Compressed: {$report['photos_compressed']}\n";
echo "Original size: " . round($report['original_bytes'] / 1024 / 1024, 1) . " MB\n";
echo "New size: " . round($report['new_bytes'] / 1024 / 1024, 1) . " MB\n";
echo "Saved: " . round($report['saved_bytes'] / 1024 / 1024, 1) . " MB\n";
if (!empty($report['skipped_reasons'])) {
    echo "\nSkipped:\n";
    foreach ($report['skipped_reasons'] as $reason => $count) {
        echo "  - {$count}x: {$reason}\n";
    }
}
echo "\nFull report written to " . PHOTO_COMPRESSION_REPORT_FILE . "\n";
echo "View it at usersc/photo_compression_preview.php\n";
