-- Yard Board — door & yard tracking (replaces the T-Card Google Sheet).
-- OPTIONAL: usersc/includes/yard_functions.php creates these automatically
-- (ensureYardTables) the first time yard_board.php is opened. Run this by
-- hand only if your DB user can't CREATE/ALTER at runtime.

CREATE TABLE IF NOT EXISTS yard_locations (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_id  INT NULL,
    code          VARCHAR(20) NOT NULL,
    kind          ENUM('door','yard') NOT NULL DEFAULT 'yard',
    sort_order    INT NOT NULL DEFAULT 0,
    active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wh (warehouse_id)
);

CREATE TABLE IF NOT EXISTS yard_units (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_id       INT NULL,
    location_id        INT NULL,
    container_number   VARCHAR(50) NOT NULL,
    container_id       INT NULL,
    customer_id        INT NULL,
    account            VARCHAR(100) NULL,
    status             VARCHAR(20) NOT NULL DEFAULT 'Expected',
    hot                TINYINT(1) NOT NULL DEFAULT 0,
    date_in            DATE NULL,
    mt_date            DATE NULL,
    ld_date            DATE NULL,
    driver             VARCHAR(100) NULL,
    lfd                DATE NULL,
    drayman            VARCHAR(100) NULL,
    notes              TEXT NULL,
    eta                DATE NULL,
    on_list            TINYINT(1) NOT NULL DEFAULT 0,
    list_note          VARCHAR(100) NULL,
    last_location_code VARCHAR(20) NULL,
    picked_up_at       DATETIME NULL,
    picked_up_by       INT NULL,
    checked_at         DATETIME NULL,
    checked_by         INT NULL,
    created_by         INT NULL,
    updated_by         INT NULL,
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_location (location_id),
    INDEX idx_wh (warehouse_id),
    INDEX idx_number (container_number),
    INDEX idx_picked (picked_up_at)
);

CREATE TABLE IF NOT EXISTS yard_events (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    unit_id          INT NULL,
    warehouse_id     INT NULL,
    user_id          INT NULL,
    action           VARCHAR(30) NOT NULL,
    container_number VARCHAR(50) NULL,
    from_code        VARCHAR(20) NULL,
    to_code          VARCHAR(20) NULL,
    details          TEXT NULL,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unit (unit_id),
    INDEX idx_wh_created (warehouse_id, created_at)
);

ALTER TABLE customers ADD COLUMN yard_color VARCHAR(7) NULL;

-- Upgrading an install that already has yard_units from the first version:
-- ALTER TABLE yard_units ADD COLUMN on_list TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN list_note VARCHAR(100) NULL;
