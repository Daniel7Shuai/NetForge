-- Faster passive income, so leasing the office and datacentre (which open new learning worlds)
-- doesn't stall a student who has done the learning. Rates are about 4x the originals:
--   a starter garage (laptop + home router online) now earns 100/h instead of 25/h,
--   so the 800-credit office lease takes one 8-hour break instead of about 32 hours.
-- XP still decides what a student may buy; credits only decide how soon.
-- Lecturers can change these on Unlocks and prices. Import after schema_content.sql.
USE netforge;

UPDATE item_types SET income_per_hour = 40  WHERE code = 'laptop';
UPDATE item_types SET income_per_hour = 60  WHERE code = 'home_router';
UPDATE item_types SET income_per_hour = 80  WHERE code = 'pc';
UPDATE item_types SET income_per_hour = 120 WHERE code = 'switch8';
UPDATE item_types SET income_per_hour = 180 WHERE code = 'router';
UPDATE item_types SET income_per_hour = 300 WHERE code = 'server';
