<?php
/**
 * Photo compression - shrinks stored container photos to save disk space.
 * Same underlying approach as resizeImageForEmail() in container_functions.php
 * (EXIF-rotation-aware GD resize), but this WRITES the result back to the
 * original file path instead of producing a temporary email attachment.
 *
 * Used by usersc/cron/photo_compression_scan.php (dry-run or live, gated
 * by PHOTO_COMPRESSION_LIVE_MODE) and read by
 * usersc/photo_compression_preview.php (which only ever displays the last
 * scan's report - it doesn't trigger a scan itself, to avoid a web
 * request timing out over a large photo catalog).
 *
 * IMPORTANT: JPEG recompression is lossy and compounds if re-applied -
 * quality degrades a little more each time. Every photo this touches
 * gets container_photos.compressed_at set, and every function here skips
 * anything already marked compressed, so a photo is only ever
 * compressed once, period - never re-touched on a later scan.
 *
 * Takes a plain PDO connection, same reasoning as drive_backup_core.php -
 * the cron entry point runs outside the normal web request lifecycle.
 */

/**
 * Resizes+recompresses one image file, either simulating (dry run - no
 * write, just measures what the result WOULD be) or actually doing it.
 * Preserves the original format (JPEG stays JPEG, PNG stays PNG) so
 * file_path/mime-type detection elsewhere never breaks. GIFs are skipped
 * entirely - lossless recompression of a GIF rarely saves much, and
 * animated GIFs would be destroyed by this approach.
 *
 * Returns ['original_size' => int, 'new_size' => int, 'skipped_reason' => string|null]
 */
function compressStoredImage($file_path, $max_dim, $quality, $dry_run = true) {
    if (!file_exists($file_path)) {
        return ['original_size' => 0, 'new_size' => 0, 'skipped_reason' => 'file missing'];
    }
    $original_size = filesize($file_path);

    if (!function_exists('imagecreatefromjpeg')) {
        return ['original_size' => $original_size, 'new_size' => $original_size, 'skipped_reason' => 'GD not available'];
    }

    $info = @getimagesize($file_path);
    if (!$info) {
        return ['original_size' => $original_size, 'new_size' => $original_size, 'skipped_reason' => 'not a readable image'];
    }
    [$orig_w, $orig_h] = $info;
    $mime = $info['mime'];

    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return ['original_size' => $original_size, 'new_size' => $original_size, 'skipped_reason' => 'unsupported format (' . $mime . ')'];
    }

    // Nothing to gain if it's already smaller than the target dimension
    if ($orig_w <= $max_dim && $orig_h <= $max_dim) {
        return ['original_size' => $original_size, 'new_size' => $original_size, 'skipped_reason' => 'already within target dimensions'];
    }

    $orientation = 1;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file_path);
        if ($exif && isset($exif['Orientation'])) $orientation = (int) $exif['Orientation'];
    }

    $src = $mime === 'image/jpeg' ? @imagecreatefromjpeg($file_path) : @imagecreatefrompng($file_path);
    if (!$src) {
        return ['original_size' => $original_size, 'new_size' => $original_size, 'skipped_reason' => 'GD could not read file'];
    }

    $src = match ($orientation) {
        2 => (imageflip($src, IMG_FLIP_HORIZONTAL) ? $src : $src),
        3 => imagerotate($src, 180, 0),
        4 => (imageflip($src, IMG_FLIP_VERTICAL) ? $src : $src),
        6 => imagerotate($src, -90, 0),
        8 => imagerotate($src, 90, 0),
        default => $src,
    };

    $w = imagesx($src);
    $h = imagesy($src);
    $ratio = min($max_dim / $w, $max_dim / $h);
    $new_w = max(1, (int) round($w * $ratio));
    $new_h = max(1, (int) round($h * $ratio));

    $dst = imagecreatetruecolor($new_w, $new_h);
    if ($mime === 'image/png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $w, $h);

    if ($dry_run) {
        // Measure without touching the real file - render to a memory buffer
        ob_start();
        if ($mime === 'image/jpeg') {
            imagejpeg($dst, null, $quality);
        } else {
            imagepng($dst, null, 6);
        }
        $buffer = ob_get_clean();
        $new_size = strlen($buffer);
    } else {
        if ($mime === 'image/jpeg') {
            imagejpeg($dst, $file_path, $quality);
        } else {
            imagepng($dst, $file_path, 6);
        }
        clearstatcache(true, $file_path);
        $new_size = filesize($file_path);
    }

    imagedestroy($src);
    imagedestroy($dst);

    return ['original_size' => $original_size, 'new_size' => $new_size, 'skipped_reason' => null];
}

