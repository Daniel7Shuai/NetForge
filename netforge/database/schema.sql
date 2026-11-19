-- NetForge schema (MySQL 8 / MariaDB 10.4+)
CREATE DATABASE IF NOT EXISTS netforge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE netforge;

-- Tunable numbers (starting money, XP curve...) live here, not in code.
CREATE TABLE IF NOT EXISTS game_config (
  name        VARCHAR(64)  PRIMARY KEY,
  value       VARCHAR(255) NOT NULL,
  note        VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(32)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('student','lecturer','admin') NOT NULL DEFAULT 'student',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  credits       INT UNSIGNED NOT NULL DEFAULT 0,
  xp            INT UNSIGNED NOT NULL DEFAULT 0,
  level         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  location      VARCHAR(16) NOT NULL DEFAULT 'garage',  -- key in public/assets/data/layout.json
  pos_x         SMALLINT NOT NULL DEFAULT 6,
  pos_y         SMALLINT NOT NULL DEFAULT 6,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Catalogue of every device and cable type. Ports are described as JSON so new
-- hardware needs only a row, e.g. [{"name":"Fa0/1","kind":"copper"}, ...]
CREATE TABLE IF NOT EXISTS item_types (
  code         VARCHAR(32) PRIMARY KEY,
  name         VARCHAR(64) NOT NULL,
  category     ENUM('device','cable') NOT NULL,
  sprite       VARCHAR(32) NOT NULL,
  label_prefix VARCHAR(16) NULL,       -- placed devices are named "<prefix>-<n>", e.g. PC-1
  slot_kinds   VARCHAR(64) NULL,       -- comma list of layout slot kinds it fits: bench, shelf, desk, rack
  width        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  height       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ports        JSON NULL,
  cable_kind   VARCHAR(16) NULL,
  description  VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_code    VARCHAR(32) NOT NULL,
  quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  price        INT UNSIGNED NOT NULL,
  unlock_level SMALLINT UNSIGNED NOT NULL DEFAULT 1, -- XP level needed to buy it (XP comes from answering questions)
  unlock_world TINYINT UNSIGNED NULL,     -- optional extra: world number that must also be mastered (NULL = none)
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  FOREIGN KEY (item_code) REFERENCES item_types(code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory (
  user_id    INT UNSIGNED NOT NULL,
  item_code  VARCHAR(32) NOT NULL,
  quantity   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, item_code),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (item_code) REFERENCES item_types(code)
) ENGINE=InnoDB;

-- Devices the player has placed on the grid.
CREATE TABLE IF NOT EXISTS devices (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  item_code  VARCHAR(32) NOT NULL,
  label      VARCHAR(32) NOT NULL,
  location   VARCHAR(16) NOT NULL DEFAULT 'garage',
  tile_x     SMALLINT NOT NULL,
  tile_y     SMALLINT NOT NULL,
  fixed      TINYINT(1) NOT NULL DEFAULT 0,   -- part of the building (e.g. ISP box): can't be moved or removed
  UNIQUE KEY uq_label (user_id, label),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (item_code) REFERENCES item_types(code)
) ENGINE=InnoDB;

-- A cable joins one device port to another. The map draws its route from the two
-- devices' positions, so moving a device re-routes its cables automatically.
-- status: up = right cable for these ports; down = plugged in but the wrong cable type.
CREATE TABLE IF NOT EXISTS cables (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  cable_code  VARCHAR(32) NOT NULL,
  a_device_id INT UNSIGNED NOT NULL,
  a_port      VARCHAR(16) NOT NULL,
  b_device_id INT UNSIGNED NOT NULL,
  b_port      VARCHAR(16) NOT NULL,
  status      ENUM('up','down') NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_a (a_device_id, a_port),
  UNIQUE KEY uq_b (b_device_id, b_port),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (a_device_id) REFERENCES devices(id) ON DELETE CASCADE,
  FOREIGN KEY (b_device_id) REFERENCES devices(id) ON DELETE CASCADE,
  FOREIGN KEY (cable_code) REFERENCES item_types(code)
) ENGINE=InnoDB;

-- Missions are data. "goal" is JSON read by the rules engine, e.g.
-- {"type":"link","a":"PC-2","b":"Switch-1","cable":"copper"}
CREATE TABLE IF NOT EXISTS objectives (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sort_order    SMALLINT NOT NULL,
  title         VARCHAR(120) NOT NULL,
  goal          JSON NOT NULL,
  reward_credits INT UNSIGNED NOT NULL DEFAULT 0,
  reward_xp     INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS progress (
  user_id      INT UNSIGNED NOT NULL,
  objective_id INT UNSIGNED NOT NULL,
  completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, objective_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (objective_id) REFERENCES objectives(id) ON DELETE CASCADE
) ENGINE=InnoDB;

/* ===================== Learning (module 2.0 / 3.0) ===================== */

-- Worlds follow the CCNA Introduction to Networks modules.
CREATE TABLE IF NOT EXISTS worlds (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number         TINYINT UNSIGNED NOT NULL UNIQUE,
  title          VARCHAR(80) NOT NULL,
  summary        VARCHAR(255) NOT NULL,
  ccna_modules   VARCHAR(32) NOT NULL,
  exam_questions TINYINT UNSIGNED NOT NULL DEFAULT 10,
  exam_minutes   TINYINT UNSIGNED NOT NULL DEFAULT 10,
  exam_pass_pct  TINYINT UNSIGNED NOT NULL DEFAULT 70,
  archived       TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- A level is one lesson plus a short adaptive challenge run.
CREATE TABLE IF NOT EXISTS levels (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  world_id          INT UNSIGNED NOT NULL,
  sort_order        SMALLINT NOT NULL,
  title             VARCHAR(80) NOT NULL,
  lesson            TEXT NOT NULL,              -- light markdown: paragraphs, "- " lists, **bold**, `code`
  questions_per_run TINYINT UNSIGNED NOT NULL DEFAULT 5,
  pass_pct          TINYINT UNSIGNED NOT NULL DEFAULT 60,
  archived          TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Six challenge types. payload/answer shapes are documented in src/Learning/Grader.php.
-- blitz and subnet rows are generators: each serve makes a fresh question from payload settings.
CREATE TABLE IF NOT EXISTS questions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  world_id    INT UNSIGNED NOT NULL,
  level_id    INT UNSIGNED NULL,
  type        ENUM('mcq','matching','ordering','fill','blitz','subnet') NOT NULL,
  prompt      TEXT NOT NULL,
  payload     JSON NOT NULL,
  answer      JSON NULL,
  explanation TEXT NOT NULL,
  topic       VARCHAR(64) NOT NULL,
  rating      SMALLINT NOT NULL DEFAULT 1000,  -- Elo rating, updated as students answer
  answered    INT UNSIGNED NOT NULL DEFAULT 0,
  archived    TINYINT(1) NOT NULL DEFAULT 0,
  KEY k_level (level_id),
  FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
  FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- A student's Elo rating per world, and whether they've mastered it (passed its exam).
CREATE TABLE IF NOT EXISTS world_progress (
  user_id     INT UNSIGNED NOT NULL,
  world_id    INT UNSIGNED NOT NULL,
  rating      SMALLINT NOT NULL DEFAULT 800,
  answered    INT UNSIGNED NOT NULL DEFAULT 0,
  exam_best   TINYINT UNSIGNED NULL,
  mastered_at TIMESTAMP NULL,
  PRIMARY KEY (user_id, world_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS level_progress (
  user_id      INT UNSIGNED NOT NULL,
  level_id     INT UNSIGNED NOT NULL,
  best_pct     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  completed_at TIMESTAMP NULL,
  PRIMARY KEY (user_id, level_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Every answer, for Elo history and lecturer analytics (module 7.0).
CREATE TABLE IF NOT EXISTS attempts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  question_id   INT UNSIGNED NOT NULL,
  mode          ENUM('practice','exam','audit') NOT NULL,
  correct       TINYINT(1) NOT NULL,
  rating_before SMALLINT NOT NULL,
  rating_after  SMALLINT NOT NULL,
  credits       INT UNSIGNED NOT NULL DEFAULT 0,
  xp            INT UNSIGNED NOT NULL DEFAULT 0,
  seconds       SMALLINT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_user (user_id, created_at),
  KEY k_question (question_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Daily Security Audit: wrong answers come back after 1, 3 and 7 days (FR-3.4).
CREATE TABLE IF NOT EXISTS audit_queue (
  user_id     INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  stage       TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- 1 -> due after 1 day, 2 -> 3 days, 3 -> 7 days
  due_on      DATE NOT NULL,
  PRIMARY KEY (user_id, question_id),
  KEY k_due (user_id, due_on),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS event_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  kind       VARCHAR(16) NOT NULL,
  message    VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_user (user_id, id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
