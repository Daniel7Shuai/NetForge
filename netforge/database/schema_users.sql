-- User management (module 1.0): email for sign-up and password reset (FR-1.1, FR-1.3),
-- display name and avatar (FR-1.4), admin account management (FR-1.5).
-- Import after schema_mock.sql.
USE netforge;

ALTER TABLE users ADD COLUMN IF NOT EXISTS email        VARCHAR(190) NULL AFTER username;
ALTER TABLE users ADD COLUMN IF NOT EXISTS display_name VARCHAR(40)  NULL AFTER email;
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar       VARCHAR(16)  NOT NULL DEFAULT 'cyan' AFTER display_name; -- key in AVATARS (art.js / Profile.php)
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_email ON users (email);

-- One-time password reset links (FR-1.3). Only a SHA-256 of the token is stored,
-- so a leaked table can't be used to reset anyone's password.
CREATE TABLE IF NOT EXISTS password_resets (
  token_hash CHAR(64) PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_by INT UNSIGNED NULL,             -- an admin who made the link, or NULL if the user asked
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- What admins changed, and to whom (FR-1.5).
CREATE TABLE IF NOT EXISTS admin_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id   INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  action     VARCHAR(160) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id, created_at),
  FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO game_config (name, value, note) VALUES ('reset_minutes', '30', 'How long a password reset link works')
ON DUPLICATE KEY UPDATE name = name;
