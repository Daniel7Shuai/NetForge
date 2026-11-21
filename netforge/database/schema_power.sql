-- NetForge phase 3: electricity, generators, cooling, temperature and efficiency upgrades.
-- Run after schema_racks.sql. Safe to run more than once.
USE netforge;

/* ---------- power and heat ----------
   Every device draws power_w watts while it runs (rack gear only runs in a rack with a PDU).
   Each place has a grid supply and built-in cooling (src/Facility.php). Generators add supply
   (gen_w); cooling units add cooling (cool_w). Running over the supply browns the place out;
   too little cooling makes it hot, and above 30°C everything there earns less.
   Efficiency upgrades are bought once per place: bill_cut takes points off the electricity bill,
   heat_cut makes the gear there run cooler, cool_boost makes the cooling there stronger. */
ALTER TABLE item_types MODIFY category ENUM('device','cable','part','furniture','company','upgrade') NOT NULL;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS power_w INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS gen_w INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS cool_w INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS bill_cut TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS heat_cut TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS cool_boost TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE item_types ADD COLUMN IF NOT EXISTS facility_for VARCHAR(60) NULL; -- upgrades: the places it can go (NULL: any)

CREATE TABLE IF NOT EXISTS user_facility (
  user_id INT UNSIGNED NOT NULL,
  location VARCHAR(16) NOT NULL,
  item_code VARCHAR(32) NOT NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, location, item_code),
  CONSTRAINT fk_facility_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- what everything already in the game draws, in watts (roughly what the real kit uses)
UPDATE item_types SET power_w = CASE
  WHEN code IN ('isp_box', 'isp_fiber') THEN 0                -- the building's, on its own supply
  WHEN code = 'smartphone' THEN 5   WHEN code = 'tablet' THEN 10  WHEN code = 'laptop' THEN 60
  WHEN code = 'printer' THEN 50     WHEN code = 'smart_tv' THEN 120 WHEN code = 'pc' THEN 250
  WHEN code = 'ip_phone' THEN 8
  WHEN code IN ('iot_light', 'iot_plug', 'iot_thermo', 'iot_camera', 'iot_waste', 'iot_env', 'iot_parking') THEN 6
  WHEN code = 'iot_street' THEN 100 WHEN code = 'iot_traffic' THEN 150 WHEN code = 'iot_plc' THEN 60
  WHEN code = 'srv_db' THEN 550
  WHEN code IN ('server', 'srv_dhcp', 'srv_dns', 'srv_email', 'srv_file') THEN 450
  WHEN code = 'gpu_rig_budget' THEN 1200 WHEN code = 'gpu_rig_std' THEN 1500 WHEN code = 'gpu_rig_pro' THEN 1800
  WHEN code = 'nas' THEN 300 WHEN code = 'san' THEN 800
  WHEN code = 'home_router' THEN 15 WHEN code = 'pt_hub' THEN 15
  WHEN code = 'sw3560' THEN 300 WHEN code = 'sw3650' THEN 350   -- PoE switches power the phones on them
  WHEN code IN ('switch8', 'sw2950', 'pt_switch') THEN 60
  WHEN code = 'r8200' THEN 150
  WHEN code IN ('router', 'r4321', 'r2911', 'pt_router', 'r1841', 'r1941', 'r2811', 'r2901', 'r2620xm', 'r2621xm') THEN 80
  WHEN code IN ('dsl_modem', 'cable_modem') THEN 10 WHEN code IN ('ap_pt', 'lap_pt') THEN 15
  WHEN code = 'wlc_pt' THEN 100 WHEN code = 'net_ctrl' THEN 120
  WHEN code IN ('asa5505', 'firewall') THEN 50
  WHEN code = 'ups' THEN 50 WHEN code = 'kvm' THEN 30 WHEN code = 'load_balancer' THEN 200
  ELSE power_w END
WHERE category = 'device';

