-- Run this once.
-- Adds a per-client "how many days to keep local photos after backup"
-- setting, used by the new local-cleanup cron (usersc/cron/container_cleanup.php).

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'retention_days'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE customers ADD COLUMN retention_days INT NOT NULL DEFAULT 90',
    'SELECT "customers.retention_days already exists — skipped"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
