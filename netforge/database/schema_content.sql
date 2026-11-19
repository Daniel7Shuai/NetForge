-- CCNA content restructure: one world per area of the game, following the CCNA 200-301 blueprint.
--   World 0  Garage      Network Fundamentals (20%)
--   World 1  Office      Network Access (20%) and IP Connectivity (25%)   needs the office leased
--   World 2  Datacentre  IP Services (10%), Security Fundamentals (15%),
--                        Automation and Programmability (10%)             needs the datacentre leased
-- The levels and questions themselves are loaded by tools/seed-content.php from database/content/ccna.php.
-- Import after schema_users.sql. Safe to run again.
USE netforge;

ALTER TABLE worlds ADD COLUMN IF NOT EXISTS requires_location VARCHAR(16) NULL; -- key in layout.json; NULL = no area needed
ALTER TABLE worlds MODIFY ccna_modules VARCHAR(80) NOT NULL; -- now the CCNA blueprint domains a world covers

-- Progress in the old worlds 1-8 belonged to topics that now live elsewhere. Clear it once,
-- only while those worlds still have their old titles, so a re-run never wipes real progress.
DELETE wp FROM world_progress wp JOIN worlds w ON w.id = wp.world_id
 WHERE w.id BETWEEN 2 AND 9
   AND w.title IN ('Protocols and models', 'Physical layer and number systems', 'Data link and Ethernet switching',
                   'Network layer and address resolution', 'Router config and IPv4 addressing', 'IPv6 and ICMP',
                   'Transport and application layers', 'Security and building a small network');

-- The existing starter levels all teach fundamentals: move them into the Garage world, in roadmap order.
UPDATE levels SET world_id = 1, sort_order = 1 WHERE id = 1;  -- Networks and their parts
UPDATE levels SET world_id = 1, sort_order = 2 WHERE id = 2;  -- LANs, WANs and the internet
UPDATE levels SET world_id = 1, sort_order = 3 WHERE id = 3;  -- Talking to a Cisco device
UPDATE levels SET world_id = 1, sort_order = 4 WHERE id = 6;  -- The OSI model
UPDATE levels SET world_id = 1, sort_order = 7 WHERE id = 4;  -- Binary Blitz
UPDATE levels SET world_id = 1, sort_order = 9 WHERE id = 5;  -- Subnet calculator
UPDATE questions SET level_id = 6 WHERE id = 34 AND level_id IS NULL; -- the encapsulation question had no level
UPDATE questions q JOIN levels l ON l.id = q.level_id SET q.world_id = l.world_id;

UPDATE worlds SET number = number + 100 WHERE id BETWEEN 2 AND 9 AND number < 100; -- free numbers 1-2 (unique key)
UPDATE worlds SET number = 0, title = 'Garage', ccna_modules = 'Network Fundamentals', requires_location = NULL,
  summary = 'Homelab basics: what a network is made of, the OSI model, Ethernet and cabling, IPv4 and IPv6, wireless and the cloud.',
  exam_questions = 15, exam_minutes = 15, exam_pass_pct = 70, archived = 0 WHERE id = 1;
UPDATE worlds SET number = 1, title = 'Office', ccna_modules = 'Network Access, IP Connectivity', requires_location = 'office',
  summary = 'Run a real office network: VLANs, Spanning Tree, wireless, port security, routing, OSPF and gateway redundancy.',
  exam_questions = 20, exam_minutes = 20, exam_pass_pct = 70, archived = 0 WHERE id = 2;
UPDATE worlds SET number = 2, title = 'Datacentre', ccna_modules = 'IP Services, Security, Automation', requires_location = 'datacentre',
  summary = 'Keep a datacentre running and safe: NAT, DHCP, DNS and other services, security and ACLs, and network automation.',
  exam_questions = 20, exam_minutes = 20, exam_pass_pct = 70, archived = 0 WHERE id = 3;
-- the old empty worlds stay in the table (nothing references them) but students no longer see them
UPDATE worlds SET archived = 1 WHERE id BETWEEN 4 AND 9;

-- Attacks unlock with the area where their defence is taught.
UPDATE attack_types SET unlock_world = 0 WHERE code IN ('phishing', 'port_scan');
UPDATE attack_types SET unlock_world = 1 WHERE code = 'arp_spoof';
UPDATE attack_types SET unlock_world = 2 WHERE code = 'syn_flood';
