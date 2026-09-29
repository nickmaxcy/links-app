-- Migration: role-based access (viewer / editor / admin).
-- Safe to run more than once. Existing accounts default to 'admin' so
-- nobody who currently has full access loses it as a side effect of this
-- migration — only newly created accounts get an explicit role choice.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admin_users'
      AND COLUMN_NAME = 'role'
);

SET @sql := IF(
    @col_exists = 0,
    "ALTER TABLE admin_users ADD COLUMN role ENUM('viewer','editor','admin') NOT NULL DEFAULT 'admin' AFTER password_hash",
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
