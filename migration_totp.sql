-- Migration: TOTP two-factor authentication for local accounts.
-- Safe to run more than once.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'totp_secret'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE admin_users ADD COLUMN totp_secret VARCHAR(64) NULL AFTER sso_subject',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'totp_enabled'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE admin_users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
