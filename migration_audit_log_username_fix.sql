-- Adds the username column to audit_log without relying on the
-- "ADD COLUMN IF NOT EXISTS" syntax (only supported on MySQL 8.0.29+).
-- Safe to run more than once.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'audit_log'
      AND COLUMN_NAME = 'username'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE audit_log ADD COLUMN username VARCHAR(50) NULL AFTER action',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