/* ---------- generators and cooling units: stand on open floor ---------- */
INSERT INTO item_types (code, name, category, sprite, label_prefix, slot_kinds, height, ports, description, income_per_sec, max_tier, shop_group, power_w, gen_w, cool_w) VALUES
 ('desk_fan', 'Desk fan', 'device', 'deskfan', 'FAN', 'floor,desk', 1, '[]',
  'Moves air over hot gear. Small, cheap and enough for a garage: 400 W of cooling for 40 W.', 0, 1, 'power', 40, 0, 400),
 ('portable_ac', 'Portable air conditioner', 'device', 'portac', 'AC', 'floor', 1, '[]',
  'A room air conditioner on wheels: 2.5 kW of cooling. It draws 900 W itself, so watch your power.', 0, 1, 'power', 900, 0, 2500),
 ('inrow_cooler', 'In-row cooler', 'device', 'inrow', 'IRC', 'floor', 1, '[]',
  'Sits in the row between racks and cools the air right where the servers blow it out: 8 kW of cooling for 1.2 kW.', 0, 1, 'power', 1200, 0, 8000),
 ('crac', 'CRAC unit', 'device', 'crac', 'CRAC', 'floor', 1, '[]',
  'Computer Room Air Conditioner: the big cabinet that keeps a datacentre cold. 25 kW of cooling for 4 kW.', 0, 1, 'power', 4000, 0, 25000),
 ('gen_portable', 'Portable generator', 'device', 'genport', 'GEN', 'floor', 1, '[]',
  'A petrol generator: 3 kW more power for this place, so you can run more than the mains allows.', 0, 1, 'power', 0, 3000, 0),
 ('gen_diesel', 'Diesel generator', 'device', 'gendiesel', 'GEN', 'floor', 1, '[]',
  'A standby diesel generator: 25 kW more power. Datacentres keep these for when the grid fails.', 0, 1, 'power', 0, 25000, 0),
 ('gen_turbine', 'Gas turbine generator', 'device', 'genturbine', 'GT', 'floor', 1, '[]',
  'An on-site gas turbine: 120 kW more power, enough for rows of GPU rigs.', 0, 1, 'power', 0, 120000, 0)
ON DUPLICATE KEY UPDATE name = VALUES(name), sprite = VALUES(sprite), slot_kinds = VALUES(slot_kinds), description = VALUES(description),
  shop_group = VALUES(shop_group), power_w = VALUES(power_w), gen_w = VALUES(gen_w), cool_w = VALUES(cool_w), max_tier = VALUES(max_tier);

/* ---------- efficiency upgrades: bought once for a place, not carried ---------- */
INSERT INTO item_types (code, name, category, sprite, description, max_tier, shop_group, bill_cut, heat_cut, cool_boost, facility_for) VALUES
 ('led_lighting', 'LED lighting', 'upgrade', 'upgrade', 'Swaps the old lights for LEDs. Cuts this place''s electricity bill by 2 points.', 1, 'power', 2, 0, 0, NULL),
 ('smart_strips', 'Smart power strips', 'upgrade', 'upgrade', 'Switch off what isn''t in use. Cuts this place''s electricity bill by 2 points.', 1, 'power', 2, 0, 0, 'garage,office'),
 ('insulation', 'Better insulation', 'upgrade', 'upgrade', 'Keeps the heat outside out. The cooling here works 15% better.', 1, 'power', 0, 0, 15, 'garage,office'),
 ('solar_roof', 'Rooftop solar panels', 'upgrade', 'upgrade', 'Makes some of your own electricity. Cuts this place''s bill by 4 points.', 1, 'power', 4, 0, 0, 'garage,office'),
 ('plat_psu', '80 PLUS Platinum power supplies', 'upgrade', 'upgrade', 'Power supplies that waste less as heat: the gear here runs 15% cooler and the bill drops 2 points.', 1, 'power', 2, 15, 0, 'office,datacentre'),
 ('aisle_contain', 'Hot and cold aisle containment', 'upgrade', 'upgrade', 'Closes off the aisles so hot exhaust air never mixes with the cold air going in. The cooling here works 30% better.', 1, 'power', 0, 0, 30, 'datacentre'),
 ('free_air', 'Free-air cooling', 'upgrade', 'upgrade', 'Uses cold outside air instead of chillers when it can: the cooling here works 25% better and the bill drops 3 points.', 1, 'power', 3, 0, 25, 'datacentre')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), shop_group = VALUES(shop_group),
  bill_cut = VALUES(bill_cut), heat_cut = VALUES(heat_cut), cool_boost = VALUES(cool_boost), facility_for = VALUES(facility_for);

DELETE FROM shop_items WHERE item_code IN ('desk_fan', 'portable_ac', 'inrow_cooler', 'crac', 'gen_portable', 'gen_diesel', 'gen_turbine',
  'led_lighting', 'smart_strips', 'insulation', 'solar_roof', 'plat_psu', 'aisle_contain', 'free_air');
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order) VALUES
 ('desk_fan', 1, 2000, 1, 800),
 ('portable_ac', 1, 60000, 3, 805),
 ('inrow_cooler', 1, 3000000, 5, 810),
 ('crac', 1, 25000000, 6, 815),
 ('gen_portable', 1, 40000, 2, 820),
 ('gen_diesel', 1, 6000000, 5, 825),
 ('gen_turbine', 1, 400000000, 8, 830),
 ('led_lighting', 1, 25000, 2, 840),
 ('smart_strips', 1, 150000, 3, 845),
 ('insulation', 1, 400000, 3, 850),
 ('solar_roof', 1, 3000000, 5, 855),
 ('plat_psu', 1, 20000000, 6, 860),
 ('aisle_contain', 1, 80000000, 7, 865),
 ('free_air', 1, 300000000, 8, 870);
