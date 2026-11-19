-- Learning content: worlds 0-8 (CCNA ITN modules 1-17), with starter lessons and
-- questions for World 0, World 2 (Binary Blitz) and World 5 (Subnet Calculator).
-- Lecturers add the rest through the question bank (module 2.0).
USE netforge;

DELETE FROM questions; DELETE FROM levels; DELETE FROM worlds;

INSERT INTO worlds (id, number, title, summary, ccna_modules, exam_questions, exam_minutes, exam_pass_pct) VALUES
 (1, 0, 'Networking basics',                     'What a network is made of, and how to talk to a Cisco device.', '1-2',   10, 10, 70),
 (2, 1, 'Protocols and models',                  'The OSI and TCP/IP models and how data is encapsulated.',      '3',     10, 10, 70),
 (3, 2, 'Physical layer and number systems',     'Cables, signals, and converting binary and decimal fast.',     '4-5',    8,  6, 70),
 (4, 3, 'Data link and Ethernet switching',      'Frames, MAC addresses and how switches forward traffic.',      '6-7',   10, 10, 70),
 (5, 4, 'Network layer and address resolution',  'IP packets, routing tables, ARP and neighbour discovery.',     '8-9',   10, 10, 70),
 (6, 5, 'Router config and IPv4 addressing',     'Configuring a router and subnetting IPv4 networks.',           '10-11',  8, 10, 70),
 (7, 6, 'IPv6 and ICMP',                         'IPv6 address types and testing reachability with ICMP.',       '12-13', 10, 10, 70),
 (8, 7, 'Transport and application layers',      'TCP, UDP, ports and the services users rely on.',              '14-15', 10, 10, 70),
 (9, 8, 'Security and building a small network', 'Threats, defences and designing a small network.',            '16-17', 10, 10, 70);

/* ---------------------------- World 0 ---------------------------- */

