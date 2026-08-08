-- Run this once.
--
-- Problem this fixes: `containers.container_number` has a hard UNIQUE KEY,
-- which permanently blocks reusing a number even after that container is
-- long done — a real problem for clients who ship on their own reused
-- trailers. A database constraint can't be made conditional ("unique only
-- while open"), so this drops it and Container Flow now enforces
-- uniqueness in the application instead, scoped to open containers only
-- (anything not yet marked Reviewed).
--
-- For clients whose freight reuses trailer numbers, you can flag their
-- customer record to use Shipment Number as the real unique identifier
-- instead of Container Number — see usersc/customer_identifier_settings.php.

-- Drop the old hard constraint (safe to re-run — only drops if present).
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'containers' AND INDEX_NAME = 'container_number'
);
SET @sql = IF(@idx_exists > 0,
    'ALTER TABLE containers DROP INDEX container_number',
    'SELECT "containers.container_number unique index already absent — skipped"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add the per-customer flag (safe to re-run).
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'use_shipment_number_as_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE customers ADD COLUMN use_shipment_number_as_id TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT "customers.use_shipment_number_as_id already exists — skipped"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- A plain (non-unique) index still helps duplicate-check query speed on both fields.
SET @idx_exists2 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'containers' AND INDEX_NAME = 'idx_container_number_lookup'
);
SET @sql = IF(@idx_exists2 = 0,
    'ALTER TABLE containers ADD INDEX idx_container_number_lookup (container_number)',
    'SELECT "idx_container_number_lookup already exists — skipped"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists3 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'containers' AND INDEX_NAME = 'idx_shipment_number_lookup'
);
SET @sql = IF(@idx_exists3 = 0,
    'ALTER TABLE containers ADD INDEX idx_shipment_number_lookup (shipment_number)',
    'SELECT "idx_shipment_number_lookup already exists — skipped"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
