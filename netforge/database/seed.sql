USE netforge;

INSERT INTO game_config (name, value, note) VALUES
 ('start_credits', '300',  'Credits a new player starts with: enough for a home router and cables to get online. After that, money comes from passive income.'),
 ('xp_per_level',  '1000', 'XP needed for each level'),
 ('map_width',     '20',   'Grid width in tiles'),
 ('map_height',    '12',   'Grid height in tiles')
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- Devices: ports are JSON, slot_kinds says where on the map they fit.
-- Port "kind" is the physical socket (copper, fiber, console). Copper ports also have a "role":
--   mdi  = host/router-style port (laptops, PCs, servers, router and WAN ports)
--   mdix = switch-style port (switch ports, home router LAN ports, the ISP box LAN port)
-- A straight-through (copper) cable links mdi to mdix; a crossover links mdi-mdi or mdix-mdix.
INSERT INTO item_types (code, name, category, sprite, label_prefix, slot_kinds, width, height, ports, cable_kind, description) VALUES
 ('laptop',      'Busted laptop',   'device', 'laptop',     'Laptop', 'bench', 1, 1,
   '[{"name":"Eth0","kind":"copper","role":"mdi"},{"name":"USB0","kind":"console"}]', NULL, 'Cracked screen, still boots. 1x Eth0'),
 ('home_router', 'Home router',     'device', 'homerouter', 'HomeRT', 'shelf', 1, 1,
   '[{"name":"WAN","kind":"copper","role":"mdi"},{"name":"LAN1","kind":"copper","role":"mdix"},{"name":"LAN2","kind":"copper","role":"mdix"},{"name":"LAN3","kind":"copper","role":"mdix"},{"name":"LAN4","kind":"copper","role":"mdix"}]',
   NULL, '1x WAN, 4x LAN, Wi-Fi'),
 ('isp_box',     'ISP fibre box',   'device', 'ispbox',     'ISP',    NULL,    1, 1,
   '[{"name":"LAN","kind":"copper","role":"mdix"}]', NULL, 'Where the internet comes in'),
 ('pc',          'Desktop PC',      'device', 'tower',      'PC',     'desk',  1, 1, '[{"name":"Fa0","kind":"copper","role":"mdi"}]', NULL, '1x Fa0, 100 Mb/s'),
 ('switch8',     'Switch, 8 port',  'device', 'rack',       'Switch', 'rack',  1, 2,
   '[{"name":"Fa0/1","kind":"copper","role":"mdix"},{"name":"Fa0/2","kind":"copper","role":"mdix"},{"name":"Fa0/3","kind":"copper","role":"mdix"},{"name":"Fa0/4","kind":"copper","role":"mdix"},{"name":"Fa0/5","kind":"copper","role":"mdix"},{"name":"Fa0/6","kind":"copper","role":"mdix"},{"name":"Fa0/7","kind":"copper","role":"mdix"},{"name":"Fa0/8","kind":"copper","role":"mdix"},{"name":"Gi0/1","kind":"fiber"},{"name":"Con0","kind":"console"}]',
   NULL, '8x Fa, 1x Gi uplink'),
 ('router',      'Router',          'device', 'rack',       'Router', 'rack',  1, 2,
   '[{"name":"Gi0/0","kind":"fiber"},{"name":"Gi0/1","kind":"copper","role":"mdi"},{"name":"Con0","kind":"console"}]', NULL, '2x Gi, routes between networks'),
 ('server',      'Server',          'device', 'rack',       'Server', 'rack',  1, 2, '[{"name":"Eth0","kind":"copper","role":"mdi"},{"name":"Eth1","kind":"copper","role":"mdi"}]', NULL, 'Hosts services for the network'),
 ('copper',      'Copper cable',    'cable',  'coil',       NULL,     NULL,    1, 1, NULL, 'copper',    'Straight-through: unlike devices, e.g. laptop to router LAN'),
 ('crossover',   'Crossover cable', 'cable',  'coil',       NULL,     NULL,    1, 1, NULL, 'crossover', 'Like devices, e.g. laptop to laptop'),
 ('fiber',       'Fiber cable',     'cable',  'coil',       NULL,     NULL,    1, 1, NULL, 'fiber',     'Long runs between racks'),
 ('console',     'Console cable',   'cable',  'coil',       NULL,     NULL,    1, 1, NULL, 'console',   'Manage a device from a PC')
ON DUPLICATE KEY UPDATE name = VALUES(name), sprite = VALUES(sprite), label_prefix = VALUES(label_prefix),
  slot_kinds = VALUES(slot_kinds), ports = VALUES(ports), description = VALUES(description);

-- The economy: credits come from passive income (devices online earn per hour);
-- XP from answering questions raises your level, and your level decides what you may buy (FR-2.4).
-- unlock_world can additionally require a mastered world; NULL means level only.
DELETE FROM shop_items;
INSERT INTO shop_items (item_code, quantity, price, unlock_level, unlock_world, sort_order) VALUES
 ('copper',      3,  40, 1, NULL, 10),
 ('home_router', 1, 200, 1, NULL, 20),
 ('console',     1,  60, 2, NULL, 30),
 ('crossover',   2,  50, 2, NULL, 35),
 ('pc',          1, 250, 3, NULL, 40),
 ('fiber',       3, 150, 4, NULL, 50),
 ('switch8',     1, 400, 4, NULL, 60),
 ('router',      1, 600, 5, NULL, 70),
 ('server',      1, 800, 6, NULL, 80);

-- Objectives guide the first session. Goal types: place, level, world, link (see Rules::goalMet).
-- link goals need the link to be UP, so the right cable and ports must be used.
DELETE FROM objectives;
INSERT INTO objectives (sort_order, title, goal, reward_credits, reward_xp) VALUES
 (10, 'Set up your laptop on the workbench',               '{"type":"place","item":"laptop"}',     0, 0),
 (20, 'Open Learn (L) and finish your first lesson',       '{"type":"level","world":0,"level":1}', 0, 0),
 (30, 'Pass the World 0 exam',                             '{"type":"world","world":0}',           0, 0),
 (40, 'Buy a home router and put it on the shelf',         '{"type":"place","item":"home_router"}',0, 0),
 (50, 'Buy copper cables, then link the ISP box to the router’s WAN port', '{"type":"link","a":"isp_box","b":"home_router","cable":"copper"}', 0, 0),
 (60, 'Connect your laptop to a router LAN port',          '{"type":"link","a":"laptop","b":"home_router","cable":"copper"}', 0, 0);
