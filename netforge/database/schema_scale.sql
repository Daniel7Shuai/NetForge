-- NetForge: bigger office and datacentre maps, a steeper price curve, and "topics" (was "worlds").
-- Run after schema_events.sql. Safe to run more than once.
USE netforge;

/* ---------- bigger places ----------
   The office is now 28x16 tiles (12 rack slots) and the datacentre 36x20 (48 rack slots); see
   public/assets/data/layout.json. Gear already placed there moves to the matching new spot, once
   (game_config.layout_version goes from 1 to 2). */
INSERT INTO game_config (name, value, note) VALUES
 ('layout_version', '1', 'Floor plan version. 2 = the bigger office and datacentre')
ON DUPLICATE KEY UPDATE name = name;

-- office: desks at x 4/9, y 3/8 -> x 4/10, y 3/9; racks at x 14-16, y 3/7 -> x 19-21, y 3/9; ISP box (10,1) -> (14,1).
-- tile_y is set first, so the tile_x rules below see the new row.
UPDATE devices d JOIN game_config g ON g.name = 'layout_version' AND g.value = '1'
   SET d.tile_y = CASE WHEN d.tile_y = 8 AND d.tile_x IN (4, 9) THEN 9
                       WHEN d.tile_y = 7 AND d.tile_x IN (14, 15, 16) THEN 9
                       ELSE d.tile_y END,
       d.tile_x = CASE WHEN d.tile_x = 9 AND d.tile_y IN (3, 9) THEN 10
                       WHEN d.tile_x IN (14, 15, 16) THEN d.tile_x + 5
                       WHEN d.tile_x = 10 AND d.tile_y = 1 THEN 14
                       ELSE d.tile_x END
 WHERE d.location = 'office';

-- datacentre: rack row x 2-15, y 3 -> x 3-16; row x 6-15, y 7 -> x 3-12, y 8; desk spot (4,9) -> (4,16); fibre panel (10,1) -> (18,1).
UPDATE devices d JOIN game_config g ON g.name = 'layout_version' AND g.value = '1'
   SET d.tile_y = CASE WHEN d.tile_y = 7 THEN 8
                       WHEN d.tile_y = 9 AND d.tile_x = 4 THEN 16
                       ELSE d.tile_y END,
       d.tile_x = CASE WHEN d.tile_y = 3 THEN d.tile_x + 1
                       WHEN d.tile_y = 8 THEN d.tile_x - 3
                       WHEN d.tile_y = 1 AND d.tile_x = 10 THEN 18
                       ELSE d.tile_x END
 WHERE d.location = 'datacentre';

-- furniture bought for the old floor plans goes back to the inventory, to place again
INSERT INTO inventory (user_id, item_code, quantity)
SELECT f.user_id, f.item_code, COUNT(*) FROM user_furniture f JOIN game_config g ON g.name = 'layout_version' AND g.value = '1'
 WHERE f.location IN ('office', 'datacentre') GROUP BY f.user_id, f.item_code
ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity);
DELETE f FROM user_furniture f JOIN game_config g ON g.name = 'layout_version' AND g.value = '1'
 WHERE f.location IN ('office', 'datacentre');

-- players standing in those places start at the new door
UPDATE users u JOIN game_config g ON g.name = 'layout_version' AND g.value = '1'
   SET u.pos_x = CASE u.location WHEN 'office' THEN 14 ELSE 22 END,
       u.pos_y = CASE u.location WHEN 'office' THEN 7 ELSE 17 END
 WHERE u.location IN ('office', 'datacentre');

UPDATE game_config SET value = '2' WHERE name = 'layout_version';

/* ---------- a steeper price curve ----------
   Incomes stay the same; prices climb faster, so each tier takes longer to pay back
   (home router ~4 minutes, PC ~12, switch ~25, router ~1 hour, server ~2, GPU rigs ~4-6),
   and late gear costs hundreds of millions to over a billion. Upgrades cost 60% and 120% of
   the price, so they scale with it. The leases are in layout.json (office 5M, datacentre 500M). */
UPDATE shop_items SET price = 500        WHERE item_code = 'copper';
UPDATE shop_items SET price = 800        WHERE item_code = 'crossover';
UPDATE shop_items SET price = 1200       WHERE item_code = 'console';
UPDATE shop_items SET price = 10000      WHERE item_code = 'fiber';
UPDATE shop_items SET price = 4000       WHERE item_code = 'home_router';
UPDATE shop_items SET price = 60000      WHERE item_code = 'pc';
UPDATE shop_items SET price = 80000      WHERE item_code = 'desk_kit';
UPDATE shop_items SET price = 600000     WHERE item_code = 'switch8';
UPDATE shop_items SET price = 1000000    WHERE item_code = 'firewall';
UPDATE shop_items SET price = 2500000    WHERE item_code = 'server_rack';
UPDATE shop_items SET price = 8000000    WHERE item_code = 'router';
UPDATE shop_items SET price = 40000000   WHERE item_code = 'gpu_card_budget';
UPDATE shop_items SET price = 55000000   WHERE item_code = 'gpu_card_std';
UPDATE shop_items SET price = 75000000   WHERE item_code = 'gpu_card_pro';
UPDATE shop_items SET price = 120000000  WHERE item_code = 'server';
UPDATE shop_items SET price = 700000000  WHERE item_code = 'gpu_rig_budget';
UPDATE shop_items SET price = 1000000000 WHERE item_code = 'gpu_rig_std';
UPDATE shop_items SET price = 1400000000 WHERE item_code = 'gpu_rig_pro';

-- enough to start: a home router and a copper cable
UPDATE game_config SET value = '5000' WHERE name = 'start_credits';

/* ---------- "topics" (they were called worlds) ---------- */
-- The six empty topics left over from the first content plan (numbers 103-108, archived by
-- schema_content.sql) only cluttered the staff Content page. Remove them while they're still empty.
DELETE w FROM worlds w
 WHERE w.archived = 1 AND w.number >= 100
   AND NOT EXISTS (SELECT 1 FROM levels l WHERE l.world_id = w.id)
   AND NOT EXISTS (SELECT 1 FROM questions q WHERE q.world_id = w.id)
   AND NOT EXISTS (SELECT 1 FROM world_progress p WHERE p.world_id = w.id);

UPDATE badges SET name = 'Topic master', description = 'Master a topic by passing its exam.' WHERE code = 'world_master';
UPDATE badges SET description = 'Score 100% on a topic exam.' WHERE code = 'perfect_exam';
