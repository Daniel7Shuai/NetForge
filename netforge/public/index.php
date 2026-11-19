<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use NetForge\Auth;
use NetForge\Db;

// Signed-in players go straight to their home; everyone else sees the landing page.
if ($me = Auth::current()) {
    header('Location: ' . Auth::home($me['role']));
    exit;
}

// Live numbers so the page never over-promises.
$worlds = Db::all(
    'SELECT w.number, w.title, w.summary, w.ccna_modules,
            (SELECT COUNT(*) FROM levels l WHERE l.world_id = w.id AND l.archived = 0) levels,
            (SELECT COUNT(*) FROM questions q WHERE q.world_id = w.id AND q.archived = 0) questions
       FROM worlds w WHERE w.archived = 0 ORDER BY w.number'
);
$totalQ = array_sum(array_column($worlds, 'questions'));
$students = (int) Db::one('SELECT COUNT(*) n FROM users WHERE role = "student"')['n'];

// The three journeys: where your network lives as you progress.
$journeys = [
    ['name' => 'The garage', 'icon' => 'ti-home-cog', 'from' => 0, 'to' => 2,
     'pitch' => 'A busted laptop, a fibre box on the wall and a lot to learn. Get yourself online.'],
    ['name' => 'The office', 'icon' => 'ti-building', 'from' => 3, 'to' => 5,
     'pitch' => 'Desks, switches and a server room behind glass. Wire a real small-business network.'],
    ['name' => 'The datacentre', 'icon' => 'ti-server-2', 'from' => 6, 'to' => 8,
     'pitch' => 'Racks, routing and the attacks that come with them. Run it, and defend it.'],
];

$title = 'NetForge: learn networking by building one';
$styles = ['landing'];
require __DIR__ . '/../src/views/head.php';
?>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="nav">
  <a class="brand" href="./"><i class="ti ti-topology-star-3" aria-hidden="true"></i>NetForge</a>
  <nav aria-label="Page">
    <a href="#loop">How it works</a>
    <a href="#journeys">Worlds</a>
    <a href="#lecturers">For lecturers</a>
  </nav>
  <div class="nav-cta">
    <a class="link" href="login.php">Sign in</a>
    <a class="btn primary" href="register.php">Start building</a>
  </div>
</header>

