-- Schema for a fresh deployment. Platforms (Zoom, Teams, Meet, Polycom) are
-- seeded with sensible default fields so there's something to work with
-- immediately; devices/entities/schools start empty. Add your own via
-- /admin/setup.php and /admin/devices.php after first boot, and manage
-- platforms (including adding new ones or editing field lists) via
-- /admin/platforms.php.

CREATE TABLE IF NOT EXISTS platforms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    platform_key VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    field_schema JSON NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO platforms (platform_key, display_name, logo_path, field_schema, sort_order) VALUES
('zoom', 'Zoom', 'platform-logos/zoom.png', JSON_ARRAY(
    JSON_OBJECT('key','meeting_id','label','Meeting ID','type','text','required', false),
    JSON_OBJECT('key','personal_link','label','Personal Link','type','url','required', false,'prefix','https://us02web.zoom.us/my/'),
    JSON_OBJECT('key','resource_name','label','Resource Name','type','text','required', false),
    JSON_OBJECT('key','installed','label','Installed','type','yesno','required', false)
), 1),
('teams', 'Microsoft Teams', 'platform-logos/teams.png', JSON_ARRAY(
    JSON_OBJECT('key','meeting_link','label','Meeting Link','type','url','required', false),
    JSON_OBJECT('key','room_email','label','Room Email','type','text','required', false),
    JSON_OBJECT('key','installed','label','Installed','type','yesno','required', false)
), 2),
('meet', 'Google Meet', 'platform-logos/meet.png', JSON_ARRAY(
    JSON_OBJECT('key','meeting_code','label','Meeting Code','type','text','required', false),
    JSON_OBJECT('key','meeting_link','label','Meeting Link','type','url','required', false),
    JSON_OBJECT('key','installed','label','Installed','type','yesno','required', false)
), 3),
('polycom', 'Polycom', 'platform-logos/polycom.png', JSON_ARRAY(
    JSON_OBJECT('key','ip_address','label','IP Address','type','ip','required', false),
    JSON_OBJECT('key','subnet','label','Subnet Mask','type','text','required', false),
    JSON_OBJECT('key','gateway','label','Gateway','type','ip','required', false),
    JSON_OBJECT('key','touchscreen_ip','label','Touchscreen IP','type','ip','required', false)
), 4);

CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity VARCHAR(100) NOT NULL,
    school VARCHAR(150) NOT NULL,
    device_name VARCHAR(150) NOT NULL,
    platform_id INT NOT NULL,
    fields JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_entity (entity),
    KEY idx_entity_school (entity, school),
    FOREIGN KEY (platform_id) REFERENCES platforms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS schools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    UNIQUE KEY uniq_entity_school (entity_id, name),
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    ip VARCHAR(45) PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    last_attempt DATETIME NULL,
    locked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(50) NOT NULL,
    username VARCHAR(50) NULL,
    details TEXT,
    ip VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NULL,
    password_hash VARCHAR(255) NULL,
    role ENUM('viewer','editor','admin') NOT NULL DEFAULT 'admin',
    sso_connection_id INT NULL,
    sso_subject VARCHAR(255) NULL,
    totp_secret VARCHAR(64) NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    must_reset_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL,
    UNIQUE KEY uniq_sso_identity (sso_connection_id, sso_subject)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sso_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider ENUM('google','microsoft') NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    icon_path VARCHAR(255) NULL,
    client_id VARCHAR(255) NOT NULL,
    client_secret VARCHAR(255) NOT NULL,
    tenant_id VARCHAR(255) NULL,
    allowed_domain VARCHAR(255) NULL,
    default_role ENUM('viewer','editor','admin') NOT NULL DEFAULT 'viewer',
    prefix_rules JSON NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE admin_users ADD CONSTRAINT fk_admin_users_sso_connection FOREIGN KEY (sso_connection_id) REFERENCES sso_connections(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS user_entity_access (
    user_id INT NOT NULL,
    entity_id INT NOT NULL,
    PRIMARY KEY (user_id, entity_id),
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_token_hash (token_hash),
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS totp_attempts (
    user_id INT PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    last_attempt DATETIME NULL,
    locked_until DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_reset_requests (
    ip VARCHAR(45) PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    last_attempt DATETIME NULL,
    locked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings (setting_key, setting_value)
VALUES ('zoom_link_prefix', 'https://us02web.zoom.us/my/');
