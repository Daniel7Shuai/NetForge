# NetForge

Top-down network-building game. PHP 8 + MySQL/MariaDB backend, Phaser 3 map, no build step.

## Run locally (XAMPP)

1. Start MySQL from the XAMPP control panel.
2. Import the database:
   ```
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/seed.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/seed_learning.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_social.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_defence.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_progress.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_upgrades.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_mock.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_users.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_content.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_economy.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_security.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_story.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_events.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_scale.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_balance.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database/schema_datatech.sql
   C:\xampp\php\php.exe tools/seed-content.php
   ```
   Set `app.timezone` in `config/config.php` to your local zone. PHP and MySQL are kept in step from it; if they disagree, attacks never arrive and weekly raids roll over at the wrong hour.
3. Copy `config/config.example.php` to `config/config.php` and set the DB password if you have one.
4. Serve the `public/` folder:
   ```
   C:\xampp\php\php.exe -S 127.0.0.1:8080 -t public
   ```
   **Or with XAMPP's Apache:** copy the project folder into `C:\xampp\htdocs` (for example as `htdocs\NetForge`), start Apache in the XAMPP Control Panel and open http://localhost/NetForge/netforge/public/. The `.htaccess` files in `config/`, `database/`, `src/`, `storage/`, `tools/` and `tests/` block those folders from the browser, so only `public/` is reachable.
5. Open http://127.0.0.1:8080 (or the Apache address) and create an account (public sign-up makes students).
6. Create a lecturer (or admin) from the command line:
   ```
   C:\xampp\php\php.exe tools/create-user.php <username> <password> lecturer [email]
   ```
   Lecturers and admins land on the lecturer pages at `/lecturer/` after signing in.

## Before putting it on a real server

- In `config/config.php`: set `app.debug` to `false` (errors stop showing to visitors), set `app.base_url` to the site's address, and set `mail.transport` to `mail` with SMTP configured, or password reset links won't be emailed.
- Use a database user with its own password, not `root` with none.
- Serve it over HTTPS. The session cookie is marked secure automatically when HTTPS is on.
- **Set `app.base_url`.** Password reset emails refuse to send without it, because a link built from the request could be pointed at another site.
- Create the first admin with `tools/create-user.php` and a strong password. Don't run `tools/seed-demo-data.php` there.
- Serve only `public/`: point the web server's document root at it. If you can't, keep the `.htaccess` files (Apache) so `config/`, `storage/` and the rest stay blocked.

## Security

- **Accounts**: passwords hashed with `password_hash`; the session ID is renewed at sign-in; cookies are HttpOnly and SameSite=Lax (and Secure over HTTPS). Every page and API call re-reads the account, so switching one off or leaving it idle (`app.idle_minutes`) signs it out at once.
- **Sign-in limits**: 5 failures on a username, or 20 from one address, pause sign-in for 15 minutes (`Auth::MAX_FAILS_*`, table `login_failures`). It works the same for names that don't exist.
- **Reset links**: one-time, 30 minutes, stored only as a SHA-256 hash, at most 3 an hour per account, never built from the request's Host header when emailing, and the forgot page answers the same whether or not the account exists.
- **Requests**: every write needs the session's CSRF token; every query uses prepared statements; every page escapes its output; the game only starts a cyber attack for requests carrying that token.
- **Headers**: no framing by other sites, no MIME sniffing, same-origin referrers (`src/bootstrap.php`).
- **Exports**: CSV cells starting with = + - @ are neutralised so spreadsheets don't run them.
- Tests: `php tests/SecurityTest.php`.

## Where things live

| Want to change… | Edit |
|---|---|
| Colours, fonts | `public/assets/css/tokens.css` |
| Missions and rewards | `objectives` table (`database/seed.sql`) |
| Prices, unlock levels | `shop_items` table |
| Starting credits, XP per level | `game_config` table |
| A new device or cable | one row in `item_types` (ports are JSON) |
| What a new player starts with | `src/Starter.php` |
| Game rules (cable fits, objective checks, levelling) | `src/Rules.php` (+ hint copy in `public/assets/js/rules.js`) |
| Floor plan, desks, rack slots | `public/assets/js/game/layout.js` |
| Pixel art | `public/assets/js/game/art.js` |
| What clicks and tools do | `tileClicked()` in `public/assets/js/store.js` |

