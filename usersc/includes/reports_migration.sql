-- Run this once. Adds the automated report-builder feature.
--
-- Concepts:
--   report_definitions - a saved, reusable report: what data it covers,
--     who gets it, and when it fires (a fixed schedule, a status-change
--     event, or both).
--   report_recipients   - the email list for a report (separate table so
--     it's easy to add/remove people without touching the definition).
--   report_run_log       - a history of every send attempt, for
--     troubleshooting ("why didn't Acme get their Monday report?").

CREATE TABLE IF NOT EXISTS report_definitions (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    name                    VARCHAR(150) NOT NULL,
    description             VARCHAR(255) NULL,
    active                  TINYINT(1) NOT NULL DEFAULT 1,

    delivery_mode           ENUM('scheduled','event','both') NOT NULL DEFAULT 'scheduled',

    -- Event delivery: fires when a container transitions TO this status
    -- (only used when delivery_mode is 'event' or 'both')
    trigger_status          VARCHAR(30) NULL,

    -- Scheduled delivery (only used when delivery_mode is 'scheduled' or 'both')
    schedule_frequency      ENUM('daily','weekly','monthly') NULL,
    schedule_day_of_week    TINYINT NULL,   -- 0=Sunday..6=Saturday, for weekly
    schedule_day_of_month   TINYINT NULL,   -- 1-28, for monthly
    schedule_hour           TINYINT NOT NULL DEFAULT 8, -- 0-23, server time

    -- Content filters — which containers this report covers. NULL/blank = no restriction on that field.
    filter_type             VARCHAR(10) NULL,   -- 'inbound' | 'outbound' | NULL for both
    filter_status            VARCHAR(30) NULL,   -- restrict to containers currently in this status
    filter_customer_id      INT NULL,
    filter_warehouse_id     INT NULL,
    filter_carrier          VARCHAR(120) NULL,

    -- For scheduled reports: which window of containers (by created_at) to include
    date_range               VARCHAR(30) NOT NULL DEFAULT 'since_last_report',

    format                    VARCHAR(20) NOT NULL DEFAULT 'html_table', -- html_table | csv_attachment | both

    last_sent_at              DATETIME NULL,
    created_by                INT NULL,
    created_at                 DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_active (active),
    INDEX idx_trigger_status (trigger_status)
);

CREATE TABLE IF NOT EXISTS report_recipients (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    report_id     INT NOT NULL,
    email         VARCHAR(255) NOT NULL,
    name          VARCHAR(150) NULL,
    INDEX idx_report (report_id)
);

CREATE TABLE IF NOT EXISTS report_run_log (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    report_id        INT NOT NULL,
    trigger_type     VARCHAR(20) NOT NULL,  -- 'scheduled' | 'event'
    container_id     INT NULL,               -- set for event-triggered sends
    container_count  INT NOT NULL DEFAULT 0,
    recipient_count  INT NOT NULL DEFAULT 0,
    success          TINYINT(1) NOT NULL DEFAULT 1,
    error_message    VARCHAR(500) NULL,
    run_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report (report_id),
    INDEX idx_run_at (run_at)
);
