-- NetForge: only end devices earn (network devices boost them), the laptop stays for sale,
-- investors negotiate, and guild news becomes a chat. Run after schema_scale.sql. Safe to run again.
USE netforge;

/* ---------- who earns ----------
   End devices earn. Home routers, switches and routers earn nothing themselves: each one on the
   path between an end device and the ISP boosts what that device earns (boost_pct, x1.5 at tier 2,
   x2 at tier 3; boosts on one path multiply). See src/Progress/Income.php.
   End devices earn more than before, to make up for the network devices' old income. */
UPDATE item_types SET income_per_sec = 0, boost_pct = 25,
       description = '1x WAN, 4x LAN, Wi-Fi. Boosts every device behind it.' WHERE code = 'home_router';
UPDATE item_types SET income_per_sec = 0, boost_pct = 40,
       description = '8x Fa, 1x Gi uplink. Boosts every device behind it.' WHERE code = 'switch8';
UPDATE item_types SET income_per_sec = 0, boost_pct = 60,
       description = '2x Gi, routes between networks. Boosts every device behind it.' WHERE code = 'router';
UPDATE item_types SET income_per_sec = 20    WHERE code = 'laptop';
UPDATE item_types SET income_per_sec = 120   WHERE code = 'pc';
UPDATE item_types SET income_per_sec = 25000 WHERE code = 'server';

/* ---------- the laptop, for sale at every stage ----------
   It fits on the garage workbench and on any desk. */
UPDATE item_types SET slot_kinds = 'bench,desk', description = 'A cheap laptop. 1x Eth0. Goes on a workbench or a desk.' WHERE code = 'laptop';
INSERT INTO shop_items (item_code, quantity, price, unlock_level, sort_order)
SELECT 'laptop', 1, 3000, 1, 5 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM shop_items WHERE item_code = 'laptop');

/* ---------- investors negotiate ----------
   An investor's offer is a game_events row (kind investor, status active, amount = the offer);
   rounds counts how often the student has asked for more. */
ALTER TABLE game_events ADD COLUMN IF NOT EXISTS rounds TINYINT UNSIGNED NOT NULL DEFAULT 0;
INSERT INTO game_config (name, value, note) VALUES
 ('investor_walk_income', '50000', 'Income (credits/s) at which an investor is most likely (80%) to walk away when asked for more')
ON DUPLICATE KEY UPDATE name = name;

/* ---------- guild news is a chat ---------- */
ALTER TABLE guild_feed MODIFY kind ENUM('join','leave','share','raid','goal','chat') NOT NULL;