| Locations, furniture, placement slots | `public/assets/data/layout.json` (read by both PHP and JS) |
| Where an item may stand | `item_types.slot_kinds` (bench, shelf, desk, rack) |
| Room art per location | `ROOMS` / `FURNITURE` in `public/assets/js/game/art.js` |

## Status

Framework done: accounts, sessions, CSRF, database, state API, HUD, drawer, console, pixel-art map, player movement, LEDs, objective tracking and rewards.

Locations (FR-4.3, three datacentre levels): click the place name in the top bar to travel.
- **Garage** (tier 1): where every player starts, with only a busted laptop. Bench and shelf spots, and the ISP box on the wall.
- **Office and server room** (tier 2): lease at level 4 for 800 credits. Four desks and six rack slots.
- **Datacentre** (tier 3): lease at level 7 for 3,000 credits. 25 rack slots on a raised floor and an ISP fibre panel (copper and fibre).
- Leasing is paid once (`user_locations`); after that you travel freely. Devices stay where you placed them and keep earning while you're elsewhere. Each place has its own ISP connection to wire up. Level, price and layout are in `public/assets/data/layout.json`; `Rules::travel`, `api/travel.php`.

Upgrades (FR-4.3): select a device to see its tier and an **Upgrade** button.
- Tiers 1 to 3 (`item_types.max_tier`; building fixtures have one tier). Tier 2 costs 60% of the item's shop price and needs its unlock level + 2; tier 3 costs 120% and needs + 4. So credits pay for it, but XP decides when. `Rules::upgradeInfo`, `Rules::upgrade`, `api/upgrade.php`.
- Higher tiers earn more while online: income x1, x1.5, x2.
- The **firewall** (shop, level 4, 500 credits; fits a shelf or rack): while online it blocks 25% of every breach penalty per tier, at most 75% (`item_types.shield_pct`, `Income::shield`, applied in `Defence::charge`).
- **Move** (same popover): click a free spot to move the device there. Its cables stay plugged in and re-route. `Rules::move`, `api/move.php`.
- Tests: `php tests/UpgradeTest.php`.

Placing devices done: select a device in the inventory (or drag it) and click a glowing spot. The server re-checks everything (`Rules::place`), names devices (Laptop-1, PC-1…) and completes objectives.

Learning (module 3.0) done: press **L** or click the objective bar.
- **One topic per area of the game** (the code and database still call topics "worlds": `worlds` table, `world.php`, `unlock_world`), following the CCNA 200-301 blueprint: Topic 0 **Garage** (Network Fundamentals), Topic 1 **Office** (Network Access, IP Connectivity), Topic 2 **Datacentre** (IP Services, Security Fundamentals, Automation and Programmability). A topic opens once the earlier topics are mastered **and** the student has leased its area (`worlds.requires_location`, set per topic on the lecturer world page). Until then Learn shows "Lease the office" with the level and credits it takes.
- Each level = lesson + adaptive challenge run. Passing every level unlocks the timed topic exam; passing it masters the topic and unlocks shop items (`shop_items.unlock_world`).
- Six challenge types: multiple choice, matching, ordering, fill in the blanks, Binary Blitz (20 s timer) and Subnet Calculator (both generated fresh each time).
- Elo (`src/Learning/Elo.php`): per-topic student rating, question ratings, K shrinks with rating, tiers Bronze/Silver/Gold/Platinum. Credits and XP are paid only from rating gains (2 credits and 5 XP per point).
- **Daily Security Audit** (FR-3.4): every wrong answer goes into `audit_queue`, due after 1 day. In the review (top of Learn, up to 10 per round), a right answer moves it to 3 days, then 7 days, then clears it; a wrong one sends it back to 1 day. Reviews update Elo and pay from rating gains like lessons. The Learn button shows a badge with the number due. Intervals live in `Challenge::AUDIT_NEXT`.
  To demo it without waiting a day: `UPDATE audit_queue SET due_on = CURDATE();`