<main id="main">
  <section class="hero" aria-labelledby="hero-title">
    <canvas id="hero-art" width="320" height="192" aria-hidden="true"></canvas>
    <div class="hero-copy">
      <h1 id="hero-title">Learn networking by building&nbsp;one.</h1>
      <p class="lede">Start in a garage with a cracked laptop. Answer CCNA questions to level up, rent out your network to pay for gear, then cable it port to port and keep the attackers out.</p>
      <div class="hero-cta">
        <a class="btn primary big" href="register.php">Start building, it’s free</a>
        <a class="btn ghost big" href="#journeys">See the worlds</a>
      </div>
      <dl class="hero-stats mono">
        <div><dt>Worlds</dt><dd><?= count($worlds) ?></dd></div>
        <div><dt>CCNA ITN modules</dt><dd>17</dd></div>
        <div><dt>Questions</dt><dd><?= $totalQ ?>+</dd></div>
      </dl>
    </div>
  </section>

  <section class="loop" id="loop" aria-labelledby="loop-title">
    <h2 id="loop-title">One loop, from garage to datacentre</h2>
    <ol class="steps">
      <li><span class="n mono">1</span><i class="ti ti-book-2" aria-hidden="true"></i><strong>Learn</strong><span>Short lessons, then questions that adapt to you.</span></li>
      <li><span class="n mono">2</span><i class="ti ti-trending-up" aria-hidden="true"></i><strong>Level up</strong><span>XP comes only from right answers. Levels unlock gear.</span></li>
      <li><span class="n mono">3</span><i class="ti ti-shopping-cart" aria-hidden="true"></i><strong>Buy</strong><span>Routers, switches, servers, the right cables.</span></li>
      <li><span class="n mono">4</span><i class="ti ti-plug-connected" aria-hidden="true"></i><strong>Wire it</strong><span>Port to port. The wrong cable leaves the link down.</span></li>
      <li><span class="n mono">5</span><i class="ti ti-coins" aria-hidden="true"></i><strong>Earn</strong><span>Every device online rents out capacity, hour by hour.</span></li>
      <li><span class="n mono">6</span><i class="ti ti-shield-lock" aria-hidden="true"></i><strong>Defend</strong><span>Port scans and phishing, built from what you’ve learned.</span></li>
    </ol>
  </section>

  <section class="journeys" id="journeys" aria-labelledby="journeys-title">
    <div class="sec-head">
      <h2 id="journeys-title">Three places, nine worlds</h2>
      <p>The worlds follow Cisco’s CCNA Introduction to Networks course in order. Master one by passing its exam, and your network moves somewhere bigger.</p>
    </div>
    <?php foreach ($journeys as $j):
        $mine = array_values(array_filter($worlds, fn ($w) => $w['number'] >= $j['from'] && $w['number'] <= $j['to']));
        $q = array_sum(array_column($mine, 'questions'));
        $mods = $mine ? explode('-', $mine[0]['ccna_modules'])[0] . '–' . (explode('-', end($mine)['ccna_modules'])[1] ?? end($mine)['ccna_modules']) : ''; ?>
      <article class="journey">
        <header>
          <i class="ti <?= $j['icon'] ?>" aria-hidden="true"></i>
          <div><h3><?= e($j['name']) ?></h3><p><?= e($j['pitch']) ?></p></div>
          <ul class="jstats mono">
            <li><b><?= count($mine) ?></b> worlds</li>
            <li><b><?= $q ?></b> questions</li>
            <li>modules <b><?= e($mods) ?></b></li>
          </ul>
        </header>
        <ol class="worlds">
          <?php foreach ($mine as $w): $ready = (int) $w['levels'] > 0; ?>
            <li class="<?= $ready ? 'ready' : 'soon' ?>">
              <strong><span class="wn mono">W<?= (int) $w['number'] ?></span><?= e($w['title']) ?></strong>
              <p><?= e($w['summary']) ?></p>
              <span class="meta mono">CCNA modules <?= e($w['ccna_modules']) ?><?= $ready ? ', ' . (int) $w['questions'] . ' questions' : '' ?></span>
              <span class="tag <?= $ready ? ((int) $w['number'] === 0 ? 'start' : 'open') : 'later' ?>"><?= $ready ? ((int) $w['number'] === 0 ? 'Start here' : 'Playable') : 'In progress' ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="feature" aria-labelledby="f1">
    <div class="f-copy">
      <h2 id="f1">Cables that behave like the real thing</h2>
      <p>Pick a cable, click a device, choose a port. Plug a straight-through cable between two switch ports and the link stays pink and down, just like in Packet Tracer, and the console tells you why.</p>
    </div>
    <div class="f-demo picker" aria-label="Example: the port picker on a home router">
      <p class="pk-head">HomeRT-1 <span class="mono">pick a port</span></p>
      <p class="pk-row up"><span class="led"></span><span class="mono">WAN</span><span class="mono to">→ ISP-1 LAN</span></p>
      <p class="pk-row hl"><span class="led"></span><span class="mono">LAN1</span><span class="mono to">switch-style</span></p>
      <p class="pk-row"><span class="led"></span><span class="mono">LAN2</span><span class="mono to">switch-style</span></p>
      <p class="pk-row off"><span class="led"></span><span class="mono">USB0</span><span class="mono to">needs a console cable</span></p>
      <p class="pk-log mono"><span class="ok">Laptop-1 Eth0 → HomeRT-1 LAN1, copper cable. Link up.</span></p>
    </div>
  </section>

  <section class="feature flip" aria-labelledby="f2">
    <div class="f-copy">
      <h2 id="f2">Attacks built from what you’ve learned</h2>
      <p>Master a world and its threats start showing up: phishing emails, port scans, ARP spoofing, SYN floods. Read the evidence, pick your response before the clock runs out, and keep your security rating up.</p>
    </div>
    <div class="f-demo attack" aria-label="Example: an incoming port scan">
      <p class="atk-head"><i class="ti ti-radar-2" aria-hidden="true"></i>Incoming attack: Port scan <span class="mono clock">0:42</span></p>
      <pre class="mono">FIREWALL 14:02:11 SYN 203.0.113.45 -> :21
