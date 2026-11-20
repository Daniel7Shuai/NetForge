-- Story and sound: the tutorial's characters, background music, and each student's
-- tutorial progress. Import after schema_security.sql. Safe to run again.
USE netforge;

-- People who talk to the player. Staff change names and art on the Characters page; the
-- art (a portrait image, at most 700 KB) is kept here in the database. With no art, the
-- game draws a built-in pixel portrait.
CREATE TABLE IF NOT EXISTS characters (
  code        VARCHAR(32) PRIMARY KEY,           -- used by the dialogue script: teacher, hacker...
  name        VARCHAR(40) NOT NULL,
  role        VARCHAR(80) NOT NULL DEFAULT '',   -- shown under the name, e.g. "Network teacher"
  side        ENUM('left', 'right') NOT NULL DEFAULT 'left', -- which side of the dialogue box they stand on
  art         MEDIUMBLOB NULL,
  art_mime    VARCHAR(32) NULL,
  art_updated TIMESTAMP NULL
) ENGINE=InnoDB;

INSERT INTO characters (code, name, role, side) VALUES
 ('teacher', 'Ada',   'Network teacher', 'left'),
 ('hacker',  'Ghost', 'Hacker',          'right')
ON DUPLICATE KEY UPDATE code = code;

-- Background music. Built-in tracks are composed in code (public/assets/js/music.js), so they're
-- original and free of copyright; staff can upload more (stored in storage/music/, details here).
CREATE TABLE IF NOT EXISTS music_tracks (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind       ENUM('synth', 'file') NOT NULL,
  synth_key  VARCHAR(32) NULL UNIQUE,      -- for built-in tracks: which one in music.js
  title      VARCHAR(80) NOT NULL,
  artist     VARCHAR(80) NOT NULL DEFAULT '',
  licence    VARCHAR(200) NOT NULL DEFAULT '', -- where an upload came from and why it's allowed
  file       VARCHAR(80) NULL,             -- stored file name in storage/music/
  mime       VARCHAR(40) NULL,
  bytes      INT UNSIGNED NULL,
  enabled    TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT NOT NULL DEFAULT 100,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO music_tracks (kind, synth_key, title, artist, licence, sort_order) VALUES
 ('synth', 'garage-boot',  'Garage Boot',     'NetForge', 'Original, composed for NetForge', 10),
 ('synth', 'packet-flow',  'Packet Flow',     'NetForge', 'Original, composed for NetForge', 20),
 ('synth', 'night-shift',  'Night Shift',     'NetForge', 'Original, composed for NetForge', 30),
 ('synth', 'server-hum',   'Server Room Hum', 'NetForge', 'Original, composed for NetForge', 40),
 ('synth', 'firewall',     'Firewall',        'NetForge', 'Original, composed for NetForge', 50)
ON DUPLICATE KEY UPDATE synth_key = synth_key;

-- Tutorial progress: which step a student is on, and whether they finished or skipped it.
ALTER TABLE users ADD COLUMN IF NOT EXISTS tutorial_step SMALLINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS tutorial_done TINYINT(1) NOT NULL DEFAULT 0;

-- Objectives in the order the tutorial teaches them: set up and wire the network first,
-- then the first lesson, and the Garage exam last.
UPDATE objectives SET sort_order = 10 WHERE goal LIKE '%"type":"place"%' AND goal LIKE '%"item":"laptop"%';
UPDATE objectives SET sort_order = 20 WHERE goal LIKE '%"type":"place"%' AND goal LIKE '%"item":"home_router"%';
UPDATE objectives SET sort_order = 30 WHERE goal LIKE '%"type":"link"%' AND goal LIKE '%"a":"isp_box"%';
UPDATE objectives SET sort_order = 40 WHERE goal LIKE '%"type":"link"%' AND goal LIKE '%"a":"laptop"%';
UPDATE objectives SET sort_order = 50, title = 'Open Learn (L) and finish your first lesson' WHERE goal LIKE '%"type":"level"%';
UPDATE objectives SET sort_order = 60, title = 'Pass the Garage exam' WHERE goal LIKE '%"type":"world"%';

-- Students who already have a working network don't need the tutorial forced on them
-- (they can replay it from the menu). Brand-new students start it from the beginning.
UPDATE users u SET tutorial_done = 1 WHERE u.tutorial_done = 0 AND EXISTS (SELECT 1 FROM cables c WHERE c.user_id = u.id);
