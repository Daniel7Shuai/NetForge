-- NetForge phase 2: racks with U-slots, Misc rack gear, interface modules and the wishlist.
-- Run after schema_catalog.sql. Safe to run more than once.
USE netforge;

/* ---------- racks ----------
   Every rack position (the office server room, the datacentre rows, or a server rack you buy) is a
   12U rack. Rack gear mounts in it at a U position: devices.rack_u is the lowest U it fills, and
   item_types.rack_u is how many it fills. A rack needs a PDU before anything in it is powered. */
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS rack_u TINYINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS rack_boost SMALLINT UNSIGNED NOT NULL DEFAULT 0; -- % more for everything in the same rack
ALTER TABLE devices ADD COLUMN IF NOT EXISTS rack_u TINYINT UNSIGNED NULL;
UPDATE item_types SET rack_u = 2 WHERE code IN ('server', 'srv_dhcp', 'srv_dns', 'srv_email', 'srv_file', 'srv_db', 'gpu_rig_budget', 'gpu_rig_std', 'gpu_rig_pro');
UPDATE item_types SET description = 'An empty 12U rack cabinet. Place it on open floor, then mount gear in it: a PDU first, so it has power.' WHERE code = 'server_rack';

/* ---------- Misc rack gear ---------- */
INSERT INTO item_types (code, name, category, sprite, label_prefix, slot_kinds, height, rack_u, ports, description, income_per_sec, max_tier, shop_group, boost_pct, rack_boost) VALUES
 ('pdu', 'Rack PDU', 'device', 'pdu1u', 'PDU', 'rack', 2, 1, '[]',
  'Power Distribution Unit: feeds power to everything in its rack. A rack needs one before any of its gear runs.', 0, 1, 'misc', 0, 0),
 ('ups', 'UPS (3U)', 'device', 'ups3u', 'UPS', 'rack', 2, 3, '[]',
  'Uninterruptible Power Supply: batteries that keep the rack running through power cuts. Each one in a powered rack lets your network keep earning 2 hours longer while you are away (up to 8 hours more).', 0, 1, 'misc', 0, 0),
 ('patch_panel', 'Patch panel', 'device', 'patch1u', 'PP', 'rack', 2, 1,
  '[{"name":"P1","kind":"copper","role":"pass"},{"name":"P2","kind":"copper","role":"pass"},{"name":"P3","kind":"copper","role":"pass"},{"name":"P4","kind":"copper","role":"pass"},{"name":"P5","kind":"copper","role":"pass"},{"name":"P6","kind":"copper","role":"pass"},{"name":"P7","kind":"copper","role":"pass"},{"name":"P8","kind":"copper","role":"pass"}]',
  'Where the building''s cable runs end, so devices are patched in with short cables instead of long ones. Passive: any copper cable plugs in, and links pass straight through.', 0, 1, 'misc', 0, 0),
 ('cable_mgmt', 'Cable management panel', 'device', 'cablemgmt1u', 'CM', 'rack', 2, 1, '[]',
  'Keeps cables tidy and labelled, so faults are found fast and air flows. Everything in its rack earns 3% more.', 0, 1, 'misc', 0, 3),
 ('blanking', 'Blanking panel', 'device', 'blank1u', 'BP', 'rack', 2, 1, '[]',
  'Closes off an empty U so cold air goes through the servers instead of around them. Everything in its rack earns 1% more.', 0, 1, 'misc', 0, 1),
 ('kvm', 'KVM switch', 'device', 'kvm1u', 'KVM', 'rack', 2, 1, '[]',
  'Keyboard, Video, Mouse: one console to manage every server in the rack. Everything in its rack earns 5% more.', 0, 1, 'misc', 0, 5),
 ('load_balancer', 'Load balancer', 'device', 'lb1u', 'LB', 'rack', 2, 1,
  '[{"name":"Gi0/0","kind":"copper","role":"mdi"},{"name":"Gi0/1","kind":"copper","role":"mdix"},{"name":"Gi0/2","kind":"copper","role":"mdix"},{"name":"Gi0/3","kind":"copper","role":"mdix"}]',
  'Shares incoming traffic between several servers, so none is overloaded and one can fail safely. Servers behind it earn 20% more.', 0, 3, 'misc', 20, 0),
 ('nas', 'NAS storage', 'device', 'nas2u', 'NAS', 'rack', 2, 2, '[{"name":"Eth0","kind":"copper","role":"mdi"}]',
  'Network Attached Storage: a box of drives that shares files over the network. Clients rent the space.', 9000, 3, 'misc', 0, 0),
 ('san', 'SAN storage array', 'device', 'san3u', 'SAN', 'rack', 2, 3, '[{"name":"Fc0","kind":"fiber"},{"name":"Fc1","kind":"fiber"}]',
  'Storage Area Network: high-speed block storage on its own fibre network, for databases and virtual machines.', 40000, 3, 'misc', 0, 0)
