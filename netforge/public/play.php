<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use NetForge\Auth;
use NetForge\Csrf;

Auth::requirePage();

$title = 'NetForge';
$styles = ['play'];
require __DIR__ . '/../src/views/head.php';
?>
<body>
<header class="top">
  <div class="top-left">
  <div class="brand"><i class="ti ti-topology-star-3" aria-hidden="true"></i><span>NetForge</span></div>
  <div class="place-wrap">
    <button type="button" class="place" id="place-btn" title="Where you are. Click to travel." aria-expanded="false" aria-controls="places">
      <i class="ti ti-map-pin" aria-hidden="true"></i><span id="loc-name">…</span><i class="ti ti-chevron-down caret" aria-hidden="true"></i>
    </button>
    <div class="places" id="places" hidden></div>
  </div>
  </div>
  <div class="wallet" id="wallet" data-tip="Your credits">
    <i class="ti ti-coins" aria-hidden="true"></i>
    <span class="amount mono" id="credits">0</span>
    <span class="rate mono" id="income"><i class="ti ti-trending-up" aria-hidden="true"></i><span>+0/s</span></span>
  </div>
  <div class="top-right">
  <button class="security g-none" id="security" title="Security rating from your recent cyber attacks. Click for the attack history."><i class="ti ti-shield-half" aria-hidden="true"></i><span class="grade mono">–</span><span class="pct">no attacks yet</span></button>
  <div class="stat streak mono" id="streak" title="Daily learning streak: days in a row you’ve answered a question correctly."><i class="ti ti-flame" aria-hidden="true"></i><span>0</span></div>
  <div class="stat xp" title="Your level and XP. Answer questions in Learn (L) for XP; each level unlocks more gear in the shop.">
    <span id="level">Lv 1</span><span class="bar"><span id="xp-fill"></span></span><small class="mono" id="xp-text">0/1000</small>
  </div>
  <div class="phone-wrap">
    <button class="iconbtn" id="phone-btn" aria-label="Phone: texts and your company value" aria-expanded="false" aria-controls="phone" data-tip="Phone: texts from investors, and your company’s value over time."><i class="ti ti-device-mobile" aria-hidden="true"></i><span class="badge mono" hidden>0</span></button>
    <div class="phone" id="phone" role="dialog" aria-label="Phone" hidden></div>
  </div>
  <div class="music-wrap">
    <button class="iconbtn" id="music-btn" aria-label="Music" aria-expanded="false" aria-controls="music-panel"><i class="ti ti-music" aria-hidden="true"></i></button>
    <div class="music-panel" id="music-panel" hidden>
      <p class="np"><small>Now playing</small><b id="music-title">Music is off</b><span id="music-artist"></span></p>
      <div class="music-row">
        <button type="button" class="iconbtn" id="music-toggle" aria-label="Play music"><i class="ti ti-player-play" aria-hidden="true"></i></button>
        <button type="button" class="iconbtn" id="music-next" aria-label="Next track"><i class="ti ti-player-skip-forward" aria-hidden="true"></i></button>
        <label class="vol"><i class="ti ti-volume" aria-hidden="true"></i><input type="range" id="music-volume" min="0" max="100" step="1" aria-label="Music volume"></label>
      </div>
    </div>
  </div>
  <div class="menu-wrap">
    <button class="iconbtn" id="menu-btn" aria-label="Menu: profile and sign out" aria-expanded="false" aria-controls="menu"><i class="ti ti-menu-2"></i></button>
    <div class="menu" id="menu" hidden>
      <p class="who mono" id="who"></p>
      <a href="profile.php"><i class="ti ti-user-circle" aria-hidden="true"></i>Profile</a>
      <button type="button" id="intro-replay"><i class="ti ti-movie" aria-hidden="true"></i>Watch the intro</button>
      <button type="button" id="tutorial-replay"><i class="ti ti-school" aria-hidden="true"></i>Replay tutorial</button>
      <form method="post" action="logout.php">
        <?= Csrf::field() ?>
        <button type="submit"><i class="ti ti-logout" aria-hidden="true"></i>Sign out</button>
      </form>
    </div>
  </div>
  </div>
</header>
<button class="objective" title="Your current objective. Click to open Learn." data-open-learn>
  <i class="ti ti-target-arrow" aria-hidden="true"></i>
  <span class="what" id="obj-title">Loading your shift…</span>
  <span class="steps" id="obj-steps"></span>
</button>

