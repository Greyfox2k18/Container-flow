<?php
/**
 * Two-phase container lifecycle cleanup:
 *
 *   PHASE 1 - ARCHIVE (safe, reversible, runs for real every time - not
 *   gated by CLEANUP_LIVE_MODE): once a container is Reviewed, backed up
 *   to Drive with nothing unbacked-up since, and older than its client's
 *   "archive after" days, it gets archived - hidden from the simple
 *   dashboard, still fully visible (and restorable) on the Pro dashboard.
 *   Nothing is deleted. See archiveContainer()/unarchiveContainer() in
 *   container_functions.php.
 *
 *   PHASE 2 - DELETE (irreversible, gated by CLEANUP_LIVE_MODE, defaults
 *   to dry run): once a container has been archived for its client's
 *   "delete after archiving" days, its local photos and record are
 *   permanently removed. The Drive backup is never touched.
 *
 * Used by both usersc/cron/container_cleanup.php (the nightly cron) and
 * usersc/ajax/container_cleanup_run.php (the manual "Run Cleanup Now"
 * button), same pattern as drive_backup_core.php.
 *
 * Takes a plain PDO connection, same reasoning as drive_backup_core.php -
 * the cron entry point runs outside the normal web request lifecycle.
 */

/**
 * Containers eligible to be ARCHIVED right now (not yet archived, but
 * past their client's archive threshold and safely backed up).
 */
