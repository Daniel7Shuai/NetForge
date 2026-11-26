-- NetForge: the Work Laptop (a console terminal for routers and switches) and staff you hire.
-- Run after schema_maps.sql. Safe to run more than once.
USE netforge;

/* ---------- the Work Laptop ----------
   Console it into a router's or switch's console port (a console cable) and open a terminal: a small
   Cisco IOS command line (src/Cli.php). Each device keeps its running and startup config in
   devices.config. A device given the CCNA basic setup (hostname, enable secret, an interface with an IP
   that's up, saved to startup-config) is "configured" and boosts 25% more. */
ALTER TABLE devices ADD COLUMN IF NOT EXISTS config LONGTEXT NULL;

INSERT INTO item_types (code, name, category, sprite, label_prefix, slot_kinds, height, ports, description, income_per_sec, max_tier, shop_group, power_w) VALUES
 ('work_laptop', 'Work laptop', 'device', 'worklaptop', 'WL', 'bench,desk,floor', 1,
  '[{"name":"Eth0","kind":"copper","role":"mdi"},{"name":"Console","kind":"console"}]',
  'Your admin laptop. Run a console cable from it to a router''s or switch''s console port, then open a terminal to configure the device. It earns nothing itself.',
  0, 1, 'end', 60)
ON DUPLICATE KEY UPDATE name = VALUES(name), sprite = VALUES(sprite), slot_kinds = VALUES(slot_kinds), ports = VALUES(ports),
  description = VALUES(description), income_per_sec = VALUES(income_per_sec), max_tier = VALUES(max_tier), power_w = VALUES(power_w);
DELETE FROM shop_items WHERE item_code = 'work_laptop';
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) VALUES ('work_laptop', 1, 15000, 2, 15);

/* desktops now need someone to work them */
UPDATE item_types SET description = '1x Fa0, 100 Mb/s. Earns only with someone at it: hire an earner in the office or datacentre. In the garage you work one yourself.'
 WHERE code = 'pc';

/* ---------- staff ----------
   Resumes arrive three at a time; you hire or reject each. Hired staff work in the place you hired
   them (office or datacentre). Roles: earner (works a desktop: it earns, more for seniors), security
   (blocks part of an attack's damage), technician (the gear in their place runs cooler), admin
   (everything in their place earns more). Salaries are a share of what they bring in (src/Staff.php). */
CREATE TABLE IF NOT EXISTS staff_candidates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  role ENUM('earner','security','technician','admin') NOT NULL,
  level TINYINT UNSIGNED NOT NULL,
  salary_pct TINYINT UNSIGNED NOT NULL,
  resume TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id),
  CONSTRAINT fk_cand_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  role ENUM('earner','security','technician','admin') NOT NULL,
  level TINYINT UNSIGNED NOT NULL,
  salary_pct TINYINT UNSIGNED NOT NULL,
  location VARCHAR(16) NOT NULL,
  resume TEXT NOT NULL,
  hired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id, location),
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
