#!/usr/bin/env php
<?php
/**
 * One-off: finds containers that were already backed up to Drive BEFORE
 * their local photos got compressed - meaning Drive still holds the old,
 * large originals. Resets drive_backed_up_at so the next normal backup
 * run (cron or "Run Backup Now") picks them back up naturally, now using
 * driveUploadOrReplaceFile() (see google_drive.php / drive_backup_core.php)
 * which updates the existing Drive file in place instead of creating a
 * duplicate alongside it.
 *
 * Run this ONCE, after you've already run the local compression scan
 * live (not before - there'd be nothing to catch up yet).
 *
 * Defaults to DRY RUN - shows what it WOULD reset without changing
 * anything. Pass --live to actually do it:
 *   php usersc/cron/drive_recompress_resync.php          (dry run)
 *   php usersc/cron/drive_recompress_resync.php --live   (actually resets)
 *
 * This does NOT talk to the Drive API itself - it only flags containers
 * for the existing backup process to pick up on its own next run.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/../includes/drive_backup_config.php';

$live = in_array('--live', $argv, true);

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

// A container needs re-syncing if it was already backed up, AND has at
// least one photo whose compressed_at is AFTER that backup happened -
// meaning Drive's copy of that photo is still the old, large original.
$sql = "SELECT DISTINCT c.id, c.container_number, c.drive_backed_up_at
        FROM containers c
        INNER JOIN container_photos p ON p.container_id = c.id
        WHERE c.drive_backed_up_at IS NOT NULL
          AND p.compressed_at IS NOT NULL
          AND p.compressed_at > c.drive_backed_up_at
        ORDER BY c.id";

$containers = $pdo->query($sql)->fetchAll(PDO::FETCH_OBJ);

echo "Found " . count($containers) . " container(s) whose Drive copy predates local compression.\n\n";

if (empty($containers)) {
    echo "Nothing to do.\n";
    exit(0);
}

foreach ($containers as $c) {
    echo ($live ? "Resetting" : "[DRY RUN] Would reset") . ": Container #{$c->id} ({$c->container_number}) - last backed up {$c->drive_backed_up_at}\n";
}

if (!$live) {
    echo "\nThis was a dry run - nothing was changed. Re-run with --live to actually reset these,\n";
    echo "then let your normal backup process (cron or \"Run Backup Now\") pick them up.\n";
    exit(0);
}

$ids = array_column($containers, 'id');
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("UPDATE containers SET drive_backed_up_at = NULL WHERE id IN ({$placeholders})");
$stmt->execute($ids);

echo "\nDone. " . count($ids) . " container(s) reset - they'll be picked up and safely re-uploaded\n";
echo "(updated in place, not duplicated) on the next backup run.\n";