- **Mock exams** (FR-3.5): the Mock exam row in Learn. Pick any open topics and 10, 20 or 30 questions; questions are drawn up front, shared evenly across the topics, on one clock (1 minute per question). No answers are shown until the end, like a real exam. The result gives the score, a breakdown by topic (weakest first) and by topic against a 70% exam-ready line, and every miss with its answer and explanation. Answers still update Elo, earn XP and feed the Daily Security Audit. Results are kept in `mock_exams` and listed as past attempts. Settings are constants in `src/Learning/Mock.php`; the run is played by `Challenge` in mode `mock`.
- **Question bank**: 154 questions in 27 levels. The 120 CCNA questions follow the blueprint weights exactly (Fundamentals 24, Network Access 24, IP Connectivity 30, IP Services 12, Security 18, Automation 12), on top of the 34 starter questions and drills in the Garage. They live in `database/content/ccna.php` (one lesson per level, questions in the same format as the editor) and load with `php tools/seed-content.php`, which checks every question with the editor's rules and changes nothing if one fails. Loading again only refreshes wording; live ratings and answer counts are kept. Lecturers can keep editing in the question bank as before. Lessons support `- ` and `1. ` lists, **bold**, `code` and ``` blocks for device commands. Tests: `php tests/ContentTest.php` checks the weights and that every answer key marks correctly.
- Unit tests: `php tests/EloTest.php` (26 hand-calculated cases for Elo and subnetting).

Shop buying done (`Rules::buy`): checks topic mastery and credits on the server.

Cabling done (Packet Tracer style):
- Cable tool (**C**), pick the type in the bar (copper = straight-through, crossover, fiber, console; counts shown), click a device, pick a port, then click the second device and pick its port.
- Sockets are physical: a cable that doesn’t fit a port (copper into fiber, etc.) is greyed out in the picker and refused by the server.
- Copper ports have a role (`mdi` host-style, `mdix` switch-style, in `item_types.ports`). Straight-through links unlike roles, crossover links like roles. The wrong one plugs in but the link stays **down** (pink lights) with an explanation in the console.
- Routes are drawn from the devices’ current positions, so moving a device later re-routes its cables. Link lights blink amber briefly, then go green or pink; live links carry a data pulse.
- Remove tool (**Del**): unplug cables (they go back to your inventory) or pick up an unplugged device. Select or Inspect (**X**) a device to see every port, its role and what it’s connected to.
- Server: `Rules::connect`, `Rules::unplug`, `Rules::pickUp`; endpoints `api/connect.php`, `api/unplug.php`, `api/pickup.php`. Tests: `php tests/CablingTest.php`.

