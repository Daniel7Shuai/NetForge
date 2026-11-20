<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\GameError;
use NetForge\Story;

// The people who talk to students in the tutorial and during attacks. Staff set their
// names and upload portrait art; with no art, the game draws a built-in pixel portrait.
$me = Staff::begin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = (string) ($_POST['code'] ?? '');
    try {
        match ($_POST['action'] ?? '') {
            'save'   => Story::updateCharacter($code, (string) ($_POST['name'] ?? ''), (string) ($_POST['role'] ?? ''), (string) ($_POST['side'] ?? '')),
            'art'    => Story::saveArt($code, $_FILES['art'] ?? []),
            'noart'  => Story::removeArt($code),
            default  => throw new GameError('Unknown action.'),
        };
        Staff::flash('ok', match ($_POST['action']) {
            'art' => 'Portrait uploaded. Students see it the next time a dialogue opens.',
            'noart' => 'Portrait removed. The game draws the built-in one again.',
            default => 'Saved.',
        });
    } catch (GameError $e) {
        Staff::flash('error', $e->getMessage());
    }
    Staff::back();
}

$chars = Story::characters();
$uses = [
    'teacher' => 'Guides new students through the tutorial: placing the laptop, buying a router and cabling the ISP.',
    'hacker' => 'Appears in the tutorial and taunts the student when a cyber attack starts.',
    'dad' => 'The student’s dad, founder of NetForge. Taken in the opening cutscene; rescued in the ending.',
    'lucas' => 'CEO of DataTech, the rival who has Dad arrested in the opening cutscene.',
    'reaper' => 'DataTech’s armoured security, who raid the datacentre in the opening cutscene.',
    'broker' => 'Vex: turns up after the office is leased, offering GPU deals (or picking pockets).',
    'investor' => 'Mira: texts investment offers to the student’s phone.',
];

$title = 'Characters';
$active = 'characters';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<div class="page-head">
  <div><h1>Characters</h1>
  <p class="lede">The people who talk to students. Change their names, and upload portrait art: a square PNG with a transparent background looks best, up to 700 KB. With no art, the game draws a built-in pixel portrait.</p></div>
</div>

<div class="char-grid">
<?php foreach ($chars as $c): ?>
  <section class="panel char">
    <div class="char-art">
      <?php if ($c['art']): ?>
        <img src="../<?= e($c['art']) ?>" alt="Portrait of <?= e($c['name']) ?>">
      <?php else: ?>
        <canvas data-portrait="<?= e($c['code']) ?>" data-scale="5" role="img" aria-label="Built-in portrait of <?= e($c['name']) ?>"></canvas>
      <?php endif; ?>
      <p class="help"><?= $c['art'] ? 'Uploaded portrait' : 'Built-in portrait' ?></p>
    </div>
    <div class="char-body">
      <h2><?= e($c['name']) ?> <span class="tag mono"><?= e($c['code']) ?></span></h2>
      <p class="help"><?= e($uses[$c['code']] ?? 'Used in the game’s dialogue.') ?></p>
      <form method="post" class="form">
        <?= Csrf::field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="code" value="<?= e($c['code']) ?>">
        <div class="grid2">
          <label>Name <input name="name" value="<?= e($c['name']) ?>" maxlength="40" required></label>
          <label>Role <input name="role" value="<?= e($c['role']) ?>" maxlength="80"><small>Shown under the name</small></label>
        </div>
        <label>Stands on the <select name="side">
          <option value="left" <?= $c['side'] === 'left' ? 'selected' : '' ?>>Left of the dialogue box</option>
          <option value="right" <?= $c['side'] === 'right' ? 'selected' : '' ?>>Right of the dialogue box</option></select></label>
        <div class="actions start"><button class="btn primary">Save</button></div>
      </form>
      <form method="post" enctype="multipart/form-data" class="form art-form">
        <?= Csrf::field() ?><input type="hidden" name="action" value="art"><input type="hidden" name="code" value="<?= e($c['code']) ?>">
        <label>New portrait <input type="file" name="art" accept="image/png,image/jpeg,image/gif,image/webp" required></label>
        <div class="actions start"><button class="btn">Upload portrait</button></div>
      </form>
      <?php if ($c['art']): ?>
        <form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="action" value="noart"><input type="hidden" name="code" value="<?= e($c['code']) ?>">
          <button class="btn ghost sm">Use the built-in portrait again</button></form>
      <?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>
<script type="module">import { paintPortraits } from '../assets/js/game/art.js'; import '../assets/js/game/story-art.js'; paintPortraits();</script>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
