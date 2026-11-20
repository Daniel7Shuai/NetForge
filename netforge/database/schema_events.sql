-- Shop categories, furniture, GPUs and their sellers, the characters' tips along the way,
-- and random events (the grey-market broker and investor texts). Import after schema_story.sql.
-- Safe to run again.
USE netforge;

/* ---------- shop categories (like Packet Tracer's device groups) ---------- */
ALTER TABLE item_types MODIFY category ENUM('device', 'cable', 'part', 'furniture') NOT NULL;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS shop_group VARCHAR(16) NOT NULL DEFAULT 'end'; -- end, network, security, gpu, cable, furniture
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS furniture_type VARCHAR(16) NULL;   -- furniture: which piece it builds (layout footprints)
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS boost_pct SMALLINT UNSIGNED NOT NULL DEFAULT 0; -- GPU cards: extra % income for the server they go in

UPDATE item_types SET shop_group = 'end'      WHERE code IN ('laptop', 'pc', 'server');
UPDATE item_types SET shop_group = 'network'  WHERE code IN ('home_router', 'switch8', 'router', 'isp_box', 'isp_fiber');
UPDATE item_types SET shop_group = 'security' WHERE code = 'firewall';
UPDATE item_types SET shop_group = 'cable'    WHERE category = 'cable';

/* ---------- furniture: buying it adds spots to put gear ---------- */
INSERT INTO item_types (code, name, category, sprite, label_prefix, slot_kinds, width, height, ports, cable_kind, description, income_per_sec, max_tier, shield_pct, shop_group, furniture_type) VALUES
 ('desk_kit',    'Office desk', 'furniture', 'desk', NULL, NULL, 3, 2, '[]', NULL, 'A desk with a spot beside it for a PC. Place it on open floor.', 0, 1, 0, 'furniture', 'desk'),
 ('server_rack', 'Server rack', 'furniture', 'rack', NULL, NULL, 1, 2, '[]', NULL, 'An empty rack with room for one rack device. Place it on open floor.', 0, 1, 0, 'furniture', 'rack')
ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), description = VALUES(description), shop_group = VALUES(shop_group), furniture_type = VALUES(furniture_type);

CREATE TABLE IF NOT EXISTS user_furniture (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  item_code  VARCHAR(32) NOT NULL,
  location   VARCHAR(16) NOT NULL,
  tile_x     SMALLINT NOT NULL,
  tile_y     SMALLINT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id, location),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

/* ---------- GPUs: rigs that earn, and cards that boost a server ---------- */
-- Three sellers sell each GPU: cheap and slower, standard, or premium and faster.
-- Their prices and stock change every 20 minutes (Market.php).
ALTER TABLE devices ADD COLUMN IF NOT EXISTS gpu_card VARCHAR(32) NULL; -- the GPU card installed in a server, if any
ALTER TABLE shop_items ADD COLUMN IF NOT EXISTS seller VARCHAR(40) NULL;

INSERT INTO item_types (code, name, category, sprite, label_prefix, slot_kinds, width, height, ports, cable_kind, description, income_per_sec, max_tier, shield_pct, shop_group, boost_pct) VALUES
 ('gpu_rig_budget', 'GPU rig (budget)',  'device', 'gpurig', 'GPU', 'rack', 1, 2, '[{"name":"Eth0","kind":"copper","role":"mdi"},{"name":"Gi0/0","kind":"fiber"}]', NULL, 'Rents out AI compute time. A cheaper build: 80% of the standard speed.', 48000, 3, 0, 'gpu', 0),
 ('gpu_rig_std',    'GPU rig',           'device', 'gpurig', 'GPU', 'rack', 1, 2, '[{"name":"Eth0","kind":"copper","role":"mdi"},{"name":"Gi0/0","kind":"fiber"}]', NULL, 'Rents out AI compute time. Earns a lot once it is online.', 60000, 3, 0, 'gpu', 0),
 ('gpu_rig_pro',    'GPU rig (pro)',     'device', 'gpurig', 'GPU', 'rack', 1, 2, '[{"name":"Eth0","kind":"copper","role":"mdi"},{"name":"Gi0/0","kind":"fiber"}]', NULL, 'Rents out AI compute time. A premium build: 115% of the standard speed.', 69000, 3, 0, 'gpu', 0),
 ('gpu_card_budget', 'GPU card (budget)', 'part', 'gpucard', NULL, NULL, 1, 1, '[]', NULL, 'Install it in a server: +45% income.', 0, 1, 0, 'gpu', 45),
 ('gpu_card_std',    'GPU card',          'part', 'gpucard', NULL, NULL, 1, 1, '[]', NULL, 'Install it in a server: +60% income.', 0, 1, 0, 'gpu', 60),
 ('gpu_card_pro',    'GPU card (pro)',    'part', 'gpucard', NULL, NULL, 1, 1, '[]', NULL, 'Install it in a server: +70% income.', 0, 1, 0, 'gpu', 70)
ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), slot_kinds = VALUES(slot_kinds), height = VALUES(height), ports = VALUES(ports),
  description = VALUES(description), income_per_sec = VALUES(income_per_sec), shop_group = VALUES(shop_group), boost_pct = VALUES(boost_pct);

