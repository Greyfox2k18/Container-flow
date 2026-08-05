-- Run this once. It creates the table that stores your editable
-- inbound / outbound completion-notification email templates.
-- If a row for a template_key is missing or has an empty html_body,
-- the code falls back to the built-in default automatically, so this
-- migration is safe to run even before you've customized anything.

CREATE TABLE IF NOT EXISTS email_templates (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(20) NOT NULL,
    subject      VARCHAR(255) NOT NULL,
    html_body    MEDIUMTEXT NOT NULL,
    updated_by   INT DEFAULT NULL,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_template_key (template_key)
);
