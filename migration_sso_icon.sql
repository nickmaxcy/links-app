-- Migration: optional icon per SSO connection, shown on its login button.
-- Safe to run more than once.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sso_connections' AND COLUMN_NAME = 'icon_path'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE sso_connections ADD COLUMN icon_path VARCHAR(255) NULL AFTER display_name',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
