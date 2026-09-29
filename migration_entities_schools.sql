-- One-time migration for an existing links-app database.
-- Safe to run more than once (uses IF NOT EXISTS / INSERT IGNORE).
-- Does NOT touch or delete anything in the devices table.

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

INSERT IGNORE INTO entities (name)
SELECT DISTINCT entity FROM devices;

INSERT IGNORE INTO schools (entity_id, name)
SELECT e.id, d.school
FROM (SELECT DISTINCT entity, school FROM devices) d
JOIN entities e ON e.name = d.entity;
