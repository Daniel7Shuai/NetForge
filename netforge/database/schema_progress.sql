-- Passive income, badges and streaks (FR-4.4, FR-4.5). Import after the other schema files.
USE netforge;

-- Credits per hour a device earns while it is online (linked back to the ISP box):
-- the player rents out their network's capacity. This is the only steady source of money.
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS income_per_hour SMALLINT UNSIGNED NOT NULL DEFAULT 0;
-- When income was last added to the student's balance.
ALTER TABLE users ADD COLUMN IF NOT EXISTS income_at DATETIME NULL;

UPDATE item_types SET income_per_hour = CASE code
  WHEN 'laptop' THEN 10 WHEN 'home_router' THEN 15 WHEN 'pc' THEN 25 WHEN 'switch8' THEN 40
  WHEN 'router' THEN 60 WHEN 'server' THEN 120 ELSE 0 END;

INSERT INTO game_config (name, value, note) VALUES
 ('income_cap_hours', '8', 'Most hours of income that build up while a student is away')
ON DUPLICATE KEY UPDATE note = VALUES(note);

CREATE TABLE IF NOT EXISTS badges (
  code        VARCHAR(24) PRIMARY KEY,
  name        VARCHAR(40) NOT NULL,
  description VARCHAR(160) NOT NULL,
  icon        VARCHAR(40) NOT NULL,      -- Tabler icon name
  sort_order  SMALLINT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_badges (
  user_id   INT UNSIGNED NOT NULL,
  badge     VARCHAR(24) NOT NULL,
  earned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, badge),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (badge) REFERENCES badges(code) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Rules for each badge are in src/Progress/Badges.php (keyed by code).
INSERT INTO badges (code, name, description, icon, sort_order) VALUES
 ('first_boot',   'First boot',      'Set up your laptop on the workbench.',                  'ti-device-laptop',     10),
 ('first_lesson', 'Class is in',     'Complete your first level.',                            'ti-book-2',            20),
 ('online',       'Online',          'Get a device online through the ISP box.',              'ti-world',             30),
 ('shopper',      'Gear up',         'Buy your first piece of equipment.',                    'ti-shopping-cart',     40),
 ('world_master', 'World master',    'Master a world by passing its exam.',                   'ti-certificate',       50),
 ('perfect_exam', 'Flawless',        'Score 100% on a world exam.',                           'ti-star',              60),
 ('blitz_10',     'Bit flipper',     'Get 10 Binary Blitz questions right.',                  'ti-binary',            70),
 ('subnet_10',    'Subnetter',       'Get 10 subnet calculator questions right.',             'ti-sitemap',           80),
 ('auditor',      'Auditor',         'Get 5 Daily Security Audit questions right.',     'ti-shield-check',      90),
 ('cabler',       'Cable guy',       'Have 3 working links at once.',                         'ti-plug-connected',   100),
 ('defender',     'Defender',        'Stop 3 cyber attacks.',                                 'ti-shield-lock',      110),
 ('fortress',     'Fortress',        'Hold a security rating of A after at least 5 attacks.', 'ti-building-castle',  120),
 ('team_player',  'Team player',     'Help your guild complete a weekly raid.',               'ti-users-group',      130),
 ('streak_3',     'On a roll',       'Learn on 3 days in a row.',                             'ti-flame',            140),
 ('streak_7',     'Week warrior',    'Learn on 7 days in a row.',                             'ti-calendar-star',    150)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), icon = VALUES(icon), sort_order = VALUES(sort_order);