-- added only if missing, so a re-run keeps any price a lecturer has changed
INSERT INTO shop_items (item_code, quantity, price, unlock_level, unlock_world, sort_order, seller)
SELECT n.item_code, 1, n.price, n.lvl, NULL, n.sort_order, n.seller FROM (
  SELECT 'desk_kit' item_code, 20000 price, 3 lvl, 200 sort_order, NULL seller
  UNION ALL SELECT 'server_rack',     150000,   5, 210, NULL
  UNION ALL SELECT 'gpu_card_budget', 2100000,  6, 300, 'ByteBay'
  UNION ALL SELECT 'gpu_card_std',    3000000,  6, 301, 'Northwind Components'
  UNION ALL SELECT 'gpu_card_pro',    4050000,  6, 302, 'Apex Silicon'
  UNION ALL SELECT 'gpu_rig_budget',  17500000, 7, 310, 'ByteBay'
  UNION ALL SELECT 'gpu_rig_std',     25000000, 7, 311, 'Northwind Components'
  UNION ALL SELECT 'gpu_rig_pro',     33750000, 7, 312, 'Apex Silicon'
) n WHERE NOT EXISTS (SELECT 1 FROM shop_items s WHERE s.item_code = n.item_code);

-- What each student bought from a seller in a 20-minute stock window (stock is limited per window).
CREATE TABLE IF NOT EXISTS gpu_purchases (
  user_id INT UNSIGNED NOT NULL,
  shop_id INT UNSIGNED NOT NULL,
  window  INT UNSIGNED NOT NULL,
  qty     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, shop_id, window),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Temporary buffs, e.g. the broker's GPU discount.
CREATE TABLE IF NOT EXISTS user_buffs (
  user_id    INT UNSIGNED NOT NULL,
  kind       VARCHAR(24) NOT NULL,
  pct        SMALLINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, kind),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

/* ---------- the characters' tips along the way ---------- */
-- One row per tip a student has already seen, so each one only shows once.
CREATE TABLE IF NOT EXISTS user_seen (
  user_id INT UNSIGNED NOT NULL,
  flag    VARCHAR(48) NOT NULL,
  seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, flag),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO characters (code, name, role, side) VALUES
 ('broker',   'Vex',  'Grey-market broker', 'right'),
 ('investor', 'Mira', 'Lumen Capital',      'right')
ON DUPLICATE KEY UPDATE code = code;

/* ---------- random events, once a student has moved past the garage ---------- */
ALTER TABLE users ADD COLUMN IF NOT EXISTS next_event_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS game_events (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  kind       VARCHAR(16) NOT NULL,                 -- broker, investor
  status     VARCHAR(16) NOT NULL DEFAULT 'active', -- active, done, left
  outcome    VARCHAR(16) NULL,                     -- deal, scam, refused, dividend, none
  amount     BIGINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  KEY (user_id, status),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Phone texts (investors and others).
CREATE TABLE IF NOT EXISTS messages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  sender     VARCHAR(32) NOT NULL,   -- a character code
  body       VARCHAR(400) NOT NULL,
  amount     BIGINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at    DATETIME NULL,
  KEY (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- The student's company value over time, for the stock chart on the phone.
CREATE TABLE IF NOT EXISTS company_history (
  user_id INT UNSIGNED NOT NULL,
  at      DATETIME NOT NULL,
  value   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO game_config (name, value, note) VALUES
 ('event_min_minutes', '4',  'Shortest gap between random events (broker, investors), in minutes of play'),
 ('event_max_minutes', '10', 'Longest gap between random events, in minutes of play')
ON DUPLICATE KEY UPDATE name = name;
