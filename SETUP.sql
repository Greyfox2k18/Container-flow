-- Container Tracking System — Database Setup
-- Run this SQL after installing UserSpice on a fresh server.

-- Main container table
CREATE TABLE IF NOT EXISTS containers (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    container_number  VARCHAR(50) NOT NULL,
    type              ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
    status            ENUM('pending','in_progress','completed','reviewed') NOT NULL DEFAULT 'pending',
    customer_id       INT NULL,
    shipment_number   VARCHAR(50) NULL,
    receipt_ship_date DATE NULL,
    seal_number       VARCHAR(50) NULL,
    po_bol_number     VARCHAR(100) NULL,
    carrier           VARCHAR(100) NULL,
    piece_count       INT NULL,
    notes             TEXT NULL,
    created_by        INT NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_type (type),
    INDEX idx_customer (customer_id),
    INDEX idx_created (created_at)
);

-- Photos
CREATE TABLE IF NOT EXISTS container_photos (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    container_id  INT NOT NULL,
    photo_type    VARCHAR(50) NOT NULL,
    file_path     VARCHAR(500) NOT NULL,
    file_name     VARCHAR(255) NOT NULL,
    description   TEXT NULL,
    uploaded_by   INT NULL,
    uploaded_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_container (container_id),
    INDEX idx_type (photo_type)
);

-- Customers / clients
CREATE TABLE IF NOT EXISTS customers (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(150) NOT NULL,
    inbound_emails      TEXT NULL,
    outbound_emails     TEXT NULL,
    notes               TEXT NULL,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Activity log
CREATE TABLE IF NOT EXISTS container_activity_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    container_id  INT NOT NULL,
    user_id       INT NULL,
    action        VARCHAR(100) NOT NULL,
    details       TEXT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_container (container_id),
    INDEX idx_created (created_at)
);

-- App settings (key-value)
CREATE TABLE IF NOT EXISTS container_settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Client portal user mapping
CREATE TABLE IF NOT EXISTS container_client_users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    customer_id INT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user (user_id),
    INDEX idx_customer (customer_id)
);

-- Per-client photo type requirements
CREATE TABLE IF NOT EXISTS customer_photo_requirements (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT NOT NULL,
    container_type  ENUM('inbound','outbound') NOT NULL,
    photo_type_key  VARCHAR(80) NOT NULL,
    label           VARCHAR(120) NOT NULL,
    sort_order      INT DEFAULT 0,
    INDEX idx_cust_type (customer_id, container_type)
);

-- Upload directory
-- Run: mkdir -p /var/www/container-flow.com/html/usersc/uploads
--      chown -R www-data:www-data /var/www/container-flow.com/html/usersc/uploads
--      chmod -R 775 /var/www/container-flow.com/html/usersc/uploads

-- Cron (run as www-data or the web server user):
-- 0 6 * * * php /var/www/container-flow.com/html/usersc/cron/daily_digest.php >> /var/www/container-flow.com/html/usersc/logs/daily_digest.log 2>&1
