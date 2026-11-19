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
  <div class="brand"><i class="ti ti-topology-star-3" aria-hidden="true"></i>NetForge</div>
  <div class="place-wrap">
    <button type="button" class="place" id="place-btn" title="Where you are. Click to travel." aria-expanded="false" aria-controls="places">
      <i class="ti ti-map-pin" aria-hidden="true"></i><span id="loc-name">…</span><i class="ti ti-chevron-down caret" aria-hidden="true"></i>
    </button>
    <div class="places" id="places" hidden></div>
  </div>
  <button class="objective" title="Current objective. Opens Learn." data-open-learn>
    <i class="ti ti-target-arrow" aria-hidden="true"></i>
    <span class="what" id="obj-title">Loading your shift…</span>
    <span class="steps" id="obj-steps"></span>
  </button>
  <button class="security g-none" id="security" title="Security rating. Click for attack history."><i class="ti ti-shield-half" aria-hidden="true"></i><span class="grade mono">–</span><span class="pct">no attacks yet</span></button>
  <div class="stat streak mono" id="streak" title="Daily learning streak"><i class="ti ti-flame" aria-hidden="true"></i><span>0</span></div>
  <div class="stat income mono" id="income" title="Passive income from devices online"><i class="ti ti-trending-up" aria-hidden="true"></i><span>+0/h</span></div>
  <div class="stat money mono" title="Credits"><i class="ti ti-coins" aria-hidden="true"></i><span id="credits">0</span></div>
  <div class="stat xp" title="Level and XP">
    <span id="level">Lv 1</span><span class="bar"><span id="xp-fill"></span></span><small class="mono" id="xp-text">0/1000</small>
  </div>
  <div class="menu-wrap">
    <button class="iconbtn" id="menu-btn" aria-label="Menu" aria-expanded="false" aria-controls="menu"><i class="ti ti-menu-2"></i></button>
    <div class="menu" id="menu" hidden>
      <p class="who mono" id="who"></p>
      <a href="profile.php"><i class="ti ti-user-circle" aria-hidden="true"></i>Profile</a>
      <form method="post" action="logout.php">
        <?= Csrf::field() ?>
        <button type="submit"><i class="ti ti-logout" aria-hidden="true"></i>Sign out</button>
      </form>
    </div>
  </div>
</header>

<div class="main">
  <nav class="rail" aria-label="Tools">
    <button class="tool on" data-tool="select" aria-label="Select and move (V)"><i class="ti ti-pointer"></i><kbd>V</kbd></button>
    <button class="tool" data-tool="cable" aria-label="Cable (C)"><i class="ti ti-plug-connected"></i><kbd>C</kbd></button>
    <button class="tool" data-tool="inspect" aria-label="Inspect (X)"><i class="ti ti-zoom-scan"></i><kbd>X</kbd></button>
    <button class="tool" data-tool="remove" aria-label="Remove (Del)"><i class="ti ti-trash"></i></button>
    <span class="sep"></span>
    <button class="tool learn-tool" data-open-learn aria-label="Learn (L)"><i class="ti ti-book-2"></i><kbd>L</kbd></button>
    <button class="tool social-tool" data-open-social aria-label="Guild and friends (G)"><i class="ti ti-users-group"></i><kbd>G</kbd></button>
    <button class="tool on" data-tab="inv" aria-label="Inventory (I)"><i class="ti ti-backpack"></i><kbd>I</kbd></button>
    <button class="tool" data-tab="shop" aria-label="Shop (B)"><i class="ti ti-building-store"></i><kbd>B</kbd></button>
  </nav>

  <section class="stage grid-floor">
    <div class="map" id="map">
      <div id="game"></div>
      <div class="cablebar" id="cablebar" role="radiogroup" aria-label="Cable type" hidden>
        <button class="on" role="radio" aria-checked="true" data-cable="copper"><span class="sw sw-copper"></span>Copper</button>
        <button role="radio" aria-checked="false" data-cable="crossover"><span class="sw sw-crossover"></span>Crossover</button>
        <button role="radio" aria-checked="false" data-cable="fiber"><span class="sw sw-fiber"></span>Fiber</button>
        <button role="radio" aria-checked="false" data-cable="console"><span class="sw sw-console"></span>Console</button>
      </div>
      <div id="tags"></div>
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
    <nav class="learn-nav" aria-label="Worlds">
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
