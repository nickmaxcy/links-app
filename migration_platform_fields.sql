-- Migration: platform-based dynamic fields.
-- This is the biggest migration this app has had — it changes devices from
-- fixed columns to a platform + JSON-fields model. Read through before
-- running. BACK UP THE DATABASE FIRST:
--   docker exec links-db mysqldump -u root -pYOUR_ROOT_PASSWORD linksdb > pre_platforms_backup.sql
--
-- Safe-by-design: this does NOT drop your existing devices table. It is
-- renamed to devices_old and left in place so nothing is lost if something
-- looks wrong after migrating. Once you've verified everything in the app,
-- you can drop devices_old yourself.

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

-- Rename the old fixed-column table out of the way.
RENAME TABLE devices TO devices_old;

CREATE TABLE devices (
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

-- Migrate existing Zoom rows.
INSERT INTO devices (entity, school, device_name, platform_id, fields, created_at)
SELECT
    d.entity, d.school, d.device_name,
    (SELECT id FROM platforms WHERE platform_key = 'zoom'),
    JSON_OBJECT(
        'meeting_id', d.meeting_id,
        'personal_link', d.personal_link,
        'resource_name', d.resource_name,
        'installed', d.installed
    ),
    d.created_at
FROM devices_old d
WHERE d.device_type = 'zoom';

-- Migrate existing Polycom rows.
INSERT INTO devices (entity, school, device_name, platform_id, fields, created_at)
SELECT
    d.entity, d.school, d.device_name,
    (SELECT id FROM platforms WHERE platform_key = 'polycom'),
    JSON_OBJECT(
        'ip_address', d.ip_address,
        'subnet', d.subnet,
        'gateway', d.gateway,
        'touchscreen_ip', d.touchscreen_ip
    ),
    d.created_at
FROM devices_old d
WHERE d.device_type = 'polycom';