ON DUPLICATE KEY UPDATE name = VALUES(name), sprite = VALUES(sprite), slot_kinds = VALUES(slot_kinds), height = VALUES(height), rack_u = VALUES(rack_u),
  ports = VALUES(ports), description = VALUES(description), income_per_sec = VALUES(income_per_sec), shop_group = VALUES(shop_group),
  boost_pct = VALUES(boost_pct), rack_boost = VALUES(rack_boost);

INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'pdu', 1, 20000, 4, 700 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'pdu');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'patch_panel', 1, 15000, 4, 705 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'patch_panel');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'blanking', 1, 2000, 4, 710 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'blanking');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'cable_mgmt', 1, 8000, 4, 715 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'cable_mgmt');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'kvm', 1, 300000, 5, 720 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'kvm');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'ups', 1, 500000, 5, 725 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'ups');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'load_balancer', 1, 12000000, 6, 730 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'load_balancer');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'nas', 1, 50000000, 6, 735 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'nas');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'san', 1, 250000000, 8, 740 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'san');

/* ---------- interface modules ----------
   A module fits in a free bay of the devices listed in module_for. It can add ports ({b} is the
   bay number) or a radio: 'client' lets a PC or laptop join Wi-Fi, 'ap' turns a router into an
   access point. devices.modules lists the fitted ones, bay by bay. */
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS bays TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS module_for VARCHAR(200) NULL;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS module_ports VARCHAR(400) NULL;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS module_radio ENUM('none','ap','client') NOT NULL DEFAULT 'none';
ALTER TABLE devices ADD COLUMN IF NOT EXISTS modules VARCHAR(400) NULL;
UPDATE item_types SET bays = 2 WHERE code = 'pt_router';
UPDATE item_types SET bays = 1 WHERE code IN ('r2620xm', 'r2621xm', 'r1841', 'r2811', 'r1941', 'r2901', 'r2911', 'pc', 'laptop');

INSERT INTO item_types (code, name, category, sprite, label_prefix, description, max_tier, shop_group, module_for, module_ports, module_radio) VALUES
 ('nm_1cfe', 'NM-1CFE module', 'part', 'module', 'NM', 'Network module with one copper FastEthernet port (Fa{bay}/0).', 1, 'modules',
  'pt_router,r2620xm,r2621xm', '[{"name":"Fa{b}/0","kind":"copper","role":"mdi"}]', 'none'),
 ('nm_1ffe', 'NM-1FFE module', 'part', 'module', 'NM', 'Network module with one fibre FastEthernet port.', 1, 'modules',
  'pt_router,r2620xm,r2621xm', '[{"name":"Fa{b}/0","kind":"fiber"}]', 'none'),
 ('nm_1cge', 'NM-1CGE module', 'part', 'module', 'NM', 'Network module with one copper Gigabit Ethernet port.', 1, 'modules',
  'pt_router', '[{"name":"Gi{b}/0","kind":"copper","role":"mdi"}]', 'none'),
 ('nm_1fge', 'NM-1FGE module', 'part', 'module', 'NM', 'Network module with one fibre Gigabit Ethernet port.', 1, 'modules',
  'pt_router', '[{"name":"Gi{b}/0","kind":"fiber"}]', 'none'),
 ('hwic_ap', 'HWIC-AP-AG-B wireless module', 'part', 'module', 'HWIC', 'Turns an ISR router into a Wi-Fi access point for phones, tablets and IoT in the same room.', 1, 'modules',
  'r1841,r2811,r1941,r2901,r2911', '[]', 'ap'),
 ('wmp300n', 'WMP300N wireless card', 'part', 'wificard', 'WMP', 'A Linksys PCI Wi-Fi card for a desktop PC, so it can go online over Wi-Fi without a cable.', 1, 'modules',
  'pc', '[]', 'client'),
 ('wpc300n', 'WPC300N wireless card', 'part', 'wificard', 'WPC', 'A Linksys PC-card Wi-Fi adapter for a laptop, so it can go online over Wi-Fi without a cable.', 1, 'modules',
  'laptop', '[]', 'client')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), shop_group = VALUES(shop_group), module_for = VALUES(module_for),
  module_ports = VALUES(module_ports), module_radio = VALUES(module_radio), sprite = VALUES(sprite);

INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'wpc300n', 1, 1000, 2, 800 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'wpc300n');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'wmp300n', 1, 2000, 3, 805 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'wmp300n');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'nm_1cfe', 1, 30000, 3, 810 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'nm_1cfe');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'nm_1ffe', 1, 60000, 4, 815 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'nm_1ffe');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'nm_1cge', 1, 150000, 4, 820 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'nm_1cge');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'nm_1fge', 1, 300000, 5, 825 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'nm_1fge');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) SELECT 'hwic_ap', 1, 800000, 5, 830 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'hwic_ap');

/* ---------- the wishlist: shop items a student is saving for ---------- */
CREATE TABLE IF NOT EXISTS user_wishlist (
  user_id   INT UNSIGNED NOT NULL,
  item_code VARCHAR(32) NOT NULL,
  added_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, item_code),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
