-- Run this once.
-- Lets a client have a DIFFERENT notification email list for loads
-- handled by a specific warehouse, instead of always using their one
-- default inbound/outbound list. A warehouse with no override here
-- simply falls back to the client's existing default emails - nothing
-- breaks for clients who don't need this.

CREATE TABLE IF NOT EXISTS customer_warehouse_emails (
    id                             INT AUTO_INCREMENT PRIMARY KEY,
    customer_id                    INT NOT NULL,
    warehouse_id                   INT NOT NULL,
    notification_emails_inbound    TEXT NULL,
    notification_emails_outbound   TEXT NULL,
    UNIQUE KEY uk_customer_warehouse (customer_id, warehouse_id)
);