INSERT INTO levels (id, world_id, sort_order, title, lesson, questions_per_run, pass_pct) VALUES
(1, 1, 1, 'Networks and their parts',
'A **network** is two or more devices connected so they can share resources. Every network is built from three kinds of parts:

- **End devices** (hosts) are where data starts or ends: laptops, phones, servers and printers.
- **Intermediary devices** connect end devices and move data between them: switches, routers, wireless access points and firewalls.
- **Network media** is the path the data travels over: copper cable, fibre-optic cable or wireless.

A host that offers a service is a **server**, and a host that asks for one is a **client**. One computer can be both.

In a **peer-to-peer** network, devices share directly with no dedicated server. It’s cheap and simple, but hard to scale and secure.', 5, 60),

(2, 1, 2, 'LANs, WANs and the internet',
'A **LAN** (local area network) covers a small area such as a home, school or office, and is run by one person or organisation. A **WAN** (wide area network) links LANs over long distances and is usually run by service providers.

The **internet** is a worldwide collection of interconnected networks. An **intranet** is a private network for an organisation’s own members, and an **extranet** gives outsiders such as suppliers secure access to parts of it.

Homes reach the internet through an **ISP** (internet service provider) over cable, DSL, fibre, cellular or satellite.

A reliable network has four qualities:

- **Fault tolerance**: redundant paths, so one failure doesn’t cut everyone off.
- **Scalability**: it can grow without slowing existing users.
- **Quality of service (QoS)**: time-sensitive traffic such as voice gets priority.
- **Security**: confidentiality, integrity and availability of data.', 5, 60),

(3, 1, 3, 'Talking to a Cisco device',
'Cisco switches and routers run **IOS**. You reach its command line through the **console port** with a console cable, which works even before the device has any network settings, or remotely with **SSH** (encrypted). Telnet also works remotely but sends everything in plain text, so avoid it.

IOS has command modes, each with its own prompt:

- **User EXEC** `Switch>`: basic monitoring only.
- **Privileged EXEC** `Switch#`: every show command. Enter it with `enable`.
- **Global configuration** `Switch(config)#`: change settings. Enter it with `configure terminal`.
- **Interface configuration** `Switch(config-if)#`: settings for one port.

Basic setup: name the device with `hostname`, protect privileged EXEC with `enable secret`, then save with `copy running-config startup-config` so the settings survive a reboot.', 5, 60);

INSERT INTO questions (world_id, level_id, type, prompt, payload, answer, explanation, topic, rating) VALUES
(1, 1, 'mcq', 'Which of these is an intermediary device?',
 '{"options":["Switch","Laptop","Printer","IP phone"]}', '{"correct":0}',
 'A switch connects end devices and forwards data between them. Laptops, printers and IP phones are end devices.', 'Network components', 800),
(1, 1, 'mcq', 'What is a host on a network?',
 '{"options":["Any end device that sends or receives data","A device that only forwards data","The cable between two switches","The company that provides internet access"]}', '{"correct":0}',
 'Hosts are end devices: they are the source or destination of data.', 'Network components', 850),
(1, 1, 'matching', 'Match each item to its role in the network.',
 '{"left":["Laptop","Router","Fibre-optic cable","Wireless access point"],"right":["End device","Intermediary device that joins networks","Media that carries light","Intermediary device that connects wireless hosts"]}', NULL,
 'Laptops are end devices; routers join networks; fibre carries pulses of light; access points connect wireless hosts to the network.', 'Network components', 950),
(1, 1, 'fill', 'In the client-server model, the host that provides a service is the ___, and the host that requests it is the ___.',
 '{"text":"In the client-server model, the host that provides a service is the ___, and the host that requests it is the ___."}', '{"blanks":[["server"],["client"]]}',
 'Servers provide services such as web pages or files; clients request them.', 'Client-server', 900),
(1, 1, 'mcq', 'What is a drawback of a peer-to-peer network?',
 '{"options":["It’s hard to scale and secure","It needs a dedicated server","It’s expensive to set up","Devices can’t share files"]}', '{"correct":0}',
 'Peer-to-peer networks have no central server to manage users and security, so they don’t scale well.', 'Client-server', 1000),
(1, 1, 'mcq', 'Which network media carries data as pulses of light?',
 '{"options":["Fibre-optic cable","Copper twisted-pair","Coaxial cable","Wireless"]}', '{"correct":0}',
 'Fibre-optic cable sends light through glass or plastic fibres. Copper uses electrical signals; wireless uses radio waves.', 'Network media', 850),

(1, 2, 'mcq', 'Which best describes a LAN?',
 '{"options":["A network covering a small area, run by one organisation","A network linking cities, run by service providers","The worldwide network of networks","A private network for outside suppliers"]}', '{"correct":0}',
 'LANs cover a small geographic area such as a home or office, under one administration.', 'Network types', 800),
(1, 2, 'matching', 'Match each network type to its description.',
 '{"left":["Intranet","Extranet","Internet","WAN"],"right":["Private network for an organisation’s members","Gives outside partners secure access","Worldwide network of networks","Links LANs over long distances"]}', NULL,
 'Intranets are internal, extranets extend access to partners, the internet is global, and WANs connect distant LANs.', 'Network types', 1000),
(1, 2, 'mcq', 'Which network quality lets traffic take another path when a link fails?',
 '{"options":["Fault tolerance","Scalability","Quality of service","Confidentiality"]}', '{"correct":0}',
 'Fault-tolerant networks have redundant paths so a single failure doesn’t stop communication.', 'Reliable networks', 950),
(1, 2, 'fill', 'The company that connects your home to the internet is called an ___.',
 '{"text":"The company that connects your home to the internet is called an ___."}', '{"blanks":[["isp","internet service provider"]]}',
 'An ISP (internet service provider) connects homes and businesses to the internet.', 'Internet access', 850),
(1, 2, 'ordering', 'Order these networks from the smallest area to the largest.',
 '{"items":["Home LAN","Campus network","WAN between cities","The internet"]}', NULL,
 'A home LAN is smallest, a campus spans several buildings, a WAN links cities, and the internet spans the world.', 'Network types', 900),
(1, 2, 'mcq', 'What is QoS (quality of service) mainly used for?',
 '{"options":["Prioritising time-sensitive traffic such as voice and video","Encrypting data on the network","Adding switches as the network grows","Assigning IP addresses to hosts"]}', '{"correct":0}',
 'QoS gives priority to traffic that suffers from delay, like voice calls and video.', 'Reliable networks', 1050),

(1, 3, 'mcq', 'Which prompt shows privileged EXEC mode?',
 '{"options":["Switch#","Switch>","Switch(config)#","Switch(config-if)#"]}', '{"correct":0}',
 'The # prompt with no (config) means privileged EXEC. > is user EXEC.', 'IOS modes', 900),
(1, 3, 'ordering', 'Put these commands in order to rename a switch to S1, starting from user EXEC mode.',
 '{"items":["enable","configure terminal","hostname S1"]}', NULL,
 'enable moves to privileged EXEC, configure terminal to global config, where hostname can be set.', 'IOS modes', 950),
(1, 3, 'fill', 'To save the running configuration so it survives a reboot, enter: copy running-config ___',
 '{"text":"To save the running configuration so it survives a reboot, enter: copy running-config ___"}', '{"blanks":[["startup-config"]]}',
 'The startup-config is stored in NVRAM and loaded at boot.', 'Basic configuration', 1050),
(1, 3, 'mcq', 'Which remote access method encrypts the session?',
 '{"options":["SSH","Telnet","Console","AUX"]}', '{"correct":0}',
 'SSH encrypts the whole session. Telnet sends everything, including passwords, in plain text.', 'Device access', 950),
(1, 3, 'mcq', 'Why use the console port to set up a new switch?',
 '{"options":["It works before the switch has any network settings","It’s the fastest way to copy files","It encrypts all traffic","It’s the only way to reach privileged EXEC"]}', '{"correct":0}',
 'The console port is out-of-band: it needs no IP address, so it works on a brand-new device.', 'Device access', 1000),
(1, 3, 'matching', 'Match each IOS mode to what it’s for.',
 '{"left":["User EXEC","Privileged EXEC","Global configuration","Interface configuration"],"right":["Basic monitoring only","All show commands, reached with enable","Change device-wide settings","Settings for a single port"]}', NULL,
 'Each mode gives more control than the one before it.', 'IOS modes', 1100);

/* ---------------------------- World 2 ---------------------------- */

INSERT INTO levels (id, world_id, sort_order, title, lesson, questions_per_run, pass_pct) VALUES
(4, 3, 1, 'Binary Blitz',
'Computers store everything as **bits**, each a 0 or a 1. An IPv4 address is 32 bits, written as four 8-bit **octets** in decimal, like `192.168.1.10`.

Each bit in an octet has a place value:

- `128 64 32 16 8 4 2 1`

**Binary to decimal:** add the place values where there’s a 1. `10100000` = 128 + 32 = **160**.

**Decimal to binary:** go left to right. If the number is at least the place value, write 1 and subtract it; otherwise write 0. For 200: 128 (72 left), 64 (8 left), 0, 0, 8 (0 left), 0, 0, 0, giving `11001000`.

In Binary Blitz you have **20 seconds** per question. Speed comes with practice.', 6, 60);

INSERT INTO questions (world_id, level_id, type, prompt, payload, answer, explanation, topic, rating) VALUES
(3, 4, 'blitz', 'Binary to decimal, 4 bits',  '{"mode":"bin2dec","bits":4,"seconds":20}', NULL, 'Add the place values 8, 4, 2, 1 wherever there’s a 1.', 'Binary conversion', 750),
(3, 4, 'blitz', 'Binary to decimal, 8 bits',  '{"mode":"bin2dec","bits":8,"seconds":20}', NULL, 'Add the place values 128 to 1 wherever there’s a 1.', 'Binary conversion', 950),
(3, 4, 'blitz', 'Decimal to binary, 4 bits',  '{"mode":"dec2bin","bits":4,"seconds":20}', NULL, 'Subtract 8, 4, 2, 1 from left to right, writing 1 when it fits.', 'Binary conversion', 850),
(3, 4, 'blitz', 'Decimal to binary, 8 bits',  '{"mode":"dec2bin","bits":8,"seconds":20}', NULL, 'Subtract 128 to 1 from left to right, writing 1 when it fits.', 'Binary conversion', 1100),
(3, 4, 'mcq', 'What is the place value of the leftmost bit in an octet?',
 '{"options":["128","255","256","64"]}', '{"correct":0}', 'The eight place values are 128, 64, 32, 16, 8, 4, 2, 1.', 'Binary conversion', 800),
(3, 4, 'mcq', 'What is the largest decimal value one octet can hold?',
 '{"options":["255","256","128","1024"]}', '{"correct":0}', 'All eight bits set: 128+64+32+16+8+4+2+1 = 255.', 'Binary conversion', 850);

/* ---------------------------- World 5 ---------------------------- */

INSERT INTO levels (id, world_id, sort_order, title, lesson, questions_per_run, pass_pct) VALUES
(5, 6, 1, 'Subnet calculator',
'An IPv4 address has a **network** part and a **host** part. The **prefix length**, like `/24`, says how many of the 32 bits are the network part. The **subnet mask** says the same thing in dotted decimal: `/24` is `255.255.255.0`.

With a `/n` prefix there are 32 − n host bits, so 2^(32 − n) addresses. Two are reserved:

- the **network address**: all host bits 0
- the **broadcast address**: all host bits 1

So **usable hosts = 2^(32 − n) − 2**.

Example: `192.168.10.77/26`. The mask is `255.255.255.192`, so blocks are 64 addresses wide: .0, .64, .128, .192. Since 77 falls in the .64 block, the network is `192.168.10.64`, the broadcast is `192.168.10.127`, and hosts .65 to .126 are usable (62 hosts).

Every question here is generated fresh, so you can practise as much as you like.', 6, 60);

INSERT INTO questions (world_id, level_id, type, prompt, payload, answer, explanation, topic, rating) VALUES
(6, 5, 'subnet', 'Usable hosts for a prefix',   '{"mode":"hosts","minPrefix":24,"maxPrefix":30}',     NULL, 'Usable hosts = 2^(32 − prefix) − 2.', 'Subnetting', 900),
(6, 5, 'subnet', 'Mask from a prefix',          '{"mode":"mask","minPrefix":16,"maxPrefix":30}',      NULL, 'Write the prefix as 1-bits from the left, then convert each octet to decimal.', 'Subnetting', 950),
(6, 5, 'subnet', 'Network address, /24 to /30', '{"mode":"network","minPrefix":24,"maxPrefix":30}',   NULL, 'Find the block size (256 − last mask octet) and round the host down to a multiple of it.', 'Subnetting', 1050),
(6, 5, 'subnet', 'Broadcast address, /24 to /30','{"mode":"broadcast","minPrefix":24,"maxPrefix":30}', NULL, 'The broadcast is the next network address minus one.', 'Subnetting', 1150),
(6, 5, 'subnet', 'Network address, /16 to /23', '{"mode":"network","minPrefix":16,"maxPrefix":23}',   NULL, 'Here the interesting octet is the third one: find its block size and round down.', 'Subnetting', 1300);

-- The OSI and TCP/IP models (Network Fundamentals). Created in old World 1; schema_content.sql moves it into the Garage.
INSERT INTO levels (id, world_id, sort_order, title, lesson, questions_per_run, pass_pct) VALUES
(6, 2, 1, 'The OSI model', 'Networks are easier to understand in **layers**. Each layer does one job and only talks to the layers directly above and below it.\n\nThe **OSI model** has seven layers. From the bottom:\n\n1. **Physical**: bits on the wire, light or radio.\n2. **Data link**: frames between devices on the same network, using **MAC addresses**. Switches work here.\n3. **Network**: packets between networks, using **IP addresses**. Routers work here.\n4. **Transport**: segments between applications, using **port numbers** (TCP and UDP).\n5. **Session**, 6. **Presentation**, 7. **Application**: what the user''s program sees.\n\nThe **TCP/IP model** is the one the internet actually uses. It folds those seven into four: Network access (OSI 1 and 2), Internet (3), Transport (4) and Application (5 to 7).\n\nAs data goes down the stack, each layer wraps it in its own header. This is **encapsulation**, and the result has a different name at each layer, its **PDU**: a **segment** at Transport, a **packet** at Network, a **frame** at Data link, and **bits** at Physical. The receiver unwraps it on the way back up.', 4, 70);

INSERT INTO questions (world_id, level_id, type, prompt, payload, answer, explanation, topic, rating) VALUES
(2, 6, 'mcq', 'At which OSI layer do routers work?',
 '{"options":["Layer 3","Layer 2","Layer 1","Layer 7"]}', '{"correct":0}',
 'Routers forward packets between networks by IP address, which is Layer 3 (Network). Switches work at Layer 2.', 'OSI model', 850),
(2, 6, 'mcq', 'Which layer adds MAC addresses?',
 '{"options":["Data link","Network","Transport"]}', '{"correct":0}',
 'The data link layer (Layer 2) builds frames with source and destination MAC addresses. The network layer adds IP addresses.', 'OSI model', 900),
(2, 6, 'ordering', 'Order the layers from 1 to 4.',
 '{"items":["Physical","Data link","Network","Transport"]}', NULL,
 'From the bottom: Physical (1), Data link (2), Network (3), Transport (4).', 'OSI model', 900),
(2, 6, 'matching', 'Match each layer to its PDU.',
 '{"left":["Transport","Network","Data link"],"right":["Segment","Packet","Frame"]}', NULL,
 'Each layer names its unit of data: segments at Transport, packets at Network, frames at Data link.', 'PDUs', 1000),
(2, 6, 'fill', 'Adding headers as data moves down the stack is called ___.',
 '{"text":"Adding headers as data moves down the stack is called ___."}', '{"blanks":[["encapsulation"]]}',
 'Each layer wraps the data in its own header on the way down; the receiver removes them on the way up (de-encapsulation).', 'Encapsulation', 950);
