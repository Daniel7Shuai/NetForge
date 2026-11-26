-- NetForge phase 4: the redesigned office and datacentre (rooms and interior walls, in layout.json).
-- Run after schema_power.sql. Safe to run more than once.
USE netforge;

/* Which floor plan each student's gear has been moved onto. src/MapMigration.php moves it the first
   time their game loads with a newer plan (4 = the office with its NOC and meeting room, and the
   datacentre with its data hall, power room, operations centre and meeting room). */
ALTER TABLE users ADD COLUMN IF NOT EXISTS map_version TINYINT UNSIGNED NOT NULL DEFAULT 0;

/* A desktop PC fits the garage workbench as well as a desk, so the first one doesn't need an 80k desk. */
UPDATE item_types SET slot_kinds = 'desk,bench', description = '1x Fa0, 100 Mb/s. Goes on the workbench or a desk.' WHERE code = 'pc';
