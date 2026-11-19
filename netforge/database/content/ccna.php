<?php
declare(strict_types=1);

/**
 * CCNA 200-301 question bank, one world per area of the game. Loaded by tools/seed-content.php,
 * which checks every question with the same rules as the lecturer editor (QuestionInput).
 *
 * World 0 Garage      Network Fundamentals (20%)
 * World 1 Office      Network Access (20%), IP Connectivity (25%)
 * World 2 Datacentre  IP Services (10%), Security Fundamentals (15%), Automation and Programmability (10%)
 *
 * The Garage world's first levels (and Binary Blitz, Subnet calculator) already exist; this file adds
 * the levels after them. Levels are matched by title, questions by prompt, so it's safe to load again.
 *
 * Question helpers (the game shuffles options, so the correct one is simply listed first):
 *   mcq(prompt, [correct, wrong...], topic, rating, explanation)
 *   pairs(prompt, ['left = right', ...], ...)    matching; each left goes with its right
 *   order(prompt, [first, second, ...], ...)     ordering; listed in the correct order
 *   fill(prompt with ___, [['accepted', 'also'], ...], ...)  one entry per blank
 */

$mcq = fn (string $p, array $opts, string $topic, int $r, string $why) => ['type' => 'mcq', 'prompt' => $p, 'choices' => $opts, 'answer' => '1', 'topic' => $topic, 'rating' => $r, 'explanation' => $why];
$pairs = fn (string $p, array $pairs, string $topic, int $r, string $why) => ['type' => 'matching', 'prompt' => $p, 'choices' => $pairs, 'answer' => '', 'topic' => $topic, 'rating' => $r, 'explanation' => $why];
$order = fn (string $p, array $items, string $topic, int $r, string $why) => ['type' => 'ordering', 'prompt' => $p, 'choices' => $items, 'answer' => '', 'topic' => $topic, 'rating' => $r, 'explanation' => $why];
$fill = fn (string $p, array $blanks, string $topic, int $r, string $why) => ['type' => 'fill', 'prompt' => $p, 'choices' => [], 'answer' => $blanks, 'topic' => $topic, 'rating' => $r, 'explanation' => $why];

defined('FUND') || define('FUND', 'Network Fundamentals');
defined('ACCESS') || define('ACCESS', 'Network Access');
defined('CONN') || define('CONN', 'IP Connectivity');
defined('SERV') || define('SERV', 'IP Services');
defined('SEC') || define('SEC', 'Security Fundamentals');
defined('AUTO') || define('AUTO', 'Automation and Programmability');

