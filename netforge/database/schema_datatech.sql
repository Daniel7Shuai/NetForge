-- NetForge: the story (your dad's company raided by DataTech), Cisco equipment names, and the
-- ending (buy DataTech). Run after schema_balance.sql. Safe to run more than once.
USE netforge;

/* ---------- Cisco names, so students learn the real kit ----------
   The models Packet Tracer and CCNA labs use. Labels of devices already placed don't change. */
UPDATE item_types SET name = 'Linksys WRT300N router', label_prefix = 'WRT',
       description = 'Cisco Linksys WRT300N wireless home router: 1x WAN, 4x LAN, Wi-Fi. Boosts every device behind it.' WHERE code = 'home_router';
UPDATE item_types SET name = 'Catalyst 2960 switch', label_prefix = 'SW', slot_kinds = 'shelf,rack',
       description = 'Cisco Catalyst 2960, the switch in most CCNA labs: 8x FastEthernet, 1x Gigabit uplink. Boosts every device behind it. Goes on a shelf or in a rack.' WHERE code = 'switch8';
UPDATE item_types SET name = 'ISR 4331 router', label_prefix = 'R',
       description = 'Cisco ISR 4331 Integrated Services Router: 2x Gigabit, routes between networks. Boosts every device behind it.' WHERE code = 'router';
UPDATE item_types SET name = 'ASA 5506-X firewall', label_prefix = 'ASA',
       description = 'Cisco ASA 5506-X: sits between the internet and your network. Online, it blocks part of every breach.' WHERE code = 'firewall';
UPDATE item_types SET name = 'UCS C220 server', label_prefix = 'SRV',
       description = 'Cisco UCS C220 rack server: hosts services for the network.' WHERE code = 'server';
UPDATE item_types SET name = 'Laptop' WHERE code = 'laptop';

/* ---------- the story's characters ----------
   Staff can rename them and upload art on the Characters page. */
INSERT INTO characters (code, name, role, side) VALUES
 ('dad',    'Marcus', 'Your dad. Founder and CEO, NetForge', 'left'),
 ('lucas',  'Lucas',  'CEO, DataTech',             'right'),
 ('reaper', 'Reaper', 'DataTech security',         'right')
ON DUPLICATE KEY UPDATE code = code;

/* ---------- the ending: buy DataTech ----------
   Needs every topic mastered (every topic exam passed) and 999 billion credits. Buying it
   ends the game with a cutscene; the student can then go back to just before they bought it. */
ALTER TABLE item_types MODIFY category ENUM('device','cable','part','furniture','company') NOT NULL;
INSERT INTO item_types (code, name, category, sprite, label_prefix, description, shop_group)
VALUES ('datatech', 'DataTech', 'company', 'datatech', 'DT',
        'Buy out DataTech and get your dad back. Needs every topic mastered. Buying it ends the game (you can go back afterwards).', 'company')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), shop_group = VALUES(shop_group);
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order)
SELECT 'datatech', 1, 999000000000, 1, 999 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'datatech');

ALTER TABLE users ADD COLUMN IF NOT EXISTS game_ended_at DATETIME NULL;