function getArchiveCandidates($pdo) {
    $sql = "SELECT c.*,
            cu.name AS customer_name,
            COALESCE(cu.retention_days, 90) AS effective_retention_days,
            DATEDIFF(NOW(), c.created_at) AS age_days,
            (SELECT MAX(uploaded_at) FROM container_photos WHERE container_id = c.id) AS latest_photo_at
            FROM containers c
            LEFT JOIN customers cu ON c.customer_id = cu.id
            WHERE c.archived_at IS NULL
              AND c.status = 'reviewed'
              AND c.drive_backed_up_at IS NOT NULL
              AND c.drive_folder_link IS NOT NULL
              AND c.drive_folder_link != ''
              AND (
                    (SELECT MAX(uploaded_at) FROM container_photos WHERE container_id = c.id) IS NULL
                    OR (SELECT MAX(uploaded_at) FROM container_photos WHERE container_id = c.id) <= c.drive_backed_up_at
                  )
              AND DATEDIFF(NOW(), c.created_at) >= COALESCE(cu.retention_days, 90)
            ORDER BY c.created_at ASC";

    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

/**
 * Containers eligible for permanent local DELETION right now (already
 * archived, and past their client's delete-after-archiving threshold).
 */
function getDeleteCandidates($pdo) {
    $sql = "SELECT c.*,
            cu.name AS customer_name,
            COALESCE(cu.delete_after_days, 30) AS effective_delete_after_days,
            DATEDIFF(NOW(), c.archived_at) AS days_since_archived
            FROM containers c
            LEFT JOIN customers cu ON c.customer_id = cu.id
            WHERE c.archived_at IS NOT NULL
              AND DATEDIFF(NOW(), c.archived_at) >= COALESCE(cu.delete_after_days, 30)
            ORDER BY c.archived_at ASC";

    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

/**
 * Deletes one container's local photo files, container_photos rows, and
 * the container row itself. Returns ['success' => bool, 'log' => string[]].
 * File-unlink failures are logged as warnings but don't block the DB
 * cleanup - if a file's already gone, that's fine, it's backed up either way.
 */
function deleteContainerLocalData($pdo, $container, $site_root) {
    $log = [];

    $photo_stmt = $pdo->prepare("SELECT * FROM container_photos WHERE container_id = ?");
    $photo_stmt->execute([$container->id]);
    $photos = $photo_stmt->fetchAll(PDO::FETCH_OBJ);

    $deleted_files = 0;
    $missing_files = 0;
    $photo_dir = null;

    foreach ($photos as $photo) {
        $file_path = rtrim($site_root, '/') . '/' . $photo->file_path;
        if ($photo_dir === null) {
            $photo_dir = dirname($file_path);
        }
        if (file_exists($file_path)) {
            if (@unlink($file_path)) {
                $deleted_files++;
            } else {
                $log[] = "  - WARNING: could not delete file {$file_path} (permissions?)";
            }
        } else {
            $missing_files++;
        }
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM container_photos WHERE container_id = ?")->execute([$container->id]);
        $pdo->prepare("DELETE FROM containers WHERE id = ?")->execute([$container->id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        $log[] = "  - FAILED: database cleanup error for container #{$container->id}: " . $e->getMessage();
        return ['success' => false, 'log' => $log];
    }

    // Best-effort: remove the now-empty upload directory. Harmless if it
    // fails (not empty, already gone, permissions) - just cosmetic.
    if ($photo_dir && is_dir($photo_dir)) {
        @rmdir($photo_dir);
    }

    $log[] = "OK: Container #{$container->id} ({$container->container_number}) deleted locally - "
        . "{$deleted_files} file(s) removed" . ($missing_files ? ", {$missing_files} already missing" : '')
        . " - Drive backup preserved: " . $container->drive_folder_link;

    return ['success' => true, 'log' => $log];
}

/**
 * Runs one full cleanup pass: archives everything currently eligible
 * (always for real - archiving is reversible), then deletes anything
 * past the archived+delete_after_days threshold (only for real if
 * $dry_run is false - ALWAYS start with dry_run true).
 *
 * $max_delete_per_run is a circuit breaker on the DELETE phase only -
 * archiving has no cap since it's non-destructive.
 *
 * @return array ['success','log','archived_count','deleted_count','dry_run','delete_candidate_count']
 */
function runContainerCleanup($pdo, $site_root, $dry_run = true, $max_delete_per_run = 25) {
    $log = [];
    $log[] = '[' . date('Y-m-d H:i:s') . '] Starting cleanup run (delete phase: ' . ($dry_run ? 'DRY RUN' : 'LIVE') . ')';

    // ── Phase 1: Archive ────────────────────────────────────────────────
    $archive_candidates = getArchiveCandidates($pdo);
    $log[] = 'Archive phase: ' . count($archive_candidates) . ' container(s) eligible';
    $archived_count = 0;

    foreach ($archive_candidates as $container) {
        $client_name = $container->customer_name ?: 'No Client';
        try {
            $pdo->prepare("UPDATE containers SET archived_at = NOW() WHERE id = ?")->execute([$container->id]);
            $log[] = "ARCHIVED: Container #{$container->id} ({$container->container_number}), client: {$client_name}, "
                . "age: {$container->age_days} day(s) (threshold: {$container->effective_retention_days})";
            $archived_count++;
        } catch (\Throwable $e) {
            $log[] = "  - FAILED to archive container #{$container->id}: " . $e->getMessage();
        }
    }

    // ── Phase 2: Delete ─────────────────────────────────────────────────
    $delete_candidates = getDeleteCandidates($pdo);
    $log[] = 'Delete phase: ' . count($delete_candidates) . ' container(s) eligible';

    if (count($delete_candidates) > $max_delete_per_run) {
        $log[] = "NOTE: capping delete phase to the oldest {$max_delete_per_run} (of " . count($delete_candidates) . ") - remaining will process on a later run";
        $delete_candidates = array_slice($delete_candidates, 0, $max_delete_per_run);
    }

    $deleted_count = 0;

    foreach ($delete_candidates as $container) {
        $client_name = $container->customer_name ?: 'No Client';
        if ($dry_run) {
            $log[] = "[DRY RUN] Would permanently delete: Container #{$container->id} ({$container->container_number}), "
                . "client: {$client_name}, archived {$container->days_since_archived} day(s) ago (threshold: {$container->effective_delete_after_days})";
            continue;
        }

        $result = deleteContainerLocalData($pdo, $container, $site_root);
        $log = array_merge($log, $result['log']);
        if ($result['success']) {
            $deleted_count++;
        }
    }

    $log[] = '[' . date('Y-m-d H:i:s') . "] Cleanup run complete: {$archived_count} archived, " . ($dry_run
        ? count($delete_candidates) . ' would have been deleted (dry run - nothing actually removed)'
        : "{$deleted_count} deleted");

    return [
        'success' => true,
        'log' => $log,
        'archived_count' => $archived_count,
        'deleted_count' => $deleted_count,
        'dry_run' => $dry_run,
        'delete_candidate_count' => count($delete_candidates),
    ];
}

/**
 * Appends a cleanup run's log lines to the log file, creating the logs
 * directory first if it doesn't exist yet. Mirrors writeDriveBackupLog().
 */
function writeCleanupLog($log_lines) {
    $log_file = defined('CLEANUP_LOG_FILE') ? CLEANUP_LOG_FILE : __DIR__ . '/../logs/container_cleanup.log';
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    $text = implode("\n", $log_lines) . "\n";
    $written = @file_put_contents($log_file, $text, FILE_APPEND);
    if ($written === false) {
        error_log("container_cleanup: could not write to log file {$log_file} - check directory permissions/ownership");
    }
    return $text;
}