FIREWALL 14:02:11 SYN 203.0.113.45 -> :22
FIREWALL 14:02:12 SYN 203.0.113.45 -> :445
FIREWALL 14:02:12 SYN 203.0.113.45 -> :3389</pre>
      <p class="atk-q">What’s the best first response?</p>
    </div>
  </section>

  <section class="feature" aria-labelledby="f3">
    <div class="f-copy">
      <h2 id="f3">Learn together, without the pressure</h2>
      <p>Join a guild of up to eight and take on the weekly raid as a team. Leaderboards only show the students just above and below you, never the whole class. Questions adapt to your level, so the challenge stays fair.</p>
    </div>
    <div class="f-demo board" aria-label="Example: the nearby leaderboard">
      <p class="bd-head">You this week: <b class="mono">#4</b></p>
      <ol class="mono">
        <li><span>#2</span><span>packet_pat</span><span>620 XP</span></li>
        <li><span>#3</span><span>subnet_sam</span><span>585 XP</span></li>
        <li class="me"><span>#4</span><span>You</span><span>540 XP</span></li>
        <li><span>#5</span><span>arp_alex</span><span>495 XP</span></li>
        <li><span>#6</span><span>vlan_vee</span><span>460 XP</span></li>
      </ol>
      <p class="raid"><i class="ti ti-swords" aria-hidden="true"></i>Raid: Subnet Siege <span class="bar"><span style="width:72%"></span></span><span class="mono">72/100</span></p>
    </div>
  </section>

  <section class="lecturers" id="lecturers" aria-labelledby="lect-title">
    <div>
      <h2 id="lect-title">For lecturers</h2>
      <p>See which topics your class finds hard before the test, not after.</p>
      <a class="btn" href="login.php">Lecturer sign in</a>
    </div>
    <ul>
      <li><i class="ti ti-database-import" aria-hidden="true"></i><span><strong>Question bank and CSV import.</strong> Six question types; every rejected row is reported with its reason.</span></li>
      <li><i class="ti ti-chart-bar" aria-hidden="true"></i><span><strong>Class analytics.</strong> Accuracy by world and topic, and a report on which questions are harder than their rating says.</span></li>
      <li><i class="ti ti-alert-triangle" aria-hidden="true"></i><span><strong>At-risk alerts.</strong> Low accuracy, inactivity, falling ratings and review backlogs, each with the reason.</span></li>
      <li><i class="ti ti-file-type-pdf" aria-hidden="true"></i><span><strong>Reports.</strong> Class and student progress for any period, as CSV or PDF.</span></li>
    </ul>
  </section>

  <section class="final">
    <h2>Your garage is waiting.</h2>
    <p><?= $students > 1 ? $students . ' students are already wiring theirs.' : 'Be the first to get it online.' ?></p>
    <a class="btn primary big" href="register.php">Create your account</a>
  </section>
</main>

<footer class="foot">
  <span class="brand small"><i class="ti ti-topology-star-3" aria-hidden="true"></i>NetForge</span>
  <span>A gamified, adaptive way to learn networking. Content follows Cisco CCNA: Introduction to Networks.</span>
  <a href="login.php">Sign in</a>
</footer>

<script type="module" src="assets/js/landing.js"></script>
</body>
</html>
