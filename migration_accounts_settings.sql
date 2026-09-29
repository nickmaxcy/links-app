-- Migration: user accounts + configurable app settings.
-- Safe to run more than once. Does NOT touch devices, entities, schools,
-- login_attempts, or existing audit_log rows (existing rows simply get a
-- NULL username, since they predate named accounts).

CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings (setting_key, setting_value)
VALUES ('zoom_link_prefix', 'https://us02web.zoom.us/my/');

-- Requires MySQL 8.0.29+ for "ADD COLUMN IF NOT EXISTS" (the mysql:8.0 image
-- pulls a current 8.0.x point release, which has this). If this specific
-- line errors on an older server, drop the "IF NOT EXISTS" and run it once;
-- CREATE/INSERT above are unaffected either way.
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS username VARCHAR(50) NULL AFTER action;
