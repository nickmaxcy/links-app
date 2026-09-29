-- Migration: per-connection email-prefix rules for SSO (restrict which
-- local-parts may sign in, and assign a role per matching prefix).
-- Safe to run more than once.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sso_connections' AND COLUMN_NAME = 'prefix_rules'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE sso_connections ADD COLUMN prefix_rules JSON NULL AFTER default_role',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
