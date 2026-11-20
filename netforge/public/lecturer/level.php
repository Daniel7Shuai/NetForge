<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\Db;

// FR-2.1: create or edit a level: its lesson text and challenge settings.
$me = Staff::begin();
$id = (int) ($_GET['id'] ?? 0);
$l = $id ? Db::one('SELECT * FROM levels WHERE id = ?', [$id]) : null;
if ($id && $l === null) {
    Staff::flash('error', 'That level doesn’t exist.');
    Staff::redirect('index.php');
}
$worldId = $l ? (int) $l['world_id'] : (int) ($_GET['world'] ?? 0);
$w = Db::one('SELECT id, number, title FROM worlds WHERE id = ?', [$worldId]);
if ($w === null) {
    Staff::redirect('index.php');
}

$form = $l ?? ['title' => '', 'lesson' => '', 'questions_per_run' => 5, 'pass_pct' => 60];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'title' => trim((string) ($_POST['title'] ?? '')),
        'lesson' => trim(str_replace("\r\n", "\n", (string) ($_POST['lesson'] ?? ''))),
        'questions_per_run' => Staff::int($_POST['questions_per_run'] ?? '', 1, 30, 5),
        'pass_pct' => Staff::int($_POST['pass_pct'] ?? '', 1, 100, 60),
    ];
    if ($form['title'] === '' || mb_strlen($form['title']) > 80) {
        $errors[] = 'Give the level a title of up to 80 characters.';
    }
    if (mb_strlen($form['lesson']) < 20) {
        $errors[] = 'Write the lesson (at least a couple of sentences). Students read it before the challenge.';
    }
    if (!$errors) {
        if ($l) {
            Db::exec('UPDATE levels SET title = ?, lesson = ?, questions_per_run = ?, pass_pct = ? WHERE id = ?',
                [$form['title'], $form['lesson'], $form['questions_per_run'], $form['pass_pct'], $id]);
        } else {
            $next = (int) Db::one('SELECT COALESCE(MAX(sort_order), 0) + 1 n FROM levels WHERE world_id = ?', [$worldId])['n'];
            Db::exec('INSERT INTO levels (world_id, sort_order, title, lesson, questions_per_run, pass_pct) VALUES (?, ?, ?, ?, ?, ?)',
                [$worldId, $next, $form['title'], $form['lesson'], $form['questions_per_run'], $form['pass_pct']]);
            $id = (int) Db::pdo()->lastInsertId();
        }
        Staff::flash('ok', $l ? 'Level saved.' : 'Level created. Add questions to it next.');
        Staff::redirect('world.php?id=' . $worldId);
    }
}
$qCount = $id ? (int) Db::one('SELECT COUNT(*) n FROM questions WHERE level_id = ? AND archived = 0', [$id])['n'] : 0;

$title = $l ? $l['title'] : 'New level';
$active = 'index';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<p class="crumbs"><a href="index.php">Content</a> / <a href="world.php?id=<?= $worldId ?>">Topic <?= (int) $w['number'] ?></a> / <?= $l ? 'Edit level' : 'New level' ?></p>
<div class="page-head">
  <h1><?= $l ? e($l['title']) : 'New level in W' . (int) $w['number'] ?></h1>
  <?php if ($l): ?><a class="btn" href="questions.php?level=<?= $id ?>"><i class="ti ti-list-details" aria-hidden="true"></i><?= $qCount ?> questions</a><?php endif; ?>
</div>

<?php if ($errors): ?>
  <div class="flash error" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="form">
  <?= Csrf::field() ?>
  <div class="grid3">
    <label class="span2">Title <input name="title" value="<?= e($form['title']) ?>" maxlength="80" required></label>
    <label>Questions per run <input name="questions_per_run" type="number" min="1" max="30" value="<?= (int) $form['questions_per_run'] ?>"></label>
  </div>
  <div class="grid3">
    <label>Pass mark (%) <input name="pass_pct" type="number" min="1" max="100" value="<?= (int) $form['pass_pct'] ?>"><small>Score this to complete the level.</small></label>
  </div>
  <div class="split">
    <label>Lesson
      <textarea name="lesson" id="lesson-src" rows="18" required><?= e($form['lesson']) ?></textarea>
      <small>Blank line between paragraphs. Start lines with “- ” for a list or “1. ” for numbered steps. **bold** and `code` work. Put device commands between lines of ``` to show them as a terminal block.</small>
    </label>
    <div class="preview-wrap">
      <span class="label">Preview, as students see it</span>
      <article class="lesson-preview" id="lesson-preview" aria-live="polite"></article>
    </div>
  </div>
  <div class="actions">
    <a class="btn ghost" href="world.php?id=<?= $worldId ?>">Cancel</a>
    <button class="btn primary" type="submit"><?= $l ? 'Save level' : 'Create level' ?></button>
  </div>
</form>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
