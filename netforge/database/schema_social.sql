-- Social and competition (module 6.0) and weekly challenges (FR-2.5).
-- Import after schema.sql. Weeks start on Monday.
USE netforge;

CREATE TABLE IF NOT EXISTS guilds (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(32) NOT NULL UNIQUE,
  tag        VARCHAR(5) NOT NULL UNIQUE,
  motto      VARCHAR(120) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- A student is in at most one guild.
CREATE TABLE IF NOT EXISTS guild_members (
  user_id   INT UNSIGNED PRIMARY KEY,
  guild_id  INT UNSIGNED NOT NULL,
  role      ENUM('leader','member') NOT NULL DEFAULT 'member',
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_guild (guild_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (guild_id) REFERENCES guilds(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- The guild leader's own weekly goal: correct answers by the guild this week.
CREATE TABLE IF NOT EXISTS guild_goals (
  guild_id   INT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  title      VARCHAR(80) NOT NULL,
  target     SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (guild_id, week_start),
  FOREIGN KEY (guild_id) REFERENCES guilds(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Lecturer-published weekly challenge (the "raid"), FR-2.5 / FR-6.2.
-- world_id NULL means correct answers in any world count.
CREATE TABLE IF NOT EXISTS weekly_challenges (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  week_start     DATE NOT NULL UNIQUE,
  title          VARCHAR(80) NOT NULL,
  description    VARCHAR(255) NOT NULL,
  world_id       INT UNSIGNED NULL,
  target         SMALLINT UNSIGNED NOT NULL,     -- correct answers per guild
  reward_xp      SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- XP each contributor gets when the guild hits the target
  created_by     INT UNSIGNED NULL,
  FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Every correct answer by a guild member adds to that guild's tally for the week.
-- Stored at answer time, so leaving a guild later doesn't move past contributions.
CREATE TABLE IF NOT EXISTS raid_contributions (
  guild_id   INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  world_id   INT UNSIGNED NOT NULL,
  correct    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (guild_id, user_id, week_start, world_id),
  KEY k_week (week_start, guild_id),
  FOREIGN KEY (guild_id) REFERENCES guilds(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One payout per guild per challenge.
CREATE TABLE IF NOT EXISTS raid_rewards (
  guild_id     INT UNSIGNED NOT NULL,
  challenge_id INT UNSIGNED NOT NULL,
  rewarded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (guild_id, challenge_id),
  FOREIGN KEY (guild_id) REFERENCES guilds(id) ON DELETE CASCADE,
  FOREIGN KEY (challenge_id) REFERENCES weekly_challenges(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Guild news: joins, raid wins and milestones members chose to share (FR-6.4).
CREATE TABLE IF NOT EXISTS guild_feed (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  guild_id   INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  kind       ENUM('join','leave','share','raid','goal') NOT NULL,
  message    VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_guild (guild_id, id),
  FOREIGN KEY (guild_id) REFERENCES guilds(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Friends list (FR-6.5). One row per direction; adding someone adds both rows.
CREATE TABLE IF NOT EXISTS friends (
  user_id    INT UNSIGNED NOT NULL,
  friend_id  INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, friend_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
