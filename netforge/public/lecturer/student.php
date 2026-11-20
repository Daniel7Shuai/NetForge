<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Analytics\Export;
use NetForge\Analytics\Reports;
use NetForge\Content\Staff;

// FR-7.4: one student's progress report for a chosen period, exportable (FR-7.5).
$me = Staff::begin();
$id = (int) ($_GET['id'] ?? 0);
$period = Reports::period($_GET);
$r = Reports::student($id, $period);
if ($r === null) {
    Staff::flash('error', 'That student doesn’t exist.');
    Staff::redirect('students.php');
}
$u = $r['user'];
$label = date('j M Y', strtotime($period['from'])) . ' to ' . date('j M Y', strtotime($period['to']));
$modeName = ['practice' => 'Lessons', 'exam' => 'Exams', 'audit' => 'Daily Security Audit', 'mock' => 'Mock exams'];

if (in_array($_GET['format'] ?? '', ['csv', 'pdf'], true)) {
    Export::send($_GET['format'], 'netforge-student-' . $u['username'], 'Progress report: ' . $u['username'], $label, [
        ['title' => 'Topics', 'columns' => ['Topic', 'Title', 'Rating', 'Tier', 'Exam best', 'Mastered', 'Answers', 'Accuracy'],
         'widths' => [0.6, 3.2, 0.8, 0.9, 0.9, 1.2, 0.9, 0.9], 'right' => [false, false, true, false, true, false, true, true],
         'rows' => array_map(fn ($w) => ['T' . $w['number'], $w['title'], $w['rating'], $w['tier'], Export::pct($w['exam_best']), $w['mastered'] ? date('j M Y', strtotime($w['mastered'])) : '', $w['answers'], Export::pct($w['accuracy'])], $r['worlds'])],
        ['title' => 'Subtopics (weakest first)', 'columns' => ['Subtopic', 'Answers', 'Accuracy'], 'widths' => [4, 1, 1], 'right' => [false, true, true],
         'rows' => array_map(fn ($t) => [$t['topic'], $t['answers'], $t['accuracy'] . '%'], $r['topics'])],
        ['title' => 'Activity by day', 'columns' => ['Day', 'Answers', 'Correct'], 'widths' => [2, 1, 1], 'right' => [false, true, true],
         'rows' => array_map(fn ($d) => [date('D j M Y', strtotime($d['day'])), $d['answers'], $d['correct']], $r['days'])],
    ], $r['reasons'] ? ['At risk: ' . implode(' ', $r['reasons'])] : []);
}

$maxDay = max([1, ...array_column($r['days'], 'answers')]);

$title = $u['username'];
$active = 'analytics';
$keep = ['id' => $id];
require __DIR__ . '/../../src/views/staff_top.php';
?>
<p class="crumbs"><a href="analytics.php?<?= http_build_query($period) ?>">Analytics</a> / <a href="students.php?<?= http_build_query($period) ?>">Students</a> / <?= e($u['username']) ?></p>
<div class="page-head">
  <div><h1><?= e($u['username']) ?></h1>
  <p class="lede">Level <?= (int) $u['level'] ?>, <?= number_format((int) $u['xp']) ?> XP, joined <?= date('j M Y', strtotime($u['created_at'])) ?>. Answers and accuracy cover <?= e($label) ?>.</p></div>
</div>
<?php require __DIR__ . '/../../src/views/period.php'; ?>

<?php if ($r['reasons']): ?>
  <div class="flash error" role="status"><i class="ti ti-alert-triangle" aria-hidden="true"></i><div><strong>May need help</strong><ul><?php foreach ($r['reasons'] as $why): ?><li><?= e($why) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div class="tiles">
  <?php foreach ($r['modes'] as $m): ?>
    <div class="tile"><span class="k"><?= e($modeName[$m['mode']] ?? $m['mode']) ?></span>
      <span class="v mono"><?= (int) $m['answers'] ?><small> answers, <?= round($m['accuracy'] * 100) ?>%</small></span></div>
  <?php endforeach; ?>
  <?php if (!$r['modes']): ?><p class="empty">No answers in this period.</p><?php endif; ?>
</div>

<div class="two-col">
  <section class="panel">
    <h2>Topics</h2>
    <table class="data">
      <thead><tr><th scope="col">Topic</th><th scope="col" class="num">Rating</th><th scope="col" class="num">Exam best</th><th scope="col" class="num">Answers</th><th scope="col" class="num">Accuracy</th></tr></thead>
      <tbody>
      <?php foreach ($r['worlds'] as $w): if ($w['rating'] === null && !$w['answers']) continue; ?>
        <tr><th scope="row"><span class="mono tag">T<?= $w['number'] ?></span> <?= e($w['title']) ?><?= $w['mastered'] ? ' <span class="verdict ok"><i class="ti ti-circle-check" aria-hidden="true"></i>Mastered</span>' : '' ?></th>
          <td class="num mono"><?= $w['rating'] ?? '–' ?><small><?= e($w['tier'] ?? '') ?></small></td>
          <td class="num mono"><?= Export::pct($w['exam_best']) ?></td>
          <td class="num mono"><?= $w['answers'] ?></td>
          <td class="num mono"><?= Export::pct($w['accuracy']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <section class="panel">
    <h2>Subtopics, weakest first</h2>
    <?php if (!$r['topics']): ?><p class="empty">No answers in this period.</p><?php else: ?>
    <table class="data meters">
      <thead><tr><th scope="col">Subtopic</th><th scope="col" class="num">Answers</th><th scope="col">Accuracy</th></tr></thead>
      <tbody>
      <?php foreach ($r['topics'] as $t): ?>
        <tr><th scope="row"><?= e($t['topic']) ?></th><td class="num mono"><?= $t['answers'] ?></td>
          <td><span class="meter" title="<?= e($t['topic']) ?>: <?= $t['accuracy'] ?>% correct"><span class="track"><span class="fill<?= $t['accuracy'] < Reports::RISK_LOW_ACCURACY ? ' low' : '' ?>" style="width:<?= max(2, $t['accuracy']) ?>%"></span></span><span class="val mono"><?= $t['accuracy'] ?>%</span></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
</div>

<section class="panel">
  <h2>Activity by day</h2>
  <?php if (!$r['days']): ?><p class="empty">No answers in this period.</p><?php else: ?>
  <table class="data meters">
    <thead><tr><th scope="col">Day</th><th scope="col">Answers</th><th scope="col" class="num">Correct</th></tr></thead>
    <tbody>
    <?php foreach ($r['days'] as $d): ?>
      <tr><th scope="row" class="mono"><?= date('D j M', strtotime($d['day'])) ?></th>
        <td><span class="meter" title="<?= $d['answers'] ?> answers"><span class="track"><span class="fill" style="width:<?= round($d['answers'] / $maxDay * 100) ?>%"></span></span><span class="val mono"><?= $d['answers'] ?></span></span></td>
        <td class="num mono"><?= $d['correct'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
