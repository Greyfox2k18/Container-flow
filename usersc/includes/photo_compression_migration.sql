-- Run this once.
-- Tracks which photos have already been compressed, so a repeat scan
-- never re-compresses the same file (JPEG recompression is lossy and
-- quality degrades a little more each time it's re-applied).

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'container_photos' AND COLUMN_NAME = 'compressed_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE container_photos ADD COLUMN compressed_at DATETIME NULL',
    'SELECT "container_photos.compressed_at already exists — skipped"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
