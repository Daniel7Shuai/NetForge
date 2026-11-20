<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Content\QuestionInput;
use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\Db;

// FR-2.2: add or edit one question of any of the six types.
$me = Staff::begin();
$id = (int) ($_GET['id'] ?? 0);
$q = $id ? Db::one('SELECT * FROM questions WHERE id = ?', [$id]) : null;
if ($id && $q === null) {
    Staff::flash('error', 'That question doesn’t exist.');
    Staff::redirect('questions.php');
}

$form = $q ? QuestionInput::format($q) + ['world' => (int) $q['world_id'], 'level' => (int) $q['level_id']]
    : ['type' => 'mcq', 'prompt' => '', 'topic' => '', 'rating' => '1000', 'explanation' => '', 'choices' => '', 'answer' => '',
       'world' => (int) ($_GET['world'] ?? 0), 'level' => (int) ($_GET['level'] ?? 0)];
if (!$form['world'] && $form['level']) {
    $form['world'] = (int) (Db::one('SELECT world_id FROM levels WHERE id = ?', [$form['level']])['world_id'] ?? 0);
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_map(fn ($v) => is_string($v) ? str_replace("\r\n", "\n", $v) : $v, [
        'type' => $_POST['type'] ?? '', 'prompt' => $_POST['prompt'] ?? '', 'topic' => $_POST['topic'] ?? '',
        'rating' => $_POST['rating'] ?? '', 'explanation' => $_POST['explanation'] ?? '', 'choices' => $_POST['choices'] ?? '',
        'answer' => $_POST['answer'] ?? '', 'world' => (int) ($_POST['world'] ?? 0), 'level' => (int) ($_POST['level'] ?? 0),
    ]);
    $parsed = QuestionInput::parse($form);
    $errors = $parsed['errors'];
    if (!Db::one('SELECT id FROM worlds WHERE id = ?', [$form['world']])) {
        $errors[] = 'Pick the topic this question belongs to.';
    }
    if ($form['level'] && !Db::one('SELECT id FROM levels WHERE id = ? AND world_id = ?', [$form['level'], $form['world']])) {
        $errors[] = 'That level isn’t in the chosen topic.';
    }
    if (!$errors) {
        $r = $parsed['row'];
        $vals = [$form['world'], $form['level'] ?: null, $r['type'], $r['prompt'], json_encode($r['payload'], JSON_UNESCAPED_UNICODE),
            $r['answer'] === null ? null : json_encode($r['answer'], JSON_UNESCAPED_UNICODE), $r['explanation'], $r['topic']];
        if ($q) {
            // keep the live rating unless the lecturer changed it on purpose
            $rating = (int) $form['rating'] === (int) $q['rating'] ? (int) $q['rating'] : $r['rating'];
            Db::exec('UPDATE questions SET world_id = ?, level_id = ?, type = ?, prompt = ?, payload = ?, answer = ?, explanation = ?, topic = ?, rating = ? WHERE id = ?',
                [...$vals, $rating, $id]);
            Staff::flash('ok', 'Question saved.');
        } else {
            Db::exec('INSERT INTO questions (world_id, level_id, type, prompt, payload, answer, explanation, topic, rating) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$vals, $r['rating']]);
            Staff::flash('ok', 'Question added.');
        }
        Staff::redirect('questions.php?world=' . $form['world'] . ($form['level'] ? '&level=' . $form['level'] : ''));
    }
}

$worlds = Staff::worldOptions();
$levels = Db::all('SELECT l.id, l.world_id, l.title, l.sort_order, w.number FROM levels l JOIN worlds w ON w.id = l.world_id WHERE l.archived = 0 ORDER BY w.number, l.sort_order');
$stats = $q ? Db::one('SELECT COUNT(*) n, AVG(correct) acc FROM attempts WHERE question_id = ?', [$id]) : null;

$title = $q ? 'Question ' . $id : 'New question';
$active = 'questions';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<p class="crumbs"><a href="questions.php">Question bank</a> / <?= $q ? 'Question ' . $id : 'New question' ?></p>
<div class="page-head">
  <h1><?= $q ? 'Edit question ' . $id : 'New question' ?></h1>
  <?php if ($stats && $stats['n']): ?><p class="stat mono"><?= (int) $stats['n'] ?> answers · <?= round($stats['acc'] * 100) ?>% correct</p><?php endif; ?>
</div>

<?php if ($errors): ?>
  <div class="flash error" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="form" id="qform">
  <?= Csrf::field() ?>
  <div class="grid3">
    <label>Topic <select name="world" id="world" required><option value="">Choose…</option>
      <?php foreach ($worlds as $wid => $wl): ?><option value="<?= $wid ?>" <?= $form['world'] === $wid ? 'selected' : '' ?>><?= e($wl) ?></option><?php endforeach; ?></select></label>
    <label>Level <select name="level" id="level"><option value="0">None: exam pool only</option>
      <?php foreach ($levels as $l): ?><option value="<?= (int) $l['id'] ?>" data-world="<?= (int) $l['world_id'] ?>" <?= $form['level'] === (int) $l['id'] ? 'selected' : '' ?>>W<?= (int) $l['number'] ?>.<?= (int) $l['sort_order'] ?> <?= e($l['title']) ?></option><?php endforeach; ?></select></label>
    <label>Type <select name="type" id="qtype">
      <?php foreach (['mcq' => 'Multiple choice', 'matching' => 'Matching', 'ordering' => 'Ordering', 'fill' => 'Fill in the blanks', 'blitz' => 'Binary Blitz (generated)', 'subnet' => 'Subnet calculator (generated)'] as $t => $lab): ?>
        <option value="<?= $t ?>" <?= $form['type'] === $t ? 'selected' : '' ?>><?= $lab ?></option><?php endforeach; ?></select></label>
  </div>
  <label>Prompt <textarea name="prompt" rows="3" required><?= e($form['prompt']) ?></textarea>
    <small id="help-prompt"></small></label>
  <div class="grid2">
    <label>Choices <textarea name="choices" id="choices" rows="6" class="mono"><?= e($form['choices']) ?></textarea>
      <small id="help-choices"></small></label>
    <label id="answer-wrap">Answer <input name="answer" id="answer" value="<?= e($form['answer']) ?>" class="mono">
      <small id="help-answer"></small></label>
  </div>
  <div class="grid3">
    <label>Subtopic tag <input name="topic" value="<?= e($form['topic']) ?>" maxlength="64" required><small>Used for exam and audit breakdowns.</small></label>
    <label>Difficulty rating <input name="rating" type="number" min="400" max="2000" value="<?= e($form['rating']) ?>"><small>Starting Elo: 800 easy, 1000 medium, 1300 hard.</small></label>
  </div>
  <label>Explanation <textarea name="explanation" rows="3" required><?= e($form['explanation']) ?></textarea><small>Shown to students right after they answer.</small></label>
  <div class="actions">
    <a class="btn ghost" href="questions.php">Cancel</a>
    <button class="btn primary" type="submit"><?= $q ? 'Save question' : 'Add question' ?></button>
  </div>
</form>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