<div class="main">
  <nav class="rail" aria-label="Tools">
    <button class="tool on" data-tool="select" aria-label="Select and move (V)" data-tip="Select (V): click a device to see its ports, upgrade it or move it. Pick an item in your inventory, then click a glowing spot to place it."><i class="ti ti-pointer"></i><kbd>V</kbd></button>
    <button class="tool" data-tool="cable" aria-label="Cable (C)" data-tip="Cable (C): choose a cable type at the bottom of the map, click a device and a port, then the second device and its port."><i class="ti ti-plug-connected"></i><kbd>C</kbd></button>
    <button class="tool" data-tool="inspect" aria-label="Inspect (X)" data-tip="Inspect (X): click a device to see every port, its role and what it’s plugged into."><i class="ti ti-zoom-scan"></i><kbd>X</kbd></button>
    <button class="tool" data-tool="remove" aria-label="Remove (Del)" data-tip="Remove (Del): click a device to unplug its cables (they go back to your inventory) or pick it up."><i class="ti ti-trash"></i></button>
    <span class="sep"></span>
    <button class="tool learn-tool" data-open-learn aria-label="Learn (L)" data-tip="Learn (L): lessons, challenges and exams. Correct answers earn XP, and your level unlocks new gear."><i class="ti ti-book-2"></i><kbd>L</kbd></button>
    <button class="tool social-tool" data-open-social aria-label="Guild and friends (G)" data-tip="Social (G): your guild and its weekly raid, leaderboards, friends and badges."><i class="ti ti-users-group"></i><kbd>G</kbd></button>
    <button class="tool on" data-tab="inv" aria-label="Inventory (I)" data-tip="Inventory (I): the gear you own. Click a device, or drag it onto a glowing spot on the map."><i class="ti ti-backpack"></i><kbd>I</kbd></button>
    <button class="tool" data-tab="shop" aria-label="Shop (B)" data-tip="Shop (B): spend credits on gear. Your level decides what’s for sale."><i class="ti ti-building-store"></i><kbd>B</kbd></button>
  </nav>

  <section class="stage grid-floor">
    <div class="map" id="map">
      <div id="game"></div>
      <div class="cablebar" id="cablebar" role="radiogroup" aria-label="Cable type" hidden>
        <button class="on" role="radio" aria-checked="true" data-cable="copper" data-tip="Copper (straight-through): joins unlike devices, like a laptop or router to a switch, or a router to the ISP box."><span class="sw sw-copper"></span>Copper</button>
        <button role="radio" aria-checked="false" data-cable="crossover" data-tip="Crossover: joins like devices, like switch to switch or PC to PC."><span class="sw sw-crossover"></span>Crossover</button>
        <button role="radio" aria-checked="false" data-cable="fiber" data-tip="Fiber: fast links between fibre ports, like the datacentre’s ISP panel and its racks."><span class="sw sw-fiber"></span>Fiber</button>
        <button role="radio" aria-checked="false" data-cable="console" data-tip="Console: plugs into a device’s console port to manage it. It carries no network traffic."><span class="sw sw-console"></span>Console</button>
      </div>
      <div id="tags"></div>
      <div id="coins" aria-hidden="true"></div>
      <div class="picker" id="picker" role="dialog" aria-label="Ports" hidden></div>
    </div>
  </section>

  <aside class="drawer">
    <div class="tabs" role="tablist">
      <button class="on" role="tab" data-tab="inv">Inventory <kbd>I</kbd></button>
      <button role="tab" data-tab="shop">Shop <kbd>B</kbd></button>
    </div>
    <div class="pane" id="pane-inv"></div>
    <div class="pane" id="pane-shop" hidden></div>
  </aside>

  <section class="learn" id="learn" hidden aria-label="Learn">
    <nav class="learn-nav" aria-label="Topics">
      <div class="learn-top">
        <h2><i class="ti ti-book-2" aria-hidden="true"></i>Learn</h2>
        <button class="iconbtn" id="learn-close" aria-label="Back to the map (Esc)"><i class="ti ti-x"></i></button>
      </div>
      <div class="worlds" id="learn-worlds"></div>
    </nav>
    <div class="learn-view" id="learn-view"></div>
  </section>

  <section class="attack" id="attack" role="alertdialog" aria-modal="true" hidden></section>

  <section class="learn social" id="social" hidden aria-label="Guild and friends">
    <nav class="learn-nav" aria-label="Social">
      <div class="learn-top">
        <h2><i class="ti ti-users-group" aria-hidden="true"></i>Social</h2>
        <button class="iconbtn" id="social-close" aria-label="Back to the map (Esc)"><i class="ti ti-x"></i></button>
      </div>
      <div class="worlds" id="social-tabs">
        <button class="world" data-tab-social="guild"><i class="ti ti-shield-half wnum" aria-hidden="true"></i><span class="wtitle">Guild<small>Raid, goal, members</small></span></button>
        <button class="world" data-tab-social="board"><i class="ti ti-chart-bar wnum" aria-hidden="true"></i><span class="wtitle">Leaderboards<small>Nearby ranks, guilds</small></span></button>
        <button class="world" data-tab-social="friends"><i class="ti ti-user-heart wnum" aria-hidden="true"></i><span class="wtitle">Friends<small>Classmates’ progress</small></span></button>
        <button class="world" data-tab-social="badges"><i class="ti ti-award wnum" aria-hidden="true"></i><span class="wtitle">Badges<small>Milestones and streak</small></span></button>
      </div>
    </nav>
    <div class="learn-view" id="social-view"></div>
  </section>

  <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

  <footer class="console" id="console" aria-live="polite"></footer>
</div>

<script>window.NF = { csrf: <?= json_encode(Csrf::token()) ?> };</script>
<script src="https://cdn.jsdelivr.net/npm/phaser@3.80.1/dist/phaser.min.js"></script>
<script type="module" src="assets/js/main.js"></script>
</body>
</html>
