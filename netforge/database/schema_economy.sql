-- The economy: an idle-game curve where money grows into the millions and billions.
-- Devices earn per SECOND, so students see their credits climb as they play. Each tier of
-- gear costs about 6-8x the one before and earns about 5-6x more:
--   a starter garage (laptop + 2 home routers) earns 35/s and saves the 500,000 office lease in ~4 h;
--   a geared office saves the 50,000,000 datacentre lease in a couple of hours;
--   a full datacentre of tier-3 servers makes ~750,000/s, reaching a billion in ~20 minutes.
-- XP (levels) still decides what a student may buy; credits only decide how soon.
-- Lecturers change rates and prices on Unlocks and prices; leases live in public/assets/data/layout.json.
-- Absolute values, so it's safe to run again. Import after schema_content.sql.
USE netforge;

-- room for billions
ALTER TABLE users      MODIFY credits BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE attempts   MODIFY credits BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE attacks    MODIFY penalty BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE shop_items MODIFY price   BIGINT UNSIGNED NOT NULL;

-- income is now credits per second (it was per hour)
ALTER TABLE item_types CHANGE COLUMN IF EXISTS income_per_hour income_per_sec INT UNSIGNED NOT NULL DEFAULT 0;

UPDATE item_types SET income_per_sec = 5     WHERE code = 'laptop';
UPDATE item_types SET income_per_sec = 15    WHERE code = 'home_router';
UPDATE item_types SET income_per_sec = 80    WHERE code = 'pc';
UPDATE item_types SET income_per_sec = 400   WHERE code = 'switch8';
UPDATE item_types SET income_per_sec = 2500  WHERE code = 'router';
UPDATE item_types SET income_per_sec = 15000 WHERE code = 'server';

UPDATE shop_items SET price = 300     WHERE item_code = 'copper';
UPDATE shop_items SET price = 400     WHERE item_code = 'crossover';
UPDATE shop_items SET price = 600     WHERE item_code = 'console';
UPDATE shop_items SET price = 2500    WHERE item_code = 'fiber';
UPDATE shop_items SET price = 1500    WHERE item_code = 'home_router';
UPDATE shop_items SET price = 12000   WHERE item_code = 'pc';
UPDATE shop_items SET price = 75000   WHERE item_code = 'switch8';
UPDATE shop_items SET price = 100000  WHERE item_code = 'firewall';
UPDATE shop_items SET price = 600000  WHERE item_code = 'router';
UPDATE shop_items SET price = 5000000 WHERE item_code = 'server';

-- new students: enough for a home router and a pack of copper to get online
INSERT INTO game_config (name, value, note) VALUES ('start_credits', '2500', 'Credits a new student starts with')
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- a breach costs a few minutes of the student's income (at least the minimum), so it stings at every stage
DELETE FROM game_config WHERE name = 'attack_penalty';
INSERT INTO game_config (name, value, note) VALUES
 ('attack_penalty_minutes', '5', 'A breach costs this many minutes of the student''s income'),
 ('attack_penalty_min', '100', 'Smallest breach penalty in credits')
ON DUPLICATE KEY UPDATE name = name;