/**
 * Scans every not-yet-compressed photo, either simulating (dry run) or
 * actually compressing (live). Writes a JSON report to
 * PHOTO_COMPRESSION_REPORT_FILE that the preview page reads - this can
 * take a while over a large catalog, so it's meant to run via CLI
 * (usersc/cron/photo_compression_scan.php), not a web request.
 *
 * @return array ['photos_scanned','photos_compressed','photos_skipped','original_bytes','new_bytes','skipped_reasons' => [...]]
 */
function runPhotoCompressionScan($pdo, $site_root, $max_dim, $quality, $dry_run = true, $progress_callback = null) {
    $stmt = $pdo->query("SELECT id, container_id, file_path, file_name FROM container_photos WHERE compressed_at IS NULL");
    $photos = $stmt->fetchAll(PDO::FETCH_OBJ);
    $total_to_scan = count($photos);

    $scanned = 0;
    $compressed = 0;
    $original_bytes = 0;
    $new_bytes = 0;
    $skipped_reasons = [];
    $largest = []; // top offenders, for the preview page

    foreach ($photos as $photo) {
        $scanned++;
        $file_path = rtrim($site_root, '/') . '/' . $photo->file_path;
        $result = compressStoredImage($file_path, $max_dim, $quality, $dry_run);

        $original_bytes += $result['original_size'];
        $new_bytes += $result['new_size'];

        if ($result['skipped_reason']) {
            $skipped_reasons[$result['skipped_reason']] = ($skipped_reasons[$result['skipped_reason']] ?? 0) + 1;
        } else {
            $compressed++;
            $largest[] = [
                'file_name' => $photo->file_name,
                'container_id' => $photo->container_id,
                'original_kb' => round($result['original_size'] / 1024),
                'new_kb' => round($result['new_size'] / 1024),
                'saved_kb' => round(($result['original_size'] - $result['new_size']) / 1024),
            ];

            if (!$dry_run) {
                $pdo->prepare("UPDATE container_photos SET compressed_at = NOW() WHERE id = ?")->execute([$photo->id]);
            }
        }

        if ($progress_callback && ($scanned % 25 === 0 || $scanned === $total_to_scan)) {
            $progress_callback($scanned, $total_to_scan, $original_bytes - $new_bytes);
        }
    }

    usort($largest, fn($a, $b) => $b['saved_kb'] - $a['saved_kb']);

    return [
        'dry_run' => $dry_run,
        'ran_at' => date('Y-m-d H:i:s'),
        'photos_scanned' => $scanned,
        'photos_compressed' => $compressed,
        'skipped_reasons' => $skipped_reasons,
        'original_bytes' => $original_bytes,
        'new_bytes' => $new_bytes,
        'saved_bytes' => $original_bytes - $new_bytes,
        'top_savers' => array_slice($largest, 0, 20),
    ];
}

function writePhotoCompressionReport($report) {
    $file = defined('PHOTO_COMPRESSION_REPORT_FILE') ? PHOTO_COMPRESSION_REPORT_FILE : __DIR__ . '/../logs/photo_compression_report.json';
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT));
}

function readPhotoCompressionReport() {
    $file = defined('PHOTO_COMPRESSION_REPORT_FILE') ? PHOTO_COMPRESSION_REPORT_FILE : __DIR__ . '/../logs/photo_compression_report.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return $data ?: null;
}
