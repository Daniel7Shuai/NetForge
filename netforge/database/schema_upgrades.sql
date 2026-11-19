-- Equipment upgrades, the firewall and leased locations (FR-4.3, objective 1).
-- Import after schema_progress.sql.
USE netforge;

-- Every placed device has a tier, 1 to its item type's max_tier. Higher tiers earn more
-- (income x1.5 at tier 2, x2 at tier 3); a firewall's tier sets how much of a breach it blocks.
ALTER TABLE devices ADD COLUMN IF NOT EXISTS tier TINYINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS max_tier TINYINT UNSIGNED NOT NULL DEFAULT 3;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS shield_pct TINYINT UNSIGNED NOT NULL DEFAULT 0; -- % of a breach blocked per tier while online

-- Locations a student has leased. The garage is always theirs; the office and datacentre
-- are leased with credits once their level allows (settings in public/assets/data/layout.json).
CREATE TABLE IF NOT EXISTS user_locations (
  user_id   INT UNSIGNED NOT NULL,
  location  VARCHAR(16) NOT NULL,
  leased_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, location),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO item_types (code, name, category, sprite, label_prefix, slot_kinds, width, height, ports, cable_kind, description, income_per_hour, max_tier, shield_pct) VALUES
 ('firewall', 'Firewall', 'device', 'firewall', 'FW', 'shelf,rack', 1, 1,
   '[{"name":"WAN","kind":"copper","role":"mdi"},{"name":"LAN","kind":"copper","role":"mdix"},{"name":"Gi0/0","kind":"fiber"},{"name":"Con0","kind":"console"}]',
   NULL, 'Sits between the internet and your network. Online, it blocks part of every breach.', 0, 3, 25),
 ('isp_fiber', 'ISP fibre panel', 'device', 'fibrepanel', 'ISP', NULL, 1, 1,
   '[{"name":"LAN","kind":"copper","role":"mdix"},{"name":"Gi0/0","kind":"fiber"}]',
   NULL, 'The datacentre’s connection to the internet: copper and fibre.', 0, 1, 0)
ON DUPLICATE KEY UPDATE name = VALUES(name), sprite = VALUES(sprite), slot_kinds = VALUES(slot_kinds), ports = VALUES(ports),
  description = VALUES(description), max_tier = VALUES(max_tier), shield_pct = VALUES(shield_pct);

-- Fixed building parts can't be upgraded.
UPDATE item_types SET max_tier = 1 WHERE code IN ('isp_box', 'isp_fiber');

DELETE FROM shop_items WHERE item_code = 'firewall';
INSERT INTO shop_items (item_code, quantity, price, unlock_level, unlock_world, sort_order) VALUES
 ('firewall', 1, 500, 4, NULL, 75);
