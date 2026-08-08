-- Run this once. Adds multi-warehouse support on top of UserSpice tags.
--
-- How it works:
--   1. This creates a `warehouses` table — each row just links a name
--      ("Seattle", "Tacoma", etc.) to an existing UserSpice tag id.
--   2. You create the actual tags in UserSpice as normal (Admin > Users
--      > tag a user, or wherever your install manages tags), then
--      register each one as a warehouse on the new Warehouses admin page.
--   3. Tag users with the matching warehouse tag to scope what they see.
--   4. This adds a nullable `warehouse_id` column to `containers`.
--      Containers with no warehouse set stay visible to everyone.

CREATE TABLE IF NOT EXISTS warehouses (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    tag_id      INT NOT NULL,
    name        VARCHAR(120) NOT NULL,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tag_id (tag_id)
);

-- Add containers.warehouse_id only if it doesn't already exist (safe to re-run).
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'containers' AND COLUMN_NAME = 'warehouse_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE containers ADD COLUMN warehouse_id INT NULL AFTER customer_id, ADD INDEX idx_warehouse (warehouse_id)',
    'SELECT "containers.warehouse_id already exists — skipped"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
