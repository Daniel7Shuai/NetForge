-- Security fixes from the review. Import after schema_economy.sql.
USE netforge;

-- Failed sign-ins, so password guessing gets slowed down (Auth::lockedOut).
-- The username is stored as typed (lower-cased), whether or not the account exists,
-- so the lock can't be used to find out which accounts are real.
CREATE TABLE IF NOT EXISTS login_failures (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(190) NOT NULL,
  ip         VARCHAR(45)  NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (username, created_at),
  KEY (ip, created_at)
) ENGINE=InnoDB;
