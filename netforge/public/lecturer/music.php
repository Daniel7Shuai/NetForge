<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\GameError;
use NetForge\Story;

// Background music for the game. Five built-in tracks are composed in code (no copyright
// issues); staff can upload more, say where each one came from, switch any off and set the order.
$me = Staff::begin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['action'] ?? '') {
            case 'upload':
                Story::addTrack($_FILES['track'] ?? [], (string) ($_POST['title'] ?? ''), (string) ($_POST['artist'] ?? ''), (string) ($_POST['licence'] ?? ''));
                Staff::flash('ok', 'Track added to the end of the play list.');
                break;
            case 'save':
                foreach ((array) ($_POST['t'] ?? []) as $id => $v) {
                    Story::updateTrack((int) $id, (string) ($v['title'] ?? ''), (string) ($v['artist'] ?? ''), (int) ($v['sort'] ?? 100), isset($v['enabled']));
                }
                Staff::flash('ok', 'Play list saved. Students hear the changes from their next track.');
                break;
            case 'delete':
                Story::deleteTrack((int) ($_POST['id'] ?? 0));
                Staff::flash('ok', 'Track deleted.');
                break;
            default:
                throw new GameError('Unknown action.');
        }
    } catch (GameError $e) {
        Staff::flash('error', $e->getMessage());
    }
    Staff::back();
}

$tracks = Story::tracks(true);
$size = fn (?int $b) => $b === null ? '' : ($b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' KB');

$title = 'Music';
$active = 'music';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<div class="page-head">
  <div><h1>Music</h1>
  <p class="lede">The game plays these in order, looping the list. Students turn music on or off and set the volume themselves. The built-in tracks were composed for NetForge, so they’re free of copyright; only upload music you’re allowed to use.</p></div>
</div>

<section class="panel">
  <h2>Play list</h2>
  <form method="post" id="playlist">
    <?= Csrf::field() ?><input type="hidden" name="action" value="save">
    <table class="data tracks">
      <thead><tr><th scope="col">On</th><th scope="col" class="num">Order</th><th scope="col">Title</th><th scope="col">Artist</th><th scope="col">Source</th><th scope="col">Listen</th><th scope="col" class="act"><span class="visually-hidden">Delete</span></th></tr></thead>
      <tbody>
      <?php foreach ($tracks as $t): $id = $t['id']; ?>
        <tr class="<?= $t['enabled'] ? '' : 'dim' ?>">
          <td><input type="checkbox" name="t[<?= $id ?>][enabled]" <?= $t['enabled'] ? 'checked' : '' ?> aria-label="Play <?= e($t['title']) ?>"></td>
          <td class="num"><input class="num-in mono" type="number" name="t[<?= $id ?>][sort]" value="<?= $t['sort'] ?>" min="0" max="10000" aria-label="Order of <?= e($t['title']) ?>"></td>
          <td><input name="t[<?= $id ?>][title]" value="<?= e($t['title']) ?>" maxlength="80" aria-label="Title"></td>
          <td><input name="t[<?= $id ?>][artist]" value="<?= e($t['artist']) ?>" maxlength="80" aria-label="Artist"></td>
          <td><small><?= $t['kind'] === 'synth' ? 'Built in' : 'Upload, ' . e($size($t['bytes'])) ?><br><?= e($t['licence']) ?></small></td>
          <td><?php if ($t['kind'] === 'synth'): ?>
              <button type="button" class="btn sm" data-preview="<?= e($t['synth']) ?>"><i class="ti ti-player-play" aria-hidden="true"></i><span>Play</span></button>
            <?php else: ?>
              <audio controls preload="none" src="../<?= e($t['url']) ?>"></audio>
            <?php endif; ?></td>
          <td class="act"><?php if ($t['kind'] === 'file'): ?>
            <button class="btn sm danger" form="del-<?= $id ?>" data-tip="Delete this upload and its file">Delete</button>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="actions"><button class="btn primary">Save play list</button></div>
  </form>
  <?php foreach ($tracks as $t): if ($t['kind'] === 'file'): ?>
    <form method="post" id="del-<?= $t['id'] ?>" hidden><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $t['id'] ?>"></form>
  <?php endif; endforeach; ?>
</section>

<section class="panel">
  <h2>Add a track</h2>
  <form method="post" enctype="multipart/form-data" class="form">
    <?= Csrf::field() ?><input type="hidden" name="action" value="upload">
    <div class="grid2">
      <label>Audio file <small>MP3, OGG, WAV, M4A or WebM, up to 30 MB</small>
        <input type="file" name="track" accept="audio/mpeg,audio/ogg,audio/wav,audio/mp4,audio/webm,.mp3,.ogg,.wav,.m4a,.webm" required></label>
      <label>Title <input name="title" maxlength="80" required></label>
      <label>Artist <input name="artist" maxlength="80"></label>
      <label>Source and licence <small>Where it’s from and why it’s free to use</small>
        <input name="licence" maxlength="200" required placeholder="CC0 from freepd.com, or Composed by our team"></label>
    </div>
    <p class="help">Royalty-free isn’t always free to share. Prefer CC0 or public domain tracks, or ones made by your team, and keep the licence note accurate: it’s shown here for anyone checking.</p>
    <div class="actions"><button class="btn primary">Upload track</button></div>
  </form>
</section>
<script type="module">
  import { previewSynth, stopPreview } from '../assets/js/music.js';
  let playing = null;
  document.querySelectorAll('[data-preview]').forEach((b) => b.addEventListener('click', () => {
    const was = playing;
    stopPreview();
    document.querySelectorAll('[data-preview] span').forEach((s) => { s.textContent = 'Play'; });
    playing = null;
    if (was === b) return;
    previewSynth(b.dataset.preview);
    b.querySelector('span').textContent = 'Stop';
    playing = b;
  }));
</script>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
