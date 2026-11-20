<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Content\QuestionInput;
use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\Db;
use NetForge\Learning\Elo;

// FR-2.2: the question bank, with each question's live Elo rating and how often students get it right.
$me = Staff::begin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
    $arch = ($_POST['archived'] ?? '1') === '1' ? 1 : 0;
    Db::exec('UPDATE questions SET archived = ? WHERE id = ?', [$arch, (int) ($_POST['id'] ?? 0)]);
    Staff::flash('ok', $arch ? 'Question archived. It won’t be served any more.' : 'Question restored.');
    Staff::back();
}

$f = [
    'world' => (int) ($_GET['world'] ?? 0),
    'level' => (int) ($_GET['level'] ?? 0),
    'type'  => in_array($_GET['type'] ?? '', QuestionInput::TYPES, true) ? $_GET['type'] : '',
    'q'     => trim((string) ($_GET['q'] ?? '')),
    'archived' => isset($_GET['archived']),
];
if ($f['level']) {
    $f['world'] = (int) (Db::one('SELECT world_id FROM levels WHERE id = ?', [$f['level']])['world_id'] ?? 0);
}

$where = ['1 = 1'];
$args = [];
if ($f['world']) { $where[] = 'q.world_id = ?'; $args[] = $f['world']; }
if ($f['level']) { $where[] = 'q.level_id = ?'; $args[] = $f['level']; }
if ($f['type']) { $where[] = 'q.type = ?'; $args[] = $f['type']; }
if ($f['q'] !== '') { $where[] = '(q.prompt LIKE ? OR q.topic LIKE ?)'; $args[] = '%' . $f['q'] . '%'; $args[] = '%' . $f['q'] . '%'; }
if (!$f['archived']) { $where[] = 'q.archived = 0'; }

$rows = Db::all(
    'SELECT q.id, q.type, q.prompt, q.topic, q.rating, q.archived, w.number wnum, l.title ltitle, l.sort_order lpos,
            COUNT(a.id) answers, AVG(a.correct) accuracy
       FROM questions q JOIN worlds w ON w.id = q.world_id LEFT JOIN levels l ON l.id = q.level_id
       LEFT JOIN attempts a ON a.question_id = q.id
      WHERE ' . implode(' AND ', $where) . '
      GROUP BY q.id ORDER BY w.number, l.sort_order, q.id LIMIT 500',
    $args
);
$worlds = Staff::worldOptions();
$levels = Db::all('SELECT l.id, l.title, l.sort_order, w.number FROM levels l JOIN worlds w ON w.id = l.world_id ORDER BY w.number, l.sort_order');

$title = 'Question bank';
$active = 'questions';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<div class="page-head">
  <div><h1>Question bank</h1><p class="lede">Ratings move as students answer. Compare a question’s rating with its accuracy to spot ones that are mis-rated or confusing.</p></div>
  <a class="btn primary" href="question.php?world=<?= $f['world'] ?>&amp;level=<?= $f['level'] ?>"><i class="ti ti-plus" aria-hidden="true"></i>Add question</a>
</div>

<form method="get" class="filters">
  <label>Topic <select name="world"><option value="0">All topics</option>
    <?php foreach ($worlds as $wid => $wl): ?><option value="<?= $wid ?>" <?= $f['world'] === $wid ? 'selected' : '' ?>><?= e($wl) ?></option><?php endforeach; ?></select></label>
  <label>Level <select name="level"><option value="0">All levels</option>
    <?php foreach ($levels as $l): ?><option value="<?= (int) $l['id'] ?>" <?= $f['level'] === (int) $l['id'] ? 'selected' : '' ?>>T<?= (int) $l['number'] ?>.<?= (int) $l['sort_order'] ?> <?= e($l['title']) ?></option><?php endforeach; ?></select></label>
  <label>Type <select name="type"><option value="">All types</option>
    <?php foreach (QuestionInput::TYPES as $t): ?><option <?= $f['type'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
  <label class="grow">Search <input name="q" value="<?= e($f['q']) ?>" placeholder="Prompt or subtopic"></label>
  <label class="check"><input type="checkbox" name="archived" <?= $f['archived'] ? 'checked' : '' ?>> Show archived</label>
  <button class="btn" type="submit">Filter</button>
</form>

<p class="count mono"><?= count($rows) ?> question<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) === 500 ? ' (first 500)' : '' ?></p>
<?php if (!$rows): ?>
  <p class="empty">No questions match. Add one, or import a CSV.</p>
<?php else: ?>
<table class="data">
  <thead><tr><th scope="col" class="num">ID</th><th scope="col">Where</th><th scope="col">Type</th><th scope="col">Prompt</th><th scope="col">Subtopic</th>
    <th scope="col" class="num">Rating</th><th scope="col" class="num">Answers</th><th scope="col" class="num">Correct</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $acc = $r['accuracy'] === null ? null : (int) round($r['accuracy'] * 100); ?>
    <tr<?= $r['archived'] ? ' class="dim"' : '' ?>>
      <td class="num mono"><?= (int) $r['id'] ?></td>
      <td class="mono nowrap">T<?= (int) $r['wnum'] ?><?= $r['lpos'] !== null ? '.' . (int) $r['lpos'] : ' exam' ?></td>
      <td><span class="type"><?= e($r['type']) ?></span></td>
      <th scope="row" class="prompt"><?= e(mb_strimwidth($r['prompt'], 0, 90, '…')) ?></th>
      <td><?= e($r['topic']) ?></td>
      <td class="num mono" title="<?= Elo::tier((int) $r['rating']) ?>"><?= (int) $r['rating'] ?></td>
      <td class="num mono"><?= (int) $r['answers'] ?></td>
      <td class="num mono<?= $acc !== null && (int) $r['answers'] >= 5 && $acc < 40 ? ' warn' : '' ?>"><?= $acc === null ? '–' : $acc . '%' ?></td>
      <td class="act">
        <a class="btn sm" href="question.php?id=<?= (int) $r['id'] ?>">Edit</a>
        <form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn sm ghost" name="archived" value="<?= $r['archived'] ? '0' : '1' ?>"><?= $r['archived'] ? 'Restore' : 'Archive' ?></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="help">Correct is shown in pink when fewer than 40% of at least 5 answers are right.</p>
<?php endif; ?>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
