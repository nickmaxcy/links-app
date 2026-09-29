-- Migration: lock out repeated failed 2FA code attempts, mirroring the
-- existing login_attempts pattern. Keyed by user_id since 2FA verification
-- is already tied to one specific pending account.
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS totp_attempts (
    user_id INT PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    last_attempt DATETIME NULL,
    locked_until DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Also throttles repeated Forgot Password requests per IP, to prevent
-- spamming a real user's inbox or burning through the SMTP send quota.
CREATE TABLE IF NOT EXISTS password_reset_requests (
    ip VARCHAR(45) PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    last_attempt DATETIME NULL,
    locked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
