-- Mock exams (FR-3.5): a timed exam built from the worlds a student picks,
-- scored with a breakdown by topic. Import after schema_upgrades.sql.
USE netforge;

ALTER TABLE attempts MODIFY mode ENUM('practice','exam','audit','mock') NOT NULL;

-- One row per finished mock exam, so students can compare attempts.
CREATE TABLE IF NOT EXISTS mock_exams (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  worlds     VARCHAR(64) NOT NULL,          -- world numbers, e.g. "0,2,5"
  total      TINYINT UNSIGNED NOT NULL,
  correct    TINYINT UNSIGNED NOT NULL,
  pct        TINYINT UNSIGNED NOT NULL,
  seconds    SMALLINT UNSIGNED NOT NULL,    -- time taken
  timed_out  TINYINT(1) NOT NULL DEFAULT 0,
  topics     JSON NOT NULL,                 -- [{topic, correct, total}]
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
