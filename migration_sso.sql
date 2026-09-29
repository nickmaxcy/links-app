-- Migration: multi-connection SSO (Google / Microsoft).
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS sso_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider ENUM('google','microsoft') NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    client_id VARCHAR(255) NOT NULL,
    client_secret VARCHAR(255) NOT NULL,
    tenant_id VARCHAR(255) NULL,
    allowed_domain VARCHAR(255) NULL,
    default_role ENUM('viewer','editor','admin') NOT NULL DEFAULT 'viewer',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- password_hash must become nullable: an SSO-only account has no local password.
ALTER TABLE admin_users MODIFY password_hash VARCHAR(255) NULL;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'sso_connection_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE admin_users ADD COLUMN sso_connection_id INT NULL AFTER role',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'sso_subject'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE admin_users ADD COLUMN sso_subject VARCHAR(255) NULL AFTER sso_connection_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND INDEX_NAME = 'uniq_sso_identity'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE admin_users ADD UNIQUE KEY uniq_sso_identity (sso_connection_id, sso_subject)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND CONSTRAINT_NAME = 'fk_admin_users_sso_connection'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE admin_users ADD CONSTRAINT fk_admin_users_sso_connection FOREIGN KEY (sso_connection_id) REFERENCES sso_connections(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
