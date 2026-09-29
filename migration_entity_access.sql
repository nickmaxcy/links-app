-- Migration: restrict specific (non-admin) users to specific Entities.
-- A user with zero rows here has unrestricted access (backward compatible
-- default). Admins are always unrestricted regardless of rows present.
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS user_entity_access (
    user_id INT NOT NULL,
    entity_id INT NOT NULL,
    PRIMARY KEY (user_id, entity_id),
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
