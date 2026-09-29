-- Migration: forced password reset at next login.
-- Safe to run more than once.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admin_users'
      AND COLUMN_NAME = 'must_reset_password'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE admin_users ADD COLUMN must_reset_password TINYINT(1) NOT NULL DEFAULT 0 AFTER role',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