Accounts (module 1.0) done:
- **Sign-up and sign-in** (FR-1.1, FR-1.2): sign-up asks for a username, email and password, all checked on the server. Sign in with the username or the email. Each role lands on its own home page. A session closes after `app.idle_minutes` (default 120) without a request, with a note on the sign-in page.
- **Forgotten password** (FR-1.3): "Forgot it?" on the sign-in page sends a one-time link that works for `reset_minutes` (30, in `game_config`). Only a SHA-256 of the token is stored. A link dies once used, when a newer one is made, when the password changes, or when the account is switched off. The form looks the same whether or not the account exists, and sends at most 3 links an hour per account.
- **Email delivery**: `mail.transport` in `config/config.php`. The default, `log`, saves each email to `storage/mail/` instead of sending it (XAMPP has no mail server; with `app.debug` on, the page says where the file went). Set it to `mail` and configure SMTP in `php.ini` on a real server, and set `app.base_url` so links point at the right host.
- **Profile** (FR-1.4): the menu → Profile, for every role. Display name, avatar (8 colourways of the pixel character, shown on the map), email, and password (needs the current one). `public/profile.php`, `src/Accounts.php`.
- **User management** (FR-1.5): admins get **Users** in the lecturer pages. Search and filter accounts; create one with a role and temporary password; edit display name, email and role; switch an account off or back on (nothing is deleted; it's signed out on its next request); make a one-time reset link to send them. Admins can't demote or switch off themselves or the last active admin. Every change is kept in `admin_log` and shown on the account's page.
- Tests: `php tests/AccountsTest.php` (26 checks; makes and removes its own accounts).

Roles and lecturer tools (modules 1.0 and 2.0) done:
- `users.role` is student, lecturer or admin; `users.active` lets an account be switched off. Roles are checked on the server for every lecturer page (`Auth::requireRole`); students who open `/lecturer/` are sent back to the game.
- **Content** (`lecturer/index.php`, `world.php`, `level.php`): topic settings and exam (length, minutes, pass mark), add/edit/reorder/archive levels, lesson editor with a live preview that uses the same renderer as the game.
- **Question bank** (`questions.php`, `question.php`): filter by topic, level, type or text; each question shows its live Elo rating, answer count and % correct (pink under 40%). One editor for all six types with per-type help; editing keeps the live rating unless you change it.
- **CSV import** (`import.php`): download the template, upload, see every rejected row with its line number and reasons, then import the valid rows. Up to 1000 rows / 2 MB per file.
- **Unlocks and prices** (`unlocks.php`): the topic that unlocks each shop item, its price and pack size.
- The editor and the CSV import share one validator, `src/Content/QuestionInput.php`. Tests: `php tests/QuestionInputTest.php`.

Analytics and reporting (module 7.0) done, in the lecturer area under **Analytics**:
- **Class dashboard** (`analytics.php`, FR-7.1): active students, answers, accuracy, topics mastered; accuracy by topic and by subtopic (weakest first) for any date range.
- **At-risk alerts** (FR-7.2): low accuracy, inactivity, a falling topic rating, or a review backlog, each with the reason spelled out. Thresholds are constants at the top of `src/Analytics/Reports.php`.
- **Question difficulty report** (`difficulty.php`, FR-7.3): % correct the Elo model expected against % observed, flagging questions harder or easier than rated.
- **Progress reports** (FR-7.4): the class report, the students list (`students.php`) and each student’s report (`student.php`) for any period.
- **Export** (FR-7.5): every report downloads as CSV (opens in Excel) or PDF, made by the built-in `src/Analytics/SimplePdf.php` (no library needed).
- Demo data for presentations and UAT: `php tools/seed-demo-data.php [password]` creates six demo students with three weeks of history, all with the password you give (or a random one it prints); re-running replaces them. Local demos only, never on a public server.

Social and competition (module 6.0) and weekly challenges (FR-2.5) done. In the game press **G**:
- **Guilds** (FR-6.1): start one (name, tag, motto) or join one, up to 8 students. The leader sets a weekly goal and can remove members; leaving hands leadership to the longest-serving member, and the last one out closes the guild.
- **Weekly raid** (FR-6.2): the lecturer publishes one challenge per week (`lecturer/challenges.php`): title, which topic counts (or any), a target per guild and a reward. Every correct answer by a member adds to the guild’s tally (`raid_contributions`, recorded at answer time); when a guild hits the target, every contributor is paid once (`raid_rewards`).
- **Leaderboards** (FR-6.3): “nearby” ranks by XP earned this week and shows only two ranks either side of you, never the whole class; a guild board ranks guilds by correct answers this week.
- **Guild news is a chat** that every member sees, in the middle of the Guild tab: chat messages (up to 200 characters, at most one every 2 seconds) appear as bubbles, and joins, raid wins and shared milestones as notices. It refreshes every 5 seconds while open (`api/social.php?news=1`). Members are tucked behind the **Members** button next to the guild's name.
- **Milestones** (FR-6.4): **Share a milestone** under the chat posts an earned milestone (topic mastered, perfect exam, level reached, working links) to the guild news. The server only lets you share ones you’ve actually earned, once each.
- **Friends** (FR-6.5): add a classmate by username; see their level, topics mastered, XP this week, guild and last activity.
- Rules live in `src/Social/Social.php`; one endpoint, `api/social.php`. Tables in `database/schema_social.sql`.
- `tools/seed-demo-data.php` also creates two demo guilds, [PKT] Packet Pushers and [NOC] Night Shift.

Cyber attack and defence (module 5.0) done:
- **Random attacks** (FR-5.1): port scans, ARP spoofing, SYN floods and phishing. A student only faces types whose topic they’ve mastered (`attack_types.unlock_world`: phishing W0, port scan W1, ARP spoofing W4, SYN flood W7). The game checks every 15 s; attacks only start while the game is open and never during a lesson or exam. Admins set the gap between attacks, the response time and the penalty in **Cyber attacks** (`lecturer/attacks.php`).
- **Timed defence** (FR-5.2): each attack shows real-looking evidence (firewall log, ARP table, email, `netstat`) and four responses, shuffled per student, with a countdown. 12 scenarios, 3 per type, in `database/schema_defence.sql`.
- **Outcome** (FR-5.3): the right response protects the network; a wrong or late one costs credits (never below zero) and the map flashes red while under attack.
- **Security rating** (FR-5.4): grade A to F from the last 10 attacks, newer ones weighted more; shown in the top bar.
- **History** (FR-5.5): click the rating to review every attack: your response, the right one and why.
- Admins can send a test attack of any type to a student for demos. Lecturers can view the page but not change it.
- Rules: `src/Defence/Defence.php`; endpoint `api/defence.php`.

## The economy (read this before changing prices or rewards)

- **Credits come from passive income, every second** (FR-4.4): every *end device* (laptop, PC, server, GPU rig) that is *online*, meaning joined to an ISP box or fibre panel by a chain of working links, earns its `item_types.income_per_sec` times its tier bonus (x1.5, x2). **Network devices earn nothing themselves**: each home router (+25%), switch (+40%) or router (+60%) on the path between an end device and the ISP boosts what that device earns (x1.5 and x2 at higher tiers), and boosts in a row multiply, so ISP → router → switch → PC earns 1.6 x 1.4 = 2.24x. A laptop cabled straight to the ISP box earns its base rate. The wallet in the middle of the top bar counts up live, and earning devices pop coins on the map, so students see the money arrive. Up to `income_cap_hours` (8) builds up while they're away. `src/Progress/Income.php`, `public/assets/js/economy.js`.
- **XP comes from learning**: correct answers in lessons, exams and the Daily Security Audit pay XP from Elo rating gains (5 XP per point, so repeating easy questions pays almost nothing), plus weekly raid rewards. Questions pay **no credits** (`Elo::CREDITS_PER_POINT = 0`).
- **XP unlocks what credits can buy**: every 1000 XP is a level, and `shop_items.unlock_level` decides what each level may buy (`unlock_world` can add a world-mastery requirement). Levelling up announces the newly buyable gear (`Rules::addXp`). Lecturers tune levels, prices and pack sizes on **Unlocks and prices**.
- **An idle-game curve into the billions** (`database/schema_economy.sql`, steepened in `schema_scale.sql`): prices climb faster than incomes, so each tier takes longer to pay back. End devices (rates from `schema_balance.sql`): laptop 3k for 20/s (sold at every stage; fits the workbench or any desk); PC 60k for 120/s; server 120M for 25k/s; GPU rigs 700M-1.4B for 48-69k/s; GPU cards 40-75M. Network gear: home router 4k (+25%), switch 600k (+40%), router 8M (+60%). Money is shown as k, M and B everywhere (`money.js`, `src/Money.php`); hover the wallet for the exact count. Leases: office 5,000,000, datacentre 500,000,000 (in `layout.json`). Upgrades cost 60% and 120% of the price. New players start with 5,000 credits, enough for a home router and copper. If a player has nothing online, no cable and under 300 credits, the ISP tops them up once a day so nobody gets stuck. Lecturers edit every rate and price on **Unlocks and prices**, which shows the pacing.
- Breaches from cyber attacks cost a few minutes of the student's own income (`attack_penalty_minutes`, default 5, at least `attack_penalty_min`), so they sting at every stage. An online firewall blocks part of each breach.
- Credits are spent on gear, on upgrades (tier 2 and 3) and on leasing the office and datacentre; each is gated by level, so learning sets the pace.

## Badges and streak

- **Daily learning streak** (FR-4.5): days in a row with at least one correct answer; shown in the top bar (lit once you’ve learned today).
- **15 badges** (FR-4.5), each checked against what the student actually did (`src/Progress/Badges.php`), awarded whenever the game state loads, with a pop-up and a console line. Browse them in Social (**G**) → Badges; share them with the guild (FR-6.4).



The database is pre-release: after pulling schema changes, drop `netforge` and re-import `schema.sql` and `seed.sql`.
## The story

- **The opening** (first sign-in, skippable; replay it from the menu with **Watch the intro**): your dad Marcus runs NetForge's datacentre with Ada and his staff. DataTech's armoured Reapers blow open the wall and land a VTOL dropship inside, sent in by DataTech's CEO, Lucas, to do his dirty work. The staff run, Dad sends Ada to find you, and he's taken. Three days later, in your garage, Ada explains what happened: learn everything your dad knew, grow your own datacentre and buy DataTech out to get him back. Then the tutorial carries on as before.
- **The ending**: **DataTech** is in the shop (the DataTech category) for 999B and needs every topic mastered (every topic exam passed). Ada warns that buying it ends the game. Buying it plays the rescue: you and Ada walk into DataTech's prison and free Dad. An end card then offers **Go back and keep playing** (to just before buying it, with the 999B returned) or **Stay at the ending**. It asks only there (again on the next visit if the game was closed before choosing); staying is final. `src/Ending.php`, `api/ending.php`, `users.game_ended_at`.
- Cutscenes are drawn in code like the rest of the game, all original pixel art (`public/assets/js/cutscene.js`, art in `game/story-art.js`): characters walk with the player's stride animation, plus the dropship, shakes, flashes, debris, fades and captions, with the usual dialogue box. Skip or Esc ends a scene at any point. Ada's lines in the garage are the first tutorial step in `tutorial.json`; the scene dialogue is in `cutscene.js`. `{dad}`, `{lucas}` and the other character codes become names, and staff can rename Marcus, Lucas and the Reapers on the Characters page.
- **Cisco equipment**: the shop sells the real kit students meet in CCNA labs and Packet Tracer: Linksys WRT300N router, Catalyst 2960 switch (on a shelf or in a rack), ISR 4331 router, ASA 5506-X firewall and UCS C220 server. Placement hints list every kind of spot an item fits.
- Tests: `php tests/StoryTest.php`.

## Tutorial, characters, movement and music

- **Tutorial**: new students meet the network teacher in a dialogue box along the bottom of the map, with the speaker standing on top of it. She walks them from placing the laptop to buying a router, cabling the ISP box and linking the laptop; each step waits until the game shows the task is done, with a small task card and a glow on the right button. The hacker appears at the end. Every line can be skipped (Esc), the whole tutorial can be skipped, and it can be replayed from the menu. Progress is saved per student (`users.tutorial_step`, `tutorial_done`). The script is `public/assets/data/tutorial.json`: edit the lines there; `{player}`, `{teacher}` and `{hacker}` become names.
- **Characters** (lecturer pages → Characters): change each character's name, role and side, and upload portrait art (PNG, JPEG, GIF or WebP, up to 700 KB, stored in the `characters` table). With no art the game draws a built-in pixel portrait. To add a character, insert a row in `characters` and use its code in the script. The hacker also taunts the student at the top of every cyber attack (the `attack` lines in the script).
- **Point and click**: click the floor to walk there; placing or moving gear walks the character up to the spot first. No keyboard movement. The character has a 2-frame idle (breathing) and a 3-frame walk cycle, in the student's avatar colours (`playerFrame` in `art.js`).
- **Music** (top bar ♪, and lecturer pages → Music): five original chiptune tracks composed in `public/assets/js/music.js` and played with Web Audio, so there's no copyright to worry about. Staff can upload more (MP3, OGG, WAV, M4A or WebM up to 30 MB, with a required source and licence note), switch any track off and set the order. Uploads are stored in `storage/music/` (blocked from the browser; served through `api/music.php`). Students turn music on or off and set the volume; it starts on their first click, as browsers require.
- **Importing several tracks at once**: `php tools/import-music.php --list <list.txt>` (one `file | title | artist | licence` per line) or `php tools/import-music.php <file> "<title>" "<artist>" "<licence>"`. Same checks as the Music page; the files go into `storage/music`. Keep licensed music out of git: Pixabay's licence, for example, allows it in the game but not sharing the files on their own.

## Along the way: tips, shop categories, GPUs, furniture and events

- **Tips along the way** (`public/assets/js/moments.js`): after the tutorial the characters keep turning up. The teacher explains each cable the first time it comes out (straight-through, crossover, fiber, console) and each kind of port the first time it shows in a port list (host-style MDI, switch-style MDI-X, WAN and LAN, fiber, console). She also tells students when they can afford something new (and opens the shop on it), when a level unlocks gear, when they can lease a bigger place, and what's new when they walk into the office or datacentre. Each tip shows once; the server remembers which (`user_seen`), and **Replay tutorial** resets them. The lines are under `moments` in `tutorial.json`.
- **Shop and inventory categories**, in Packet Tracer's order: End devices, Network devices, Security, GPUs, Cables, Furniture (`item_types.shop_group`). The shop has filter chips at the top.
- **GPUs** (level 6-7): GPU rigs are rack devices that earn on their own; GPU cards go inside a server for +45/60/70% income (click the server, then **Fit**; a swapped card goes back to the inventory). Each comes from three sellers: ByteBay (cheap, 80% performance), Northwind Components (standard) and Apex Silicon (dear, about 115%). Each seller's price drifts between 80% and 120% of list, and their stock (0-4, counted per student) refills every 20 minutes (`src/Market.php`), so it pays to shop around.
- **Furniture**: an office desk (adds a spot for a PC) and a server rack (adds a slot for rack gear). Place it on open floor like any item; pick it up with the remove tool once nothing stands on it (`src/Gear.php`, `user_furniture`). The office's front room is full, so desks fit in the garage and datacentre; racks fit in the office's server room too.
- **Random events**, once a student has leased the office, every 4-10 minutes of play (`event_min_minutes`, `event_max_minutes` in `game_config`; `src/Events.php`):
  - **Vex, the grey-market broker**, walks in, follows the student around for a couple of minutes and speaks up himself (like Ghost), asking if they want his help: **Take his help**, **Not now** (he keeps following; click him to ask again) or **Get lost**. Taking it usually (70%) gets 20-40% off every GPU for 10 minutes; otherwise he picks their pocket for 3-6% of their credits.
  - **Investors**: Mira from Lumen Capital texts an offer. The phone rings, a notification slides in, and a phone pops up on the map with her and the chat: **Accept**, **Ask for more** or **No thanks** (close it and the offer waits in the phone for 10 minutes). The offer is about 20 minutes of income or 8% of savings, whichever is more (a little more if the company is growing). Asking for more raises it 20-40% or she walks away: 15% for a small company, rising with income and savings to a cap of 80% once income reaches `investor_walk_income` (50k/s), plus 10 points per earlier ask. The button shows the chance. The phone also charts the company value (an hour of income, lifted by level and security rating, plus 5% of savings; a point every 10 minutes).
- **Three maps, each bigger than the last**: the garage is 20x12 tiles, the office 28x16 (four desks with room for more, and a server room with 12 rack slots) and the datacentre 36x20 (three rack rows, 48 slots, plus open floor for bought racks). Each place sets its own `cols` and `rows` in `layout.json`, and the map scales to fit the screen. `schema_scale.sql` moved gear already placed in the old office and datacentre to the matching new spots, once (`game_config.layout_version`).
- Hiring employees is planned; nothing for it exists yet.
- Tests: `php tests/EventsTest.php` (77 checks: prices, stock, the broker's discount, furniture placement, GPU cards, both broker outcomes, investor offers and haggling, tips) and `php tests/IncomeTest.php` (network boosts, money format).