return [
    /* ======================================================================= Garage */
    0 => [
        [
            'title' => 'Ethernet, MAC and ARP', 'sort' => 5, 'perRun' => 5, 'domain' => FUND,
            'lesson' => <<<'MD'
Inside a LAN, devices find each other with **MAC addresses**: 48-bit hardware addresses written as 12 hex digits, like `00:1A:2B:3C:4D:5E`. Every Ethernet **frame** carries a destination MAC, a source MAC, an EtherType saying what's inside (usually IPv4 or IPv6), the data, and an **FCS** check at the end.

A **switch** learns which MAC lives on which port by reading the source address of every frame, and keeps a MAC address table. When it knows the destination it forwards the frame out of that one port. When it doesn't, it **floods** the frame out of every port except the one it came in on.

Hosts know IP addresses, not MACs. **ARP** fills the gap for IPv4:

- The host broadcasts "who has 192.168.1.1?"
- The owner replies by unicast with its MAC.
- The host caches the answer in its ARP table.

For a destination on another network, the host ARPs for its **default gateway**, not the far host.
MD,
            'questions' => [
                $mcq('How long is an Ethernet MAC address?', ['48 bits (12 hex digits)', '32 bits (4 octets)', '64 bits (16 hex digits)', '128 bits (32 hex digits)'],
                    'MAC addresses', 800, 'A MAC address is 48 bits, written as 12 hexadecimal digits. 32 bits is an IPv4 address and 128 bits is IPv6.'),
                $mcq('A switch receives a frame for a MAC address that isn’t in its MAC address table. What does it do?', ['Floods it out of every port except the one it arrived on', 'Drops the frame', 'Sends it back to the sender', 'Forwards it to the default gateway'],
                    'Switching', 900, 'An unknown unicast is flooded so it still reaches the right host. When that host replies, the switch learns its port.'),
                $mcq('What is ARP used for?', ['Finding the MAC address that goes with a known IPv4 address on the local network', 'Finding the IPv4 address that goes with a known MAC address', 'Giving hosts an IP address automatically', 'Turning names like cisco.com into IP addresses'],
                    'ARP', 850, 'ARP resolves a local IPv4 address to a MAC address so a frame can be built. DHCP hands out addresses and DNS resolves names.'),
                $fill('An ARP request is sent as a ___ frame, and the ARP reply comes back as a ___ frame.', [['broadcast'], ['unicast']],
                    'ARP', 1000, 'The asker doesn’t know the MAC yet, so the request goes to everyone (FF:FF:FF:FF:FF:FF). The owner answers the asker directly.'),
                $pairs('Match each Ethernet frame field to what it holds.', ['Destination MAC = Where the frame is going', 'Source MAC = Who sent the frame', 'EtherType = Which Layer 3 protocol is inside', 'FCS = A check for errors in the frame'],
                    'Ethernet frames', 1050, 'The switch forwards on the destination MAC, learns from the source MAC, and drops frames whose FCS check fails.'),
            ],
        ],
        [
            'title' => 'Cables and interfaces', 'sort' => 6, 'perRun' => 5, 'domain' => FUND,
            'lesson' => <<<'MD'
Copper Ethernet ports come in two styles. Hosts and routers transmit on one pair of wires (**MDI**); switches transmit on the other (**MDI-X**).

- **Straight-through** cable joins unlike devices: PC to switch, router to switch.
- **Crossover** cable joins like devices: switch to switch, PC to PC, router to router.
- Most modern ports have **Auto-MDIX** and fix this themselves, but the CCNA (and NetForge) still expects the right cable.

Twisted pair runs up to **100 metres**. Cat5e carries 1 Gbps, Cat6a 10 Gbps. **Fibre** carries light: **multimode** for shorter runs inside a building, **single-mode** for long distances. A **console** (rollover) cable is for managing a device, not for data.

`show interfaces` tells you how a port is doing:

- **up / up**: working.
- **down / down**: no physical link, so check the cable.
- **up / down**: the cable is fine, but Layer 2 isn't (for example, mismatched settings).
- **administratively down**: someone typed `shutdown`.

A **duplex mismatch** (one side full, the other half) still links up but runs slowly, with late collisions on the half-duplex side.
MD,
            'questions' => [
                $mcq('Which cable joins a PC to a switch port without Auto-MDIX?', ['Straight-through', 'Crossover', 'Console (rollover)', 'Single-mode fibre'],
                    'Cabling', 800, 'A PC (MDI) and a switch (MDI-X) are unlike devices, so a straight-through cable lines their transmit and receive pairs up.'),
                $mcq('Two switches without Auto-MDIX need a copper link between them. Which cable works?', ['Crossover', 'Straight-through', 'Console (rollover)', 'Coaxial'],
                    'Cabling', 850, 'Both switch ports are MDI-X, so the cable has to swap the pairs: a crossover.'),
                $pairs('Match each cable to where it fits best.', ['Cat5e copper = 1 Gbps to desks, up to 100 m', 'Cat6a copper = 10 Gbps over copper, up to 100 m', 'Multimode fibre = Shorter fibre runs inside a building', 'Single-mode fibre = Long links between buildings or sites'],
                    'Cabling', 950, 'Copper is limited to 100 m. Single-mode fibre’s narrow core carries light much further than multimode.'),
                $mcq('`show interfaces` reports "GigabitEthernet0/1 is up, line protocol is down". What does that tell you?', ['The physical link works, but something at Layer 2 doesn’t', 'The cable is unplugged', 'Someone shut the interface down', 'Everything is working normally'],
                    'Interfaces', 1100, 'The first "up" is Layer 1 and the line protocol is Layer 2. Down/down points at the cable; administratively down means `shutdown`.'),
                $mcq('A link works but is very slow, and the interface shows late collisions. What is the most likely cause?', ['A duplex mismatch between the two ends', 'The wrong VLAN on the port', 'A missing default gateway', 'A DNS server that is down'],
                    'Interfaces', 1150, 'When one side runs half duplex and the other full, the half-duplex side sees late collisions and throughput drops.'),
            ],
        ],
        [
            'title' => 'IPv4 addressing', 'sort' => 8, 'perRun' => 4, 'domain' => FUND,
            'lesson' => <<<'MD'
An IPv4 address is 32 bits. The **prefix** (like `/24`) says how many of those bits name the network; the rest name the host.

Some ranges are special:

- **Private** addresses work only inside a network and need NAT to reach the internet: `10.0.0.0/8`, `172.16.0.0/12` (172.16 to 172.31) and `192.168.0.0/16`.
- **Loopback** `127.0.0.1` is the host talking to itself.
- **Link-local** `169.254.x.x` (APIPA) is what a host gives itself when no DHCP server answers.
- **Multicast** is `224.0.0.0/4`, and `255.255.255.255` is a broadcast to everyone on the local network.

A host only talks directly to addresses in its own subnet. For everything else it sends packets to its **default gateway**, which must be in the **same subnet** as the host.
MD,
            'questions' => [
                $mcq('Which of these is a private IPv4 address?', ['172.16.5.4', '172.32.1.1', '11.0.0.1', '192.169.1.1'],
                    'IPv4 addressing', 900, 'The private ranges are 10.0.0.0/8, 172.16.0.0 to 172.31.255.255, and 192.168.0.0/16. 172.32.1.1 is just outside the 172.16/12 block.'),
                $mcq('A laptop shows the address 169.254.20.7. What most likely happened?', ['It couldn’t reach a DHCP server, so it gave itself a link-local address', 'Someone set that address by hand', 'It is using its loopback address', 'It joined a multicast group'],
                    'IPv4 addressing', 950, '169.254.0.0/16 is APIPA. A host picks one when DHCP fails, which lets it talk only to its own link.'),
                $pairs('Match each address to what it is.', ['10.0.0.0/8 = A private range', '127.0.0.1 = Loopback, the host itself', '224.0.0.5 = Multicast', '255.255.255.255 = Broadcast to the local network'],
                    'IPv4 addressing', 1000, '224.0.0.0/4 is multicast (224.0.0.5 is used by OSPF). 255.255.255.255 never leaves the local network.'),
                $fill('A host’s default gateway must be in the ___ subnet as the host.', [['same']],
                    'Default gateway', 850, 'A host can only reach its gateway directly, so the gateway’s address has to sit in the host’s own subnet.'),
            ],
        ],
        [
            'title' => 'IPv6 addressing', 'sort' => 10, 'perRun' => 5, 'domain' => FUND,
            'lesson' => <<<'MD'
IPv6 addresses are **128 bits**, written as eight groups of four hex digits. Two rules shorten them:

- Drop leading zeros in a group: `0db8` becomes `db8`.
- Replace one run of all-zero groups with `::`, once per address.

So `2001:0db8:0000:0000:0000:0000:0000:0001` becomes `2001:db8::1`.

Address types you'll see:

- **Global unicast** `2000::/3`: public, routable on the internet.
- **Link-local** `FE80::/10`: every IPv6 interface has one; it never leaves the link.
- **Unique local** `FC00::/7`: private, like IPv4's 10.x.
- **Multicast** `FF00::/8`. IPv6 has no broadcast at all.

IPv6 has no ARP either. **NDP** (Neighbor Discovery, part of ICMPv6) finds neighbours' MAC addresses and routers. With **SLAAC**, a host takes the network **prefix** from a router's Router Advertisement and makes up its own interface ID, often with EUI-64.
MD,
            'questions' => [
                $mcq('How long is an IPv6 address?', ['128 bits', '32 bits', '48 bits', '64 bits'],
                    'IPv6 addressing', 800, 'IPv6 addresses are 128 bits. A /64 prefix is common, leaving 64 bits for the interface ID.'),
                $mcq('What is the shortest correct form of 2001:0db8:0000:0000:0000:0000:0000:0001?', ['2001:db8::1', '2001:db8::0::1', '2001:db8:1', '2001:db8:0:0:1'],
                    'IPv6 addressing', 950, 'Drop leading zeros, then replace the run of zero groups with :: once. Using :: twice would be ambiguous, and leaving groups out without :: changes the address.'),
                $pairs('Match each IPv6 prefix to its address type.', ['2000::/3 = Global unicast', 'FE80::/10 = Link-local', 'FC00::/7 = Unique local', 'FF00::/8 = Multicast'],
                    'IPv6 addressing', 1050, 'Global unicast is routable on the internet, link-local stays on one link, unique local is private, and FF00::/8 is multicast.'),
                $mcq('IPv6 has no ARP. What finds a neighbour’s MAC address instead?', ['Neighbor Discovery Protocol (NDP), part of ICMPv6', 'DHCPv6', 'A broadcast ARP request', 'EUI-64'],
                    'IPv6 addressing', 1000, 'NDP’s Neighbor Solicitation and Advertisement messages do ARP’s job, sent to multicast addresses instead of broadcast.'),
                $fill('With SLAAC, a host takes the network ___ from a Router Advertisement and creates its own interface ID.', [['prefix', 'network prefix']],
                    'IPv6 addressing', 1100, 'SLAAC means no DHCP server is needed for the address: the router advertises the prefix, and the host fills in the last 64 bits itself.'),
            ],
        ],
        [
            'title' => 'Wireless, virtualisation and the cloud', 'sort' => 11, 'perRun' => 5, 'domain' => FUND,
            'lesson' => <<<'MD'
**Wireless LANs** (Wi-Fi, IEEE 802.11) use radio in the 2.4 GHz, 5 GHz and 6 GHz bands. The network's name is its **SSID**. In 2.4 GHz only channels **1, 6 and 11** don't overlap, so neighbouring access points should use those.

**Virtualisation** runs many virtual machines on one physical server:

- A **type 1 hypervisor** runs directly on the hardware (bare metal). It's what datacentres use.
- A **type 2 hypervisor** runs as an app on a normal operating system, like a lab VM on your laptop.
- **Containers** share the host's OS kernel instead of each carrying a full operating system, so they start faster and use less memory.

**Cloud** services come in three main models:

- **SaaS**: you use finished software, like webmail.
- **PaaS**: you deploy your own code on the provider's platform.
- **IaaS**: you rent virtual servers, storage and networks and manage them yourself.
MD,
            'questions' => [
                $mcq('Which 2.4 GHz Wi-Fi channels don’t overlap each other?', ['1, 6 and 11', '1, 2 and 3', '1, 3 and 5', '6, 7 and 8'],
                    'Wireless basics', 900, 'Each 2.4 GHz channel is about 22 MHz wide but only 5 MHz apart, so only channels five apart, like 1, 6 and 11, stay clear of each other.'),
                $mcq('What is an SSID?', ['The name of a wireless network', 'The MAC address of an access point’s radio', 'The encryption key for the network', 'The channel the network uses'],
                    'Wireless basics', 750, 'The SSID is the network name clients see. The radio’s MAC address is the BSSID.'),
                $mcq('What is a type 1 hypervisor?', ['One that runs directly on the server hardware', 'One that runs as an app on a normal operating system', 'A program that runs containers', 'A virtual switch inside a VM'],
                    'Virtualisation', 950, 'Type 1 (bare metal) hypervisors like ESXi run on the hardware itself. Type 2, like VirtualBox, runs on top of an OS.'),
                $pairs('Match each cloud service model to what you get.', ['SaaS = Finished software you just use, like webmail', 'PaaS = A platform to deploy your own code on', 'IaaS = Virtual servers, storage and networks to run yourself'],
                    'Cloud', 1000, 'The further from IaaS toward SaaS, the more the provider manages for you.'),
                $mcq('How do containers differ from virtual machines?', ['Containers share the host’s OS kernel, so they are lighter', 'Each container carries its own full operating system', 'Containers need a type 1 hypervisor to run', 'Containers can’t use the network'],
                    'Virtualisation', 1050, 'A VM includes a whole guest OS; containers package just the app and its libraries and share the host’s kernel.'),
            ],
        ],
    ],

    /* ======================================================================= Office */
    1 => [
        [
            'title' => 'VLANs and trunking', 'sort' => 1, 'perRun' => 5, 'domain' => ACCESS,
            'lesson' => <<<'MD'
A **VLAN** splits one switch into separate virtual LANs. Each VLAN is its own **broadcast domain**, so staff, guests and servers can share hardware but stay apart.

- An **access port** belongs to one VLAN and connects an end device.
- A **trunk** carries many VLANs between switches (or to a router) by adding an **802.1Q** tag: 4 bytes holding a 12-bit VLAN ID, so VLANs 1 to 4094.
- Frames in the **native VLAN** (VLAN 1 by default) cross a trunk untagged.

```
interface g0/2
 switchport mode access
 switchport access vlan 10
```

Hosts in different VLANs need a **Layer 3 device** to talk: a router with one subinterface per VLAN (**router-on-a-stick**), or a Layer 3 switch with SVIs.

```
interface g0/0.10
 encapsulation dot1Q 10
 ip address 192.168.10.1 255.255.255.0
```
MD,
            'questions' => [
                $mcq('What does putting ports in different VLANs do?', ['Splits the switch into separate broadcast domains', 'Doubles the speed of each port', 'Joins two routers together', 'Encrypts traffic between ports'],
                    'VLANs', 800, 'Each VLAN is its own broadcast domain: a broadcast in VLAN 10 never reaches VLAN 20.'),
                $mcq('What does 802.1Q add to a frame on a trunk?', ['A 4-byte tag holding the VLAN ID', 'A new IP header', 'A second source MAC address', 'An encrypted trailer'],
                    'Trunking', 900, 'The 802.1Q tag sits between the source MAC and the EtherType and carries a 12-bit VLAN ID.'),
                $mcq('What is special about the native VLAN on an 802.1Q trunk?', ['Its frames cross the trunk untagged', 'Its frames are tagged twice', 'Its frames are always dropped', 'Only management traffic may use it'],
                    'Trunking', 1000, 'Untagged frames on a trunk belong to the native VLAN, VLAN 1 unless you change it. Both ends must agree on it.'),
                $fill('To make G0/2 an access port in VLAN 10: switchport mode ___, then switchport access vlan ___.', [['access'], ['10']],
                    'VLANs', 900, '`switchport mode access` stops the port trunking, and `switchport access vlan 10` puts it in VLAN 10.'),
                $mcq('The 802.1Q VLAN ID field is 12 bits. Which VLAN IDs can you use?', ['1 to 4094', '1 to 1005 only', '0 to 255', '1 to 65535'],
                    'Trunking', 1100, '12 bits give 0 to 4095, and 0 and 4095 are reserved. 1 to 1005 is the normal range; 1006 to 4094 is the extended range.'),
                $mcq('Hosts in VLAN 10 need to reach hosts in VLAN 20. What do you need?', ['A Layer 3 device: a router or a Layer 3 switch', 'Just a trunk between the switches', 'Both VLANs set as the native VLAN', 'Spanning Tree enabled'],
                    'Inter-VLAN routing', 950, 'VLANs are separate IP subnets, so traffic between them has to be routed: router-on-a-stick or SVIs on a Layer 3 switch.'),
                $order('Put the router-on-a-stick commands for VLAN 10 in order.', ['configure terminal', 'interface g0/0.10', 'encapsulation dot1Q 10', 'ip address 192.168.10.1 255.255.255.0'],
                    'Inter-VLAN routing', 1150, 'A subinterface needs its 802.1Q encapsulation before IOS accepts an IP address on it.'),
            ],
        ],
        [
            'title' => 'Spanning Tree and EtherChannel', 'sort' => 2, 'perRun' => 5, 'domain' => ACCESS,
            'lesson' => <<<'MD'
Extra links between switches give redundancy, but Ethernet frames have no TTL, so a loop would circle forever in a **broadcast storm**. **Spanning Tree (STP, 802.1D; Rapid STP, 802.1w)** blocks just enough ports to break every loop.

1. The switch with the lowest **bridge ID** (priority, default **32768**, then MAC address) becomes the **root bridge**.
2. Every other switch picks a **root port**: its best path to the root.
3. Each link gets one **designated port** that forwards.
4. Anything left becomes an **alternate** (blocked) port, ready if a link fails.

**PortFast** lets access ports for end devices skip straight to forwarding. **BPDU Guard** error-disables a PortFast port if a switch is plugged into it.

**EtherChannel** bundles several physical links into one logical link, so STP sees one link and doesn't block any of them. Negotiate it with **LACP** (open standard) or **PAgP** (Cisco), or force it with mode **on**.
MD,
            'questions' => [
                $mcq('Why do switches run Spanning Tree Protocol?', ['To stop Layer 2 loops by blocking redundant paths', 'To share traffic evenly across all links', 'To assign ports to VLANs', 'To route between subnets'],
                    'Spanning Tree', 800, 'Without STP, redundant links would loop broadcasts forever. STP blocks some ports and unblocks them if a link fails.'),
                $mcq('How is the root bridge chosen?', ['Lowest bridge ID: priority first, then MAC address', 'Highest bridge ID', 'The switch with the most ports', 'The switch with the fastest uplink'],
                    'Spanning Tree', 900, 'Lower wins. With every priority at the default, the oldest switch (lowest MAC) often wins, so set the priority yourself.'),
                $mcq('What is the default STP bridge priority on a Cisco switch?', ['32768', '4096', '0', '65535'],
                    'Spanning Tree', 950, 'The default is 32768. Priorities go up in steps of 4096; set 4096 or 0 to make a switch root.'),
                $pairs('Match each port role or feature to what it does.', ['Root port = The switch’s best path toward the root bridge', 'Designated port = The forwarding port on each link', 'Alternate port = A blocked backup path to the root', 'PortFast = Takes an access port straight to forwarding'],
                    'Spanning Tree', 1100, 'Root and designated ports forward; alternate ports block until needed. PortFast is only for ports with end devices.'),
                $mcq('What does BPDU Guard do?', ['Error-disables a PortFast port that receives a BPDU', 'Makes the switch the root bridge', 'Speeds up STP on trunk ports', 'Encrypts BPDUs between switches'],
                    'Spanning Tree', 1050, 'A BPDU on an end-device port means someone plugged in a switch. BPDU Guard shuts the port before it can cause a loop.'),
                $mcq('What does EtherChannel do?', ['Bundles several links into one logical link so STP doesn’t block them', 'Carries several VLANs over one link', 'Connects wireless access points to a controller', 'Picks the best route to a network'],
                    'EtherChannel', 950, 'STP treats the bundle as one link, so all its member links forward and share the load.'),
                $pairs('Match each EtherChannel option to what it is.', ['LACP = The open IEEE standard for negotiating a bundle', 'PAgP = Cisco’s own protocol for negotiating a bundle', 'Mode on = Forms the bundle with no negotiation at all'],
                    'EtherChannel', 1150, 'LACP (active/passive) and PAgP (desirable/auto) check both ends agree. Mode on skips the checks, so a mismatch can cause a loop.'),
            ],
        ],
        [
            'title' => 'Wireless LANs', 'sort' => 3, 'perRun' => 5, 'domain' => ACCESS,
            'lesson' => <<<'MD'
Small sites use **autonomous** access points: each one is set up separately, like a home router.

Bigger sites use **lightweight APs** run by a **Wireless LAN Controller (WLC)**. The WLC holds the configuration, picks channels and power, and handles roaming. Each AP builds a **CAPWAP** tunnel to the controller and sends its control messages through it.

AP modes include:

- **Local**: serves clients and tunnels their traffic to the WLC. Connect it to an **access port**.
- **FlexConnect**: switches client traffic locally at a branch, and keeps working if the WLC link fails. Connect it to a trunk.
- **Monitor**: only listens, to spot rogue APs and attacks.
- **Sniffer**: captures wireless traffic for analysis.

A WLC's own ports usually connect to a switch as a trunk, often bundled as a **LAG**.
MD,
            'questions' => [
                $mcq('In a lightweight AP design, what does the Wireless LAN Controller do?', ['Centrally configures and manages many APs', 'Broadcasts the SSID itself', 'Hands out IP addresses by DHCP only', 'Connects the network to the internet'],
                    'WLAN architecture', 900, 'Lightweight APs get their settings from the WLC, which also manages channels, power and roaming.'),
                $mcq('What is CAPWAP?', ['The tunnel protocol between lightweight APs and the WLC', 'A wireless encryption standard', 'The 5 GHz channel plan', 'A kind of SSID'],
                    'WLAN architecture', 1000, 'CAPWAP carries control messages, and in local mode client data, between each AP and its controller.'),
                $pairs('Match each AP mode to what it does.', ['Local = Serves clients and tunnels their traffic to the WLC', 'FlexConnect = Switches client traffic locally at a branch', 'Monitor = Only listens, to detect rogue APs', 'Sniffer = Captures wireless traffic for analysis'],
                    'WLAN architecture', 1100, 'Local is the default. FlexConnect suits branches with a remote WLC. Monitor and Sniffer don’t serve clients.'),
                $mcq('How is an autonomous AP different from a lightweight AP?', ['Each autonomous AP is configured on its own', 'An autonomous AP needs a WLC to work', 'An autonomous AP can’t broadcast an SSID', 'An autonomous AP only works on 5 GHz'],
                    'WLAN architecture', 950, 'Autonomous APs are standalone. That’s fine for a few APs but painful for fifty.'),
                $mcq('What kind of switch port should a lightweight AP in local mode connect to?', ['An access port, since client traffic travels inside the CAPWAP tunnel', 'A trunk carrying every client VLAN', 'A routed port with no VLAN', 'A console port'],
                    'WLAN architecture', 1200, 'In local mode, client traffic is tunnelled to the WLC, so the AP only needs its own management VLAN. FlexConnect APs need a trunk.'),
            ],
        ],
        [
            'title' => 'Port security and access control', 'sort' => 4, 'perRun' => 5, 'domain' => ACCESS,
            'lesson' => <<<'MD'
**Port security** limits which MAC addresses may use a switch port:

```
interface g0/5
 switchport mode access
 switchport port-security
 switchport port-security maximum 2
 switchport port-security mac-address sticky
```

**Sticky** learns the first MACs it sees and saves them in the running config. When an unknown MAC shows up, the **violation mode** decides what happens:

- **Protect**: drop its frames silently.
- **Restrict**: drop them, log it and count the violation.
- **Shutdown** (the default): put the port in **err-disabled** state.

To recover an err-disabled port, fix the cause, then enter `shutdown` and `no shutdown` on it (or set up `errdisable recovery`).

**802.1X** goes further: a device must authenticate, usually against a RADIUS server, before the port passes any of its traffic.
MD,
            'questions' => [
                $mcq('What is the default port security violation mode?', ['Shutdown: the port goes err-disabled', 'Protect: frames are dropped silently', 'Restrict: frames are dropped and logged', 'None: violations are allowed'],
                    'Port security', 900, 'Unless you choose otherwise, a violation error-disables the port.'),
                $pairs('Match each violation mode to what happens.', ['Protect = Drops frames from unknown MACs without logging', 'Restrict = Drops them, logs and counts the violation', 'Shutdown = Error-disables the whole port'],
                    'Port security', 1000, 'Protect and Restrict keep the port up for the allowed MACs; Shutdown takes it down for everyone.'),
                $fill('Allow at most 2 MACs: switchport port-security ___ 2. Learn them into the running config: switchport port-security mac-address ___.', [['maximum'], ['sticky']],
                    'Port security', 1050, '`maximum` sets the limit and `sticky` learns the addresses so you don’t type them in by hand.'),
                $mcq('What does 802.1X add to a switch port?', ['The device has to authenticate before the port passes its traffic', 'Faster speeds for authenticated users', 'A second VLAN for voice', 'Encryption of all traffic on the port'],
                    'Access control', 1100, 'With 802.1X the switch asks the device for credentials and checks them with a RADIUS server before letting it on.'),
                $mcq('A port is err-disabled after a port security violation. Once the cause is fixed, how do you bring it back by hand?', ['Enter shutdown, then no shutdown on the interface', 'Reload the switch', 'Delete the VLAN', 'Run clear mac address-table'],
                    'Port security', 1000, 'Bouncing the interface clears the err-disabled state. `errdisable recovery` can do it automatically after a timer.'),
            ],
        ],
        [
            'title' => 'Routing tables and administrative distance', 'sort' => 5, 'perRun' => 5, 'domain' => CONN,
            'lesson' => <<<'MD'
A router forwards each packet by looking up its destination in the **routing table** (`show ip route`). Each entry starts with a code:

- **C** connected network, **L** the router's own address on it (/32)
- **S** static, **O** OSPF, **D** EIGRP, **R** RIP
- `*` marks a candidate default route

When several entries match, the **longest prefix** wins: for 10.1.1.5, a /24 beats a /16, which beats a /8.

When two sources offer the same prefix, the router trusts the lowest **administrative distance (AD)**:

- Connected 0, static 1, EIGRP 90, OSPF 110, RIP 120.

**Metric** is different: it only compares routes from the same protocol (OSPF cost, RIP hop count).

The **gateway of last resort** is the default route, used when nothing else matches.
MD,
            'questions' => [
                $mcq('In `show ip route`, what does the code C mean?', ['A directly connected network', 'A static route', 'A route learned by OSPF', 'The default route'],
                    'Routing tables', 750, 'C is a connected network. Its partner L is the router’s own address on that network.'),
                $pairs('Match each routing table code to its source.', ['L = The router’s own interface address', 'S = A static route', 'O = Learned by OSPF', 'D = Learned by EIGRP'],
                    'Routing tables', 900, 'D comes from EIGRP’s old name, DUAL. R is RIP.'),
                $mcq('A router has routes to 10.0.0.0/8, 10.1.0.0/16 and 10.1.1.0/24. Which does it use for a packet to 10.1.1.5?', ['10.1.1.0/24', '10.1.0.0/16', '10.0.0.0/8', 'It load-balances across all three'],
                    'Routing tables', 1000, 'Longest prefix match: the most specific route that contains the address wins.'),
                $pairs('Match each route source to its default administrative distance.', ['Connected = 0', 'Static = 1', 'EIGRP = 90', 'OSPF = 110', 'RIP = 120'],
                    'Administrative distance', 1100, 'Lower AD is more trusted. These five are worth knowing by heart.'),
                $mcq('OSPF and RIP both offer a route to 172.16.0.0/16. Which goes in the routing table?', ['The OSPF route, because its AD of 110 is lower than RIP’s 120', 'The RIP route, because it has fewer hops', 'Both, sharing the traffic', 'Neither, because they conflict'],
                    'Administrative distance', 1000, 'Between different sources, AD decides. Metrics from different protocols can’t be compared.'),
                $mcq('What does administrative distance measure?', ['How trustworthy a route’s source is; lower is preferred', 'The number of routers to the destination', 'The bandwidth of the path', 'The delay on the path'],
                    'Administrative distance', 900, 'AD ranks sources. Metric ranks routes within one source.'),
                $mcq('What is the gateway of last resort?', ['The default route, used when nothing more specific matches', 'The router with the highest priority', 'The first route added to the table', 'A backup ISP link that is always idle'],
                    'Routing tables', 850, 'It comes from a default route (0.0.0.0/0). Without one, packets with no matching route are dropped.'),
            ],
        ],
        [
            'title' => 'Static and default routes', 'sort' => 6, 'perRun' => 5, 'domain' => CONN,
            'lesson' => <<<'MD'
A **static route** is one you type in:

```
ip route 192.168.2.0 255.255.255.0 10.0.0.2
```

That says "reach 192.168.2.0/24 through the **next hop** 10.0.0.2". A static route can also name an exit interface, or both.

- A **default route** matches everything: `ip route 0.0.0.0 0.0.0.0 203.0.113.1`. In IPv6 it's `ipv6 route ::/0 2001:db8::1`.
- A **host route** points at a single address: a /32 in IPv4, a /128 in IPv6.
- A **floating static route** has a higher AD than the main route, like `ip route 0.0.0.0 0.0.0.0 198.51.100.1 5`. It only goes into the table when the main route disappears.

IPv6 routing is off by default on Cisco routers: turn it on with `ipv6 unicast-routing`.

Static routes are simple and predictable, but they don't adapt: if the network changes, someone has to edit them by hand.
MD,
            'questions' => [
                $fill('Write the IPv4 default route via 203.0.113.1: ip route ___ ___ 203.0.113.1', [['0.0.0.0'], ['0.0.0.0']],
                    'Static routing', 900, 'Network 0.0.0.0 with mask 0.0.0.0 matches every destination.'),
                $mcq('What is a floating static route?', ['A static route with a higher AD, used only if the main route fails', 'A static route that moves between interfaces', 'A route learned from a DHCP server', 'A static route with no next hop'],
                    'Static routing', 1050, 'Giving it an AD above the main route keeps it out of the table until the main route disappears.'),
                $mcq('In `ip route 192.168.2.0 255.255.255.0 10.0.0.2`, what is 10.0.0.2?', ['The next-hop router’s address', 'The router’s own interface address', 'The destination host', 'The subnet mask'],
                    'Static routing', 850, 'Packets for 192.168.2.0/24 are sent to the router at 10.0.0.2, which must be reachable from this router.'),
                $mcq('Which command adds an IPv6 default route?', ['ipv6 route ::/0 2001:db8::1', 'ip route 0.0.0.0 0.0.0.0 2001:db8::1', 'ipv6 route ::/128 2001:db8::1', 'ipv6 route FF00::/8 2001:db8::1'],
                    'Static routing', 1000, '::/0 is the IPv6 "match everything" prefix. ::/128 is a single (unspecified) address, and FF00::/8 is multicast.'),
                $mcq('What is a host route?', ['A route to one single address: /32 in IPv4', 'A route a host learns from its gateway', 'The default route on a PC', 'A route to the router’s own loopback only'],
                    'Static routing', 950, 'A /32 (or /128 in IPv6) matches exactly one address.'),
                $mcq('Which command lets a Cisco router forward IPv6 packets?', ['ipv6 unicast-routing', 'ipv6 enable', 'ip routing ipv6', 'ipv6 address autoconfig'],
                    'IPv6 routing', 1050, 'IPv6 routing is off by default. `ipv6 enable` only turns on link-local addressing on one interface.'),
                $mcq('What is the main downside of static routes?', ['They don’t adapt when the network changes, so they have to be updated by hand', 'They use a lot of CPU', 'They can’t be used for default routes', 'They only work with IPv6'],
                    'Static routing', 850, 'Static routes cost nothing to run, but a failed link or new subnet means someone has to edit them.'),
            ],
        ],
        [
            'title' => 'OSPF', 'sort' => 7, 'perRun' => 6, 'domain' => CONN,
            'lesson' => <<<'MD'
**OSPF** is a **link-state** routing protocol. Every router learns the full map of its area and works out the best paths itself. Its metric is **cost**, based on interface bandwidth. In single-area OSPF every interface sits in the backbone, **area 0**.

```
router ospf 1
 router-id 1.1.1.1
 network 10.1.1.0 0.0.0.255 area 0
```

The **router ID** comes from the `router-id` command, or else the highest loopback address, or else the highest active interface address.

Routers become **neighbours** by exchanging Hellos, every **10 seconds** on Ethernet, with a **40-second** dead timer. These must match on both sides: area, subnet, **hello and dead timers**, authentication and MTU. Router IDs must be different.

On a shared Ethernet segment, routers elect a **DR** and **BDR** to cut down updates: the highest interface **priority** wins, then the highest router ID. Priority **0** means never DR.

**OSPFv3** does the same job for IPv6, enabled per interface: `ipv6 ospf 1 area 0`.
MD,
            'questions' => [
                $mcq('What kind of routing protocol is OSPF?', ['Link-state', 'Distance-vector', 'Path-vector', 'Static'],
                    'OSPF', 800, 'Each OSPF router builds a full map of its area and runs the SPF algorithm on it. RIP is distance-vector; BGP is path-vector.'),
                $mcq('What metric does OSPF use?', ['Cost, based on interface bandwidth', 'Hop count', 'Delay only', 'Administrative distance'],
                    'OSPF', 900, 'OSPF cost is the reference bandwidth divided by the interface bandwidth, added up along the path.'),
                $order('Order how an OSPF router picks its router ID, first choice first.', ['The router-id command', 'The highest IPv4 address on a loopback interface', 'The highest IPv4 address on an active interface'],
                    'OSPF', 1100, 'Setting it by hand with `router-id` is clearest. Loopbacks never go down, which is why they come next.'),
                $mcq('What are OSPF’s default hello and dead timers on Ethernet?', ['10 seconds and 40 seconds', '5 seconds and 15 seconds', '30 seconds and 120 seconds', '60 seconds and 180 seconds'],
                    'OSPF', 1000, 'Hellos every 10 seconds; a neighbour is declared dead after 40 seconds of silence (four hellos).'),
                $mcq('Which of these must match for two routers to become OSPF neighbours?', ['The hello and dead timers', 'The router IDs', 'The OSPF process IDs', 'The interface priorities'],
                    'OSPF', 1150, 'Timers, area, subnet, authentication and MTU must match. Router IDs must differ, and process IDs are local, so they can be different.'),
                $mcq('How is the DR chosen on an Ethernet segment?', ['Highest interface priority, then highest router ID', 'Lowest IP address on the segment', 'The first router to boot', 'The router with the fastest link'],
                    'OSPF', 1100, 'Priority (default 1) is checked first. Note the election doesn’t preempt: a better router joining later waits its turn.'),
                $mcq('What does an OSPF interface priority of 0 do?', ['The router never becomes DR or BDR on that segment', 'The router always becomes DR', 'OSPF is turned off on that interface', 'The router’s routes are ignored'],
                    'OSPF', 1050, 'Priority 0 takes a router out of the DR/BDR election, while it still forms adjacencies with them.'),
                $fill('In single-area OSPF every interface sits in the backbone: network 10.1.1.0 0.0.0.255 area ___', [['0', '0.0.0.0']],
                    'OSPF', 900, 'The backbone is area 0, which can also be written 0.0.0.0.'),
                $mcq('What is OSPFv3 for?', ['Routing IPv6, enabled per interface', 'Routing IPv4 with encryption', 'Connecting several areas only', 'Sending routes to a WLC'],
                    'OSPF', 1000, 'OSPFv3 carries IPv6 routes. You enable it under each interface with `ipv6 ospf <process> area <area>`.'),
            ],
        ],
        [
            'title' => 'Gateways and first-hop redundancy', 'sort' => 8, 'perRun' => 5, 'domain' => CONN,
            'lesson' => <<<'MD'
Every host sends traffic for other networks to its **default gateway**. If that one router fails, the whole subnet is cut off, even with a second router sitting right there.

A **first-hop redundancy protocol (FHRP)** fixes this. Two or more routers share a **virtual IP** (and virtual MAC) address, and hosts use the virtual IP as their gateway. One router answers for it; if it fails, another takes over and the hosts never notice.

- **HSRP** (Cisco): one **active** and one **standby** router. The highest **priority** (default 100) wins, then the highest IP. With **preempt**, a higher-priority router takes the active role back when it returns.
- **VRRP** (open standard): one **master** and one or more backups.
- **GLBP** (Cisco): shares the load across several gateways at once.

If a PC can reach its own subnet but nothing beyond it, check its default gateway first.
MD,
            'questions' => [
                $mcq('What does a first-hop redundancy protocol give hosts?', ['A virtual default gateway that keeps working if a router fails', 'Two IP addresses each', 'Faster DNS lookups', 'A backup DHCP server'],
                    'First-hop redundancy', 850, 'Routers share a virtual IP. Hosts point at it, so a router failing doesn’t change their settings.'),
                $pairs('Match each FHRP to how it works.', ['HSRP = Cisco: one active router and one standby', 'VRRP = Open standard: one master and backups', 'GLBP = Cisco: shares the load across several gateways'],
                    'First-hop redundancy', 1000, 'HSRP and VRRP have one router doing the work at a time; GLBP spreads hosts across several.'),
                $mcq('Which router becomes HSRP active?', ['The highest priority, then the highest IP address', 'The lowest priority', 'The router with the lowest MAC address', 'Whichever router is closest to the internet'],
                    'First-hop redundancy', 1050, 'Priority defaults to 100; raise it on the router you want active.'),
                $mcq('What does HSRP preempt do?', ['Lets a higher-priority router take back the active role when it returns', 'Stops the standby router ever taking over', 'Makes both routers active at once', 'Changes the virtual IP address'],
                    'First-hop redundancy', 1100, 'Without preempt, the router that took over keeps the role even after the preferred one is back.'),
                $mcq('With HSRP, what should hosts use as their default gateway?', ['The virtual IP address', 'The active router’s real IP address', 'The standby router’s real IP address', 'The switch’s management address'],
                    'First-hop redundancy', 900, 'The virtual IP moves to whichever router is active, so hosts never need to change it.'),
                $mcq('What is a host’s default gateway for?', ['It is where the host sends packets for other networks', 'It hands out IP addresses', 'It turns names into IP addresses', 'It blocks unwanted traffic'],
                    'Default gateway', 750, 'Traffic for the local subnet goes straight to the destination; anything else goes to the gateway.'),
                $mcq('A PC can ping hosts in its own subnet but nothing beyond it. What is the most likely problem?', ['A wrong or missing default gateway', 'A faulty network cable', 'A DNS server that is down', 'A duplicate MAC address'],
                    'Default gateway', 950, 'Local pings prove the cable and IP settings work. Reaching other networks needs a correct gateway.'),
            ],
        ],
    ],

    /* ======================================================================= Datacentre */
    2 => [
        [
            'title' => 'NAT, DHCP and DNS', 'sort' => 1, 'perRun' => 5, 'domain' => SERV,
            'lesson' => <<<'MD'
**NAT** swaps private addresses for public ones at the edge of the network. Its four terms:

- **Inside local**: a host's private address, as seen inside.
- **Inside global**: the public address it appears as outside.
- **Outside global**: an outside host's real public address.
- **Outside local**: that outside host as seen from inside (usually the same).

**PAT** (NAT overload) lets many inside hosts share **one** public address, telling them apart by **port number**. It's what nearly every home router does.

**DHCP** gives hosts their addresses in four steps: **Discover, Offer, Request, Acknowledgement**. Discover is a broadcast, so it can't cross a router by itself. `ip helper-address` on the router relays it to a DHCP server on another subnet.

**DNS** turns names into addresses, over **UDP port 53** (TCP for large replies). Common records:

- **A** name to IPv4, **AAAA** name to IPv6
- **CNAME** an alias for another name
- **MX** the mail server for a domain
MD,
            'questions' => [
                $pairs('Match each NAT term to what it means.', ['Inside local = A host’s private address inside the network', 'Inside global = The public address that host appears as outside', 'Outside global = An outside host’s real public address'],
                    'NAT', 1100, '"Inside/outside" says whose address it is; "local/global" says which side of the NAT router you’re looking from.'),
                $mcq('How does PAT let many hosts share one public IP address?', ['It tells their sessions apart by port number', 'It gives each host a different public IP', 'It sends traffic from one host at a time', 'It uses each host’s MAC address'],
                    'NAT', 950, 'PAT (NAT overload) rewrites source ports too, so replies can be sent back to the right inside host.'),
                $order('Put the four DHCP messages in order.', ['Discover', 'Offer', 'Request', 'Acknowledgement'],
                    'DHCP', 850, 'DORA: the client discovers, a server offers, the client requests that offer, and the server acknowledges it.'),
                $mcq('What does `ip helper-address` do on a router interface?', ['Relays DHCP broadcasts to a DHCP server on another subnet', 'Gives the router a second IP address', 'Points the router at a DNS server', 'Turns the router into a DHCP server'],
                    'DHCP', 1000, 'Routers don’t forward broadcasts. The helper address turns the client’s Discover into a unicast to the server.'),
                $pairs('Match each DNS record to what it holds.', ['A = A name’s IPv4 address', 'AAAA = A name’s IPv6 address', 'CNAME = An alias for another name', 'MX = The mail server for a domain'],
                    'DNS', 950, 'AAAA is "quad A" because an IPv6 address is four times as long as IPv4.'),
                $mcq('Which port do DNS queries normally use?', ['UDP 53', 'UDP 67', 'TCP 80', 'UDP 123'],
                    'DNS', 850, 'DNS uses UDP 53, falling back to TCP 53 for large replies. 67 is DHCP, 80 is HTTP, and 123 is NTP.'),
            ],
        ],
        [
            'title' => 'NTP, SNMP, QoS and ICMP', 'sort' => 2, 'perRun' => 5, 'domain' => SERV,
            'lesson' => <<<'MD'
**NTP** keeps clocks in step, so logs from different devices line up. A device's **stratum** is its distance from a reference clock: stratum 1 is attached to one, and every hop adds one. Lower is better.

**SNMP** lets a management station watch devices. The manager **polls** them (get), and devices send **traps** on their own when something happens. Only **SNMPv3** adds proper authentication and encryption; v1 and v2c send a community string in plain text.

**QoS** decides which traffic goes first when a link is busy. Voice needs low delay, low jitter and almost no loss, so it gets **Low Latency Queuing (LLQ)**, a strict priority queue. Traffic is classified and marked (DSCP) at the edge, then queued accordingly.

**ICMP** carries network messages:

- **Echo request / echo reply**: what `ping` uses.
- **Destination unreachable**: no route, or the port is closed.
- **Time exceeded**: a packet's TTL reached zero.

**Traceroute** sends packets with TTL 1, 2, 3... Each router that drops one sends back *time exceeded*, and that reveals the path.
MD,
            'questions' => [
                $mcq('What does a lower NTP stratum number mean?', ['The clock is closer to a reference time source', 'The clock is less accurate', 'The device is further from the server', 'NTP is turned off'],
                    'NTP', 950, 'Stratum 1 sits on a reference clock such as GPS. Each NTP hop away adds one.'),
                $mcq('What is an SNMP trap?', ['A message a device sends to the manager on its own when something happens', 'A request from the manager for a value', 'A firewall rule that blocks SNMP', 'A password for SNMP access'],
                    'SNMP', 950, 'Polling asks every so often; traps tell the manager straight away, like "interface down".'),
                $mcq('Which SNMP version adds authentication and encryption?', ['SNMPv3', 'SNMPv1', 'SNMPv2c', 'All of them'],
                    'SNMP', 900, 'v1 and v2c rely on a community string sent in clear text. Use v3 wherever you can.'),
                $mcq('Which QoS tool gives voice traffic strict priority on a busy link?', ['Low Latency Queuing (LLQ)', 'Weighted random early detection', 'Traffic shaping', 'First in, first out'],
                    'QoS', 1150, 'LLQ adds a strict priority queue to class-based queuing, so voice always goes first, up to a set limit.'),
                $mcq('How does traceroute find the routers along a path?', ['It sends packets with rising TTLs; each router that drops one replies with ICMP time exceeded', 'It asks each router for its routing table', 'It reads the path from DNS', 'It pings every address in the subnet'],
                    'ICMP', 1050, 'TTL 1 dies at the first router, TTL 2 at the second, and so on. Each one reveals itself in the time exceeded reply.'),
                $pairs('Match each ICMP message to when you see it.', ['Echo request and reply = When you run ping', 'Destination unreachable = There is no route, or the port is closed', 'Time exceeded = A packet’s TTL reached zero'],
                    'ICMP', 900, 'These three explain most of what ping and traceroute show you.'),
            ],
        ],
        [
            'title' => 'Threats, passwords, AAA and VPNs', 'sort' => 3, 'perRun' => 5, 'domain' => SEC,
            'lesson' => <<<'MD'
Security has its own vocabulary:

- **Vulnerability**: a weakness.
- **Threat**: a danger that could use it.
- **Exploit**: the actual way to use it.
- **Mitigation**: what you do to reduce the risk.

Common attacks include phishing, malware, man-in-the-middle and **denial of service**. A **DDoS** floods the target from many compromised machines at once.

Protect device access:

- `enable secret` stores a strongly hashed password. `enable password` and `service password-encryption` are weak.
- Use SSH, not Telnet, and local or central user accounts.

**AAA** answers three questions: **Authentication** (who are you?), **Authorisation** (what may you do?) and **Accounting** (what did you do?). Devices check with a server over **RADIUS** (encrypts only the password) or **TACACS+** (encrypts the whole message and handles each A separately, which suits device administration).

A **VPN** sends traffic through an encrypted tunnel. **Site-to-site** IPsec VPNs join whole networks; **remote-access** VPNs connect individual users.
MD,
            'questions' => [
                $pairs('Match each security term to its meaning.', ['Vulnerability = A weakness that could be used', 'Threat = A danger that could use a weakness', 'Exploit = An actual way to take advantage of a weakness', 'Mitigation = Something done to reduce the risk'],
                    'Security concepts', 950, 'A threat needs a vulnerability; an exploit is the tool; mitigation closes the gap or limits the damage.'),
                $mcq('How is a DDoS attack different from a plain DoS attack?', ['It comes from many compromised machines at once', 'It only targets DNS servers', 'It steals passwords instead of blocking service', 'It needs physical access to the network'],
                    'Security threats', 850, 'Distributed attacks use botnets, so blocking one source address doesn’t stop them.'),
                $mcq('Which command protects privileged EXEC mode with a strongly hashed password?', ['enable secret', 'enable password', 'service password-encryption', 'login local'],
                    'Device passwords', 900, '`enable secret` stores a strong hash. `service password-encryption` only applies weak, easily reversed type 7 encoding.'),
                $pairs('Match each part of AAA to the question it answers.', ['Authentication = Who are you?', 'Authorisation = What are you allowed to do?', 'Accounting = What did you do?'],
                    'AAA', 850, 'Logging in is authentication, permissions are authorisation, and the audit trail is accounting.'),
                $mcq('What is true of TACACS+ compared with RADIUS?', ['TACACS+ encrypts the whole message and keeps the three AAA functions separate', 'TACACS+ encrypts only the password', 'TACACS+ is an open standard and RADIUS is Cisco’s', 'TACACS+ uses UDP and RADIUS uses TCP'],
                    'AAA', 1200, 'TACACS+ (Cisco, TCP 49) suits device administration. RADIUS (open standard, UDP) encrypts only the password and is common for network access.'),
                $mcq('What does a site-to-site IPsec VPN do?', ['Connects two whole networks through an encrypted tunnel between gateways', 'Lets one remote user reach the office', 'Speeds up traffic between sites', 'Replaces the need for routing'],
                    'VPNs', 950, 'The two gateways encrypt traffic between them, so hosts at each site need no VPN software. Remote-access VPNs are per user.'),
            ],
        ],
        [
            'title' => 'ACLs and firewalls', 'sort' => 4, 'perRun' => 5, 'domain' => SEC,
            'lesson' => <<<'MD'
An **access control list** is an ordered list of permit and deny lines. The router checks each packet from the top and stops at the **first match**. Every ACL ends with an invisible **deny any**, so a list with only deny lines blocks everything.

- **Standard ACLs** (1-99) match the **source address only**. Place them **close to the destination**, or you'll block too much.
- **Extended ACLs** (100-199) match source, destination, protocol and port. Place them **close to the source**, so unwanted traffic dies early.

ACLs use **wildcard masks**, the inverse of a subnet mask: 0 means "must match" and 1 means "ignore". So /24 is `0.0.0.255`.

```
access-list 101 deny tcp any host 10.1.1.10 eq 23
access-list 101 permit ip any any
```

A **stateful firewall** goes further than an ACL: it tracks each connection and automatically lets the return traffic back in for sessions started from inside. Next-generation firewalls also inspect applications and block known threats.
MD,
            'questions' => [
                $mcq('What is at the end of every ACL, even though you can’t see it?', ['An implicit deny any', 'An implicit permit any', 'A log of every packet', 'Nothing'],
                    'ACLs', 850, 'Anything that matches no line is denied. An ACL with only deny lines blocks all traffic.'),
                $mcq('What can a standard ACL match on?', ['The source IP address only', 'Source and destination IP addresses', 'The destination port only', 'The source MAC address'],
                    'ACLs', 900, 'Standard ACLs see only the source address. Extended ACLs add destination, protocol and ports.'),
                $mcq('Where should you place an extended ACL?', ['As close to the source as possible', 'As close to the destination as possible', 'On the ISP’s router', 'On every interface in the network'],
                    'ACLs', 1050, 'An extended ACL is precise enough to drop unwanted traffic early. A standard ACL goes near the destination so it doesn’t block too much.'),
                $fill('The wildcard mask for 192.168.1.0/24 is ___.', [['0.0.0.255']],
                    'ACLs', 1000, 'A wildcard mask is the inverse of the subnet mask: 255.255.255.0 becomes 0.0.0.255.'),
                $mcq('What does `access-list 101 deny tcp any host 10.1.1.10 eq 23` block?', ['Telnet to 10.1.1.10 from anywhere', 'All traffic from 10.1.1.10', 'Web traffic to 10.1.1.10', 'SSH to every host'],
                    'ACLs', 1100, 'TCP port 23 is Telnet, and the destination is the single host 10.1.1.10. Remember to add a permit line after it, or the implicit deny blocks everything else.'),
                $mcq('What does a stateful firewall do that a basic ACL doesn’t?', ['It tracks connections and lets return traffic back in for sessions started inside', 'It filters on source IP addresses', 'It works at Layer 2 only', 'It assigns IP addresses'],
                    'Firewalls', 1000, 'A stateless ACL needs rules for both directions. A stateful firewall remembers the session and allows the replies.'),
            ],
        ],
        [
            'title' => 'Layer 2 and wireless security', 'sort' => 5, 'perRun' => 5, 'domain' => SEC,
            'lesson' => <<<'MD'
Attacks inside the LAN need switch features to stop them:

- **MAC flooding** fills the switch's MAC table with fake addresses, so it floods every frame everywhere. **Port security** limits MACs per port.
- A **rogue DHCP server** hands out a fake gateway. **DHCP snooping** only trusts DHCP replies from ports you mark **trusted** (toward the real server), and builds a table of who got which address.
- **ARP spoofing** lies about which MAC owns an IP. **Dynamic ARP Inspection (DAI)** checks ARP messages against the DHCP snooping table.
- **VLAN hopping** abuses trunk negotiation. Set access ports to `switchport mode access`, turn off DTP with `switchport nonegotiate`, and use an unused native VLAN.

**Wireless security**: never use **WEP**. **WPA2** uses AES with CCMP; **WPA3** replaces the pre-shared key handshake with **SAE**, which resists password guessing. **Personal** mode shares one passphrase; **Enterprise** mode has each user log in through 802.1X and a RADIUS server.
MD,
            'questions' => [
                $mcq('With DHCP snooping, which switch ports should be trusted?', ['Only ports toward the real DHCP server', 'Every access port', 'Every port, by default', 'Only ports with IP phones'],
                    'DHCP snooping', 1050, 'DHCP offers arriving on untrusted ports are dropped, which stops rogue DHCP servers.'),
                $mcq('What does Dynamic ARP Inspection check ARP messages against?', ['The DHCP snooping binding table', 'The routing table', 'The MAC address table', 'The DNS cache'],
                    'Layer 2 security', 1150, 'DAI uses the IP-to-MAC bindings DHCP snooping recorded, and drops ARP messages that don’t match.'),
                $pairs('Match each wireless security option to what it is.', ['WEP = Old and broken: never use it', 'WPA2 = AES encryption with CCMP', 'WPA3 = Uses SAE instead of the old pre-shared key handshake', 'Enterprise mode = Each user logs in through 802.1X and RADIUS'],
                    'Wireless security', 1050, 'WPA3’s SAE stops attackers guessing passwords offline from a captured handshake.'),
                $mcq('How does WPA2-Personal differ from WPA2-Enterprise?', ['Personal uses one shared passphrase; Enterprise gives each user their own login', 'Personal is encrypted and Enterprise is not', 'Personal only works on 2.4 GHz', 'Enterprise needs no server'],
                    'Wireless security', 950, 'Enterprise checks each user with 802.1X against a RADIUS server, so one leaked password doesn’t open the network to everyone.'),
                $mcq('An attacker fills a switch’s MAC address table with fake addresses. Which feature stops this?', ['Port security', 'Spanning Tree', 'EtherChannel', 'HSRP'],
                    'Layer 2 security', 950, 'With a full table the switch floods every frame, so the attacker sees everything. Port security caps the MACs per port.'),
                $mcq('Which step helps prevent VLAN hopping?', ['Turn off trunk negotiation with switchport nonegotiate on access ports', 'Put every port in VLAN 1', 'Enable PortFast on trunks', 'Use a crossover cable'],
                    'Layer 2 security', 1150, 'Hard-code access ports, disable DTP, and move the native VLAN to an unused VLAN.'),
            ],
        ],
        [
            'title' => 'SDN controllers and REST APIs', 'sort' => 6, 'perRun' => 4, 'domain' => AUTO,
            'lesson' => <<<'MD'
Every network device has three **planes**:

- **Data plane**: forwards the actual packets and frames.
- **Control plane**: decides where traffic should go (routing protocols, STP).
- **Management plane**: how admins reach and configure it (SSH, SNMP).

**Software-defined networking (SDN)** moves much of the control plane to a central **controller**, such as Cisco DNA Center or ACI. The controller talks to devices through **southbound APIs** (like NETCONF or OpenFlow), and to your apps and scripts through **northbound APIs**, usually REST.

A **REST API** works over HTTP. The method says what to do:

- **GET** read, **POST** create, **PUT** update or replace, **DELETE** delete.

The status code says how it went: **200** OK, **201** created, **401** not authenticated, **404** not found, **500** server error.
MD,
            'questions' => [
                $pairs('Match each plane to what it does.', ['Data plane = Forwards the actual packets and frames', 'Control plane = Decides where traffic should go', 'Management plane = How admins reach and configure the device'],
                    'SDN', 1000, 'SDN centralises much of the control plane in a controller, while devices keep forwarding in the data plane.'),
                $mcq('In SDN, what does the northbound API connect?', ['The controller and the apps or scripts that use it', 'The controller and the network devices', 'Two switches in the same rack', 'A host and its default gateway'],
                    'SDN', 1100, 'Northbound faces your apps (usually REST); southbound faces the devices (NETCONF, OpenFlow and others).'),
                $pairs('Match each HTTP method to the action it performs in a REST API.', ['GET = Read', 'POST = Create', 'PUT = Update or replace', 'DELETE = Delete'],
                    'REST APIs', 950, 'These map to CRUD: create, read, update, delete.'),
                $mcq('A REST call returns status 404. What does that mean?', ['The resource wasn’t found', 'The request worked', 'You aren’t authenticated', 'The server had an internal error'],
                    'REST APIs', 900, '2xx means success, 4xx a problem with the request (401 not authenticated, 404 not found), and 5xx a problem on the server.'),
            ],
        ],
        [
            'title' => 'JSON and YAML', 'sort' => 7, 'perRun' => 4, 'domain' => AUTO,
            'lesson' => <<<'MD'
APIs and automation tools pass data around as text.

**JSON** is built from:

- **objects** in curly braces: `{"hostname": "R1", "mtu": 1500}`
- **arrays** in square brackets: `[10, 20, 30]`
- **strings** in double quotes, plus numbers, `true`, `false` and `null`.

Keys must be in double quotes, and there's no comma after the last item. Objects can nest, so to read a value you follow the path: `device` → `ports` → the second item → `status`.

**YAML** holds the same kind of data with less punctuation. It uses **indentation** for structure, `key: value` pairs, and `- ` for list items. Ansible playbooks are written in YAML.

```
interface: Gi0/1
enabled: true
vlans:
  - 10
  - 20
```
MD,
            'questions' => [
                $mcq('In the JSON {"interface": "Gi0/1", "enabled": true, "vlans": [10, 20]}, what is the value of "vlans"?', ['An array (a list)', 'An object', 'A string', 'A boolean'],
                    'JSON', 850, 'Square brackets hold an array. Curly braces would make it an object.'),
                $mcq('Which of these is valid JSON?', ['{"hostname": "R1", "mtu": 1500}', '{\'hostname\': \'R1\'}', '{hostname: "R1"}', '{"hostname": "R1",}'],
                    'JSON', 1000, 'JSON needs double-quoted keys and strings and allows no trailing comma.'),
                $mcq('In {"device": {"name": "SW1", "ports": [{"id": 1, "status": "up"}, {"id": 2, "status": "down"}]}}, what is the status of port 2?', ['down', 'up', '2', 'SW1'],
                    'JSON', 1100, 'Follow the path: device, then ports, then the item whose id is 2, then status.'),
                $mcq('How does YAML show structure?', ['With indentation and key: value pairs', 'With curly braces and square brackets only', 'With XML tags', 'With commas at the end of every line'],
                    'YAML', 900, 'YAML relies on indentation (spaces, not tabs), so a misaligned line changes the meaning.'),
            ],
        ],
        [
            'title' => 'Ansible, Puppet and Chef', 'sort' => 8, 'perRun' => 4, 'domain' => AUTO,
            'lesson' => <<<'MD'
Configuring a hundred switches by hand is slow and error-prone. **Configuration management tools** describe the configuration you want once, then apply it everywhere.

- **Ansible**: **agentless**. It pushes changes from a control node over SSH. Playbooks are written in **YAML**.
- **Puppet**: **agent-based**. Devices pull their configuration from a server. Manifests are in Puppet's own language.
- **Chef**: **agent-based**. Recipes and cookbooks are written in **Ruby**.

They help stop **configuration drift**: devices slowly differing from the standard because of one-off manual changes.

Good automation is **idempotent**: running it a second time changes nothing if the device is already in the desired state, so it's safe to run again and again.
MD,
            'questions' => [
                $pairs('Match each tool to how it works.', ['Ansible = Agentless; pushes over SSH with YAML playbooks', 'Puppet = Agent-based; manifests in its own language', 'Chef = Agent-based; recipes and cookbooks in Ruby'],
                    'Configuration management', 1050, 'Ansible needs nothing installed on devices, which is why it’s popular for network gear.'),
                $mcq('Why use a configuration management tool for network devices?', ['To apply consistent configurations to many devices automatically', 'To make each device faster', 'To replace routing protocols', 'To encrypt traffic between devices'],
                    'Configuration management', 850, 'You describe the desired state once, and the tool applies it everywhere the same way.'),
                $mcq('What is configuration drift?', ['Devices slowly differing from the standard configuration through manual changes', 'A routing loop between two routers', 'A clock that runs fast without NTP', 'Wireless clients roaming between APs'],
                    'Configuration management', 950, 'Small manual fixes add up. Re-applying the standard configuration brings devices back in line.'),
                $mcq('What does it mean for an automation task to be idempotent?', ['Running it again changes nothing if the device is already correct', 'It runs on only one device at a time', 'It must be run by an administrator', 'It deletes the old configuration first'],
                    'Configuration management', 1100, 'Idempotent tasks are safe to repeat, so you can run the same playbook every day to enforce the standard.'),
            ],
        ],
    ],
];
