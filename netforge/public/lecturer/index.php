<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Content\Staff;
use NetForge\Db;

// Content overview: every topic with its levels, question count and how students are doing.
$me = Staff::begin();

$worlds = Db::all(
    'SELECT w.*,
            (SELECT COUNT(*) FROM levels l WHERE l.world_id = w.id AND l.archived = 0) levels,
            (SELECT COUNT(*) FROM questions q WHERE q.world_id = w.id AND q.archived = 0) questions,
            (SELECT COUNT(*) FROM attempts a JOIN questions q ON q.id = a.question_id WHERE q.world_id = w.id) answers,
            (SELECT AVG(a.correct) FROM attempts a JOIN questions q ON q.id = a.question_id WHERE q.world_id = w.id) accuracy,
            (SELECT COUNT(*) FROM world_progress p WHERE p.world_id = w.id AND p.mastered_at IS NOT NULL) mastered
       FROM worlds w ORDER BY w.number'
);
$totals = Db::one(
    'SELECT (SELECT COUNT(*) FROM questions WHERE archived = 0) questions,
            (SELECT COUNT(*) FROM users WHERE role = "student") students'
);

$title = 'Content';
$active = 'index';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<div class="page-head">
  <div>
    <h1>Topics and levels</h1>
    <p class="lede">Topics follow the CCNA Introduction to Networks modules. Open one to edit its lessons, exam and level order.</p>
  </div>
  <p class="stat mono"><?= (int) $totals['questions'] ?> questions · <?= (int) $totals['students'] ?> students</p>
</div>

<table class="data">
  <thead><tr>
    <th scope="col">Topic</th><th scope="col">CCNA modules</th><th scope="col" class="num">Levels</th>
    <th scope="col" class="num">Questions</th><th scope="col" class="num">Answers</th><th scope="col" class="num">Accuracy</th>
    <th scope="col" class="num">Mastered by</th><th scope="col"><span class="sr-only">Actions</span></th>
  </tr></thead>
  <tbody>
  <?php foreach ($worlds as $w): ?>
    <tr<?= $w['archived'] ? ' class="dim"' : '' ?>>
      <th scope="row"><span class="mono tag">T<?= (int) $w['number'] ?></span> <?= e($w['title']) ?><?= $w['archived'] ? ' <small>(archived)</small>' : '' ?></th>
      <td class="mono"><?= e($w['ccna_modules']) ?></td>
      <td class="num mono"><?= (int) $w['levels'] ?></td>
      <td class="num mono"><?= (int) $w['questions'] ?></td>
      <td class="num mono"><?= (int) $w['answers'] ?></td>
      <td class="num mono"><?= $w['accuracy'] === null ? '–' : round($w['accuracy'] * 100) . '%' ?></td>
      <td class="num mono"><?= (int) $w['mastered'] ?></td>
      <td class="act"><a class="btn sm" href="world.php?id=<?= (int) $w['id'] ?>">Edit</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
