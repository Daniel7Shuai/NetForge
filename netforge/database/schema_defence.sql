-- Cyber attack and defence (module 5.0). Import after schema.sql and seed_learning.sql.
USE netforge;

-- When the next random attack may arrive for each student (set when they're online).
ALTER TABLE users ADD COLUMN IF NOT EXISTS next_attack_at DATETIME NULL;

-- Admin-adjustable settings (FR-5.1).
INSERT INTO game_config (name, value, note) VALUES
 ('attacks_enabled',     '1',  '1 = random attacks on, 0 = off'),
 ('attack_min_minutes',  '20', 'Shortest gap between attacks, in minutes of play'),
 ('attack_max_minutes',  '60', 'Longest gap between attacks, in minutes of play'),
 ('attack_seconds',      '60', 'Time a student has to respond to an attack'),
 ('attack_penalty',      '75', 'Credits lost when an attack gets through')
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- The four attack types. A student only faces types whose world they have mastered,
-- so attacks are always built from topics they've already learned.
CREATE TABLE IF NOT EXISTS attack_types (
  code         VARCHAR(16) PRIMARY KEY,
  name         VARCHAR(40) NOT NULL,
  unlock_world TINYINT UNSIGNED NOT NULL,
  enabled      TINYINT(1) NOT NULL DEFAULT 1,
  summary      VARCHAR(160) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attack_scenarios (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type_code   VARCHAR(16) NOT NULL,
  title       VARCHAR(80) NOT NULL,
  evidence    TEXT NOT NULL,             -- shown as a terminal / email block, one line per row
  prompt      VARCHAR(255) NOT NULL,
  options     JSON NOT NULL,             -- the first option is the correct defence; shuffled when shown
  explanation TEXT NOT NULL,
  FOREIGN KEY (type_code) REFERENCES attack_types(code) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Every attack a student faced (FR-5.5).
CREATE TABLE IF NOT EXISTS attacks (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  scenario_id  INT UNSIGNED NOT NULL,
  option_order JSON NOT NULL,            -- how the options were shuffled for this student
  status       ENUM('incoming','defended','breached') NOT NULL DEFAULT 'incoming',
  chosen       TINYINT NULL,             -- index into the original options, NULL if time ran out
  penalty      INT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL,
  expires_at   DATETIME NOT NULL,
  resolved_at  DATETIME NULL,
  KEY k_user (user_id, id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (scenario_id) REFERENCES attack_scenarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

DELETE FROM attack_scenarios;
DELETE FROM attack_types;
INSERT INTO attack_types (code, name, unlock_world, summary) VALUES
 ('phishing',  'Phishing',      0, 'Fake messages that trick people into giving away access.'),
 ('port_scan', 'Port scan',     1, 'Probing many ports to find services to attack.'),
 ('arp_spoof', 'ARP spoofing',  4, 'Faking MAC-to-IP mappings to intercept local traffic.'),
 ('syn_flood', 'SYN flood',     7, 'Half-open TCP connections that exhaust a server.');

INSERT INTO attack_scenarios (type_code, title, evidence, prompt, options, explanation) VALUES
('phishing', 'Mailbox full?',
 'From: IT Support <helpdesk@netf0rge-support.co>\nSubject: URGENT: your mailbox is full\n\nYour account will be closed in 2 hours.\nVerify now: http://netf0rge-login.example/verify',
 'This email just arrived. What do you do?',
 '["Report it as phishing and delete it without clicking","Click the link and sign in to check","Reply and ask whether it’s genuine","Forward it to classmates with the link as a warning"]',
 'Classic phishing: false urgency, a look-alike domain (netf0rge with a zero) and a sign-in link. Never click; report it so others are protected.'),
('phishing', 'Invoice attached',
 'From: accounts@supplier-billing.example\nSubject: Invoice 8812 overdue\nAttachment: Invoice_8812.pdf.exe (412 KB)',
 'Which detail gives this away as an attack?',
 '["The attachment is really a program: it ends in .exe","It mentions an invoice number","It was sent to your work address","The attachment is under 1 MB"]',
 'A double extension hides an executable behind “.pdf”. Opening it would run malware. Real invoices are documents, not programs.'),
('phishing', 'The helpful caller',
 'Incoming call: "Hi, this is your ISP. We’ve detected a fault on your router.\nCan you read me the admin password so we can fix it remotely?"',
 'What’s the safest response?',
 '["Refuse, hang up and call the ISP back on its official number","Give the password, since they’re from the ISP","Give them only half of the password","Give the password, then change it straight after"]',
 'This is vishing (voice phishing). Real providers never ask for your password. Verify by calling a number you already trust.'),

('port_scan', 'Knock, knock',
 'FIREWALL 14:02:11 SYN 203.0.113.45 -> 192.168.1.10:21\nFIREWALL 14:02:11 SYN 203.0.113.45 -> 192.168.1.10:22\nFIREWALL 14:02:11 SYN 203.0.113.45 -> 192.168.1.10:23\nFIREWALL 14:02:12 SYN 203.0.113.45 -> 192.168.1.10:80\nFIREWALL 14:02:12 SYN 203.0.113.45 -> 192.168.1.10:445\nFIREWALL 14:02:12 SYN 203.0.113.45 -> 192.168.1.10:3389',
 'One outside address is touching port after port. What’s the best first response?',
 '["Block the source address at the firewall and close every port you don’t use","Turn the router off and on again","Open more ports to confuse the attacker","Ignore it: scans can’t do any harm"]',
 'A port scan maps which services are listening so they can be attacked. Block the scanner and keep only the ports you need open.'),
('port_scan', 'Website only',
 'SCAN RESULT for 192.168.1.10\n21/tcp   open  ftp\n23/tcp   open  telnet\n80/tcp   open  http\n443/tcp  open  https\n3389/tcp open  ms-wbt-server',
 'This server only hosts a website. Which ports should stay open?',
 '["80 and 443 only","21, 23, 80 and 443","Every port below 1024","3389 only"]',
 'HTTP (80) and HTTPS (443) are all a web server needs. Every other open port is extra attack surface for the scanner to find.'),
('port_scan', 'Telnet is listening',
 'nmap 192.168.1.1\nPORT    STATE  SERVICE\n23/tcp  open   telnet\n80/tcp  open   http',
 'The scan found Telnet open on your router. What should you do?',
 '["Disable Telnet and manage the router over SSH","Move Telnet to a different port number","Leave it: the password protects it","Enable both Telnet and SSH for flexibility"]',
 'Telnet sends everything, passwords included, in plain text. SSH encrypts the session. Turn Telnet off entirely.'),

('arp_spoof', 'Two gateways?',
 'arp -a\n  192.168.1.1     aa-bb-cc-11-22-33   dynamic\n  192.168.1.50    aa-bb-cc-11-22-33   dynamic\n  192.168.1.20    3c-52-82-9f-01-7a   dynamic',
 'The gateway and another host share one MAC address. What is this, and what do you do?',
 '["ARP spoofing: isolate the device at .50 and enable Dynamic ARP Inspection","DHCP exhaustion: restart the router","Normal ARP caching: do nothing","A DNS fault: flush the DNS cache"]',
 'A second IP claiming the gateway’s MAC means someone is poisoning ARP caches to sit in the middle. Remove the device and let the switch validate ARP.'),
('arp_spoof', 'Flood of announcements',
 'switch# show log\n%ARP: gratuitous reply 192.168.1.1 is-at de:ad:be:ef:00:01 (Fa0/7)\n%ARP: gratuitous reply 192.168.1.1 is-at de:ad:be:ef:00:01 (Fa0/7)\n%ARP: gratuitous reply 192.168.1.1 is-at de:ad:be:ef:00:01 (Fa0/7)',
 'A device on Fa0/7 keeps claiming to be the gateway. Which switch feature stops this?',
 '["Dynamic ARP Inspection","Spanning Tree Protocol","VLAN trunking","Power over Ethernet"]',
 'Dynamic ARP Inspection checks ARP replies against trusted IP-to-MAC bindings and drops forged ones like these.'),
('arp_spoof', 'The extra hop',
 'tracert 8.8.8.8\n  1   <1 ms  192.168.1.66\n  2    1 ms  192.168.1.1\n  3   12 ms  100.64.0.1\nBrowser: "Your connection is not private"',
 'Traffic now passes through 192.168.1.66 before the gateway. What’s the likely cause?',
 '["A man-in-the-middle using ARP spoofing","An outage at the ISP","The laptop’s disk is full","A wrong DNS server setting"]',
 'An unexpected local first hop plus certificate warnings is the signature of an ARP-spoofing man-in-the-middle.'),

('syn_flood', 'Half-open everywhere',
 'netstat -an | find "SYN_RECEIVED" /c\n  48213\nTCP 192.168.1.10:80  198.51.100.7:51234  SYN_RECEIVED\nTCP 192.168.1.10:80  203.0.113.91:40112  SYN_RECEIVED',
 'Tens of thousands of connections are stuck half-open. What’s the best mitigation?',
 '["Turn on SYN cookies and rate-limit new connections at the firewall","Raise the connection timeout so they finish","Reboot the web server every few minutes","Switch the website from TCP to UDP"]',
 'A SYN flood fills the server’s backlog with half-open connections. SYN cookies avoid storing them, and rate limiting slows the flood.'),
('syn_flood', 'Which step is missing?',
 'Client -> Server  SYN\nServer -> Client  SYN-ACK\nClient -> Server  ...\n(repeated 40,000 times from different sources)',
 'In a SYN flood, which step of the TCP handshake never arrives?',
 '["The final ACK from the client","The first SYN","The server’s SYN-ACK","The FIN that closes the connection"]',
 'The attacker sends SYNs but never the final ACK, so each connection waits half-open and uses up server resources.'),
('syn_flood', 'Block one address?',
 'Top sources (last 60 s):\n  45.33.12.8      1,204 SYN\n  103.77.9.41     1,187 SYN\n  188.9.240.6     1,165 SYN\n  ... 2,980 more addresses',
 'Why won’t blocking the top address stop this attack?',
 '["It’s distributed and addresses can be spoofed; filter upstream with the ISP","Firewalls can only block one address at a time","Blocking makes the attacker send more","The top address is actually your own router"]',
 'Thousands of sources means a distributed attack, often with forged addresses. It has to be filtered before it reaches you, usually by the ISP.');
