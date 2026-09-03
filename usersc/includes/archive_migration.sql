-- Run this once.
-- Adds a two-stage lifecycle: containers get ARCHIVED first (hidden from
-- the simple dashboard, still visible on the Pro dashboard, fully
-- reversible), and only actually DELETED locally after a further grace
-- period. Replaces the previous single-stage "delete after retention_days"
-- design from cleanup_migration.sql with something safer.

-- When a container was archived. NULL = not archived (normal/active).
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'containers' AND COLUMN_NAME = 'archived_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE containers ADD COLUMN archived_at DATETIME NULL, ADD INDEX idx_archived_at (archived_at)',
    'SELECT "containers.archived_at already exists — skipped"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- How many days AFTER being archived before local deletion. retention_days
-- (added earlier) now means "archive after this many days" - this new
-- column is the second, further threshold for the actually-destructive step.
SET @col_exists2 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'delete_after_days'
);
SET @sql = IF(@col_exists2 = 0,
    'ALTER TABLE customers ADD COLUMN delete_after_days INT NOT NULL DEFAULT 30',
    'SELECT "customers.delete_after_days already exists — skipped"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
