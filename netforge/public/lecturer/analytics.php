<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Analytics\Export;
use NetForge\Analytics\Reports;
use NetForge\Content\Staff;

// FR-7.1 class dashboard, FR-7.2 at-risk alerts, FR-7.4 class progress report, FR-7.5 export.
$me = Staff::begin();
$period = Reports::period($_GET);
$ov = Reports::overview($period);
$worlds = Reports::byWorld($period);
$topics = Reports::byTopic($period);
$risk = Reports::atRisk($period);
$label = date('j M Y', strtotime($period['from'])) . ' to ' . date('j M Y', strtotime($period['to']));

if (in_array($_GET['format'] ?? '', ['csv', 'pdf'], true)) {
    Export::send($_GET['format'], 'netforge-class-report', 'Class progress report', $label, [
        ['title' => 'Summary', 'columns' => ['Measure', 'Value'], 'widths' => [3, 1], 'right' => [false, true], 'rows' => [
            ['Students', $ov['students']], ['Active in period', $ov['active']], ['Answers', $ov['answers']],
            ['Accuracy', Export::pct($ov['accuracy'])], ['Topics mastered in period', $ov['mastered']],
        ]],
        ['title' => 'Accuracy by topic', 'columns' => ['Topic', 'Title', 'Answers', 'Students', 'Accuracy'],
         'widths' => [0.7, 4, 1, 1, 1], 'right' => [false, false, true, true, true],
         'rows' => array_map(fn ($w) => ['T' . $w['number'], $w['title'], $w['answers'], $w['students'], Export::pct($w['accuracy'])], $worlds)],
        ['title' => 'Accuracy by subtopic (weakest first)', 'columns' => ['Subtopic', 'Topics', 'Answers', 'Students', 'Accuracy'],
         'widths' => [3, 1.2, 1, 1, 1], 'right' => [false, false, true, true, true],
         'rows' => array_map(fn ($t) => [$t['topic'], $t['worlds'], $t['answers'], $t['students'], $t['accuracy'] . '%'], $topics)],
        ['title' => 'At-risk students', 'columns' => ['Student', 'Last answer', 'Reasons'], 'widths' => [1.2, 1.3, 5],
         'rows' => array_map(fn ($r) => [$r['username'], $r['last'] ? date('j M Y', strtotime($r['last'])) : 'Never', implode(' ', $r['reasons'])], $risk)],
    ]);
}

$bar = fn (?int $pct, string $what) => $pct === null ? '<span class="muted">–</span>'
    : sprintf('<span class="meter" title="%s: %d%% correct"><span class="track"><span class="fill%s" style="width:%d%%"></span></span><span class="val mono">%d%%</span></span>',
        e($what), $pct, $pct < Reports::RISK_LOW_ACCURACY ? ' low' : '', max(2, $pct), $pct);

$title = 'Analytics';
$active = 'analytics';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<div class="page-head">
  <div><h1>Class analytics</h1><p class="lede">How the class is doing in <?= e($label) ?>. Every figure comes straight from students’ recorded answers.</p></div>
  <div class="head-links">
    <a class="btn" href="difficulty.php"><i class="ti ti-scale" aria-hidden="true"></i>Question difficulty</a>
    <a class="btn" href="students.php?<?= http_build_query($period) ?>"><i class="ti ti-users" aria-hidden="true"></i>Students</a>
  </div>
</div>
<?php require __DIR__ . '/../../src/views/period.php'; ?>

<div class="tiles">
  <div class="tile"><span class="k">Active students</span><span class="v mono"><?= $ov['active'] ?><small>/<?= $ov['students'] ?></small></span></div>
  <div class="tile"><span class="k">Answers</span><span class="v mono"><?= number_format($ov['answers']) ?></span></div>
  <div class="tile"><span class="k">Accuracy</span><span class="v mono"><?= $ov['accuracy'] === null ? '–' : $ov['accuracy'] . '%' ?></span></div>
  <div class="tile"><span class="k">Topics mastered</span><span class="v mono"><?= $ov['mastered'] ?></span></div>
</div>

<section class="panel risk">
  <h2><i class="ti ti-alert-triangle" aria-hidden="true"></i>Students who may need help <span class="mono count-pill"><?= count($risk) ?></span></h2>
  <?php if (!$risk): ?>
    <p class="empty">No one is flagged for this period.</p>
  <?php else: ?>
    <ul class="risk-list">
    <?php foreach ($risk as $r): ?>
      <li>
        <a class="who-link" href="student.php?id=<?= $r['id'] ?>&amp;<?= http_build_query($period) ?>"><?= e($r['username']) ?></a>
        <span class="muted mono"><?= $r['last'] ? 'last answer ' . date('j M', strtotime($r['last'])) : 'no answers yet' ?></span>
        <ul class="reasons"><?php foreach ($r['reasons'] as $why): ?><li><?= e($why) ?></li><?php endforeach; ?></ul>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <p class="help">Flagged when accuracy is under <?= Reports::RISK_LOW_ACCURACY ?>% over at least <?= Reports::RISK_MIN_ANSWERS ?> answers, after <?= Reports::RISK_INACTIVE_DAYS ?> days without answering, when a topic rating falls <?= Reports::RISK_RATING_DROP ?>+ points, or with <?= Reports::RISK_AUDIT_BACKLOG ?>+ overdue reviews.</p>
</section>

<div class="two-col">
  <section class="panel">
    <h2>Accuracy by topic</h2>
    <table class="data meters">
      <thead><tr><th scope="col">Topic</th><th scope="col" class="num">Answers</th><th scope="col" class="num">Students</th><th scope="col">Accuracy</th></tr></thead>
      <tbody>
      <?php foreach ($worlds as $w): ?>
        <tr<?= $w['answers'] ? '' : ' class="dim"' ?>>
          <th scope="row"><span class="mono tag">T<?= $w['number'] ?></span> <?= e($w['title']) ?></th>
          <td class="num mono"><?= $w['answers'] ?></td><td class="num mono"><?= $w['students'] ?></td>
          <td><?= $bar($w['accuracy'], 'T' . $w['number']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="panel">
    <h2>Weakest subtopics</h2>
    <?php if (!$topics): ?>
      <p class="empty">No answers in this period.</p>
    <?php else: ?>
    <table class="data meters">
      <thead><tr><th scope="col">Subtopic</th><th scope="col" class="num">Answers</th><th scope="col">Accuracy</th></tr></thead>
      <tbody>
      <?php foreach ($topics as $t): ?>
        <tr><th scope="row"><?= e($t['topic']) ?> <small class="mono"><?= e($t['worlds']) ?></small></th>
          <td class="num mono"><?= $t['answers'] ?></td><td><?= $bar($t['accuracy'], $t['topic']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
</div>
<p class="help">Bars below <?= Reports::RISK_LOW_ACCURACY ?>% are pink. Hover a bar for its exact value.</p>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
