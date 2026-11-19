<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Analytics\Export;
use NetForge\Analytics\Reports;
use NetForge\Content\Staff;

// Every student at a glance for the chosen period; click one for their progress report (FR-7.4).
$me = Staff::begin();
$period = Reports::period($_GET);
$rows = Reports::students($period);
$flagged = array_column(Reports::atRisk($period), 'reasons', 'id');
$label = date('j M Y', strtotime($period['from'])) . ' to ' . date('j M Y', strtotime($period['to']));
$last = fn (?string $d) => $d === null ? 'Never' : date('j M Y', strtotime($d));

if (in_array($_GET['format'] ?? '', ['csv', 'pdf'], true)) {
    Export::send($_GET['format'], 'netforge-students', 'Students', $label, [[
        'title' => 'Activity and progress',
        'columns' => ['Student', 'Level', 'Answers', 'Accuracy', 'Worlds mastered', 'Overdue reviews', 'Last answer', 'At risk'],
        'widths' => [1.6, 0.7, 0.9, 0.9, 1.2, 1.2, 1.3, 0.8], 'right' => [false, true, true, true, true, true, false, false],
        'rows' => array_map(fn ($s) => [$s['username'], $s['level'], $s['answers'], Export::pct($s['accuracy']), $s['mastered'], $s['audit_due'], $last($s['last']), isset($flagged[$s['id']]) ? 'Yes' : ''], $rows),
    ]]);
}

$title = 'Students';
$active = 'analytics';
$keep = [];
require __DIR__ . '/../../src/views/staff_top.php';
?>
<p class="crumbs"><a href="analytics.php?<?= http_build_query($period) ?>">Analytics</a> / Students</p>
<div class="page-head">
  <div><h1>Students</h1><p class="lede">Answers and accuracy cover <?= e($label) ?>. Mastery and reviews are as of today.</p></div>
</div>
<?php require __DIR__ . '/../../src/views/period.php'; ?>

<?php if (!$rows): ?>
  <p class="empty">No students yet.</p>
<?php else: ?>
<table class="data">
  <thead><tr><th scope="col">Student</th><th scope="col" class="num">Level</th><th scope="col" class="num">Answers</th><th scope="col" class="num">Accuracy</th>
    <th scope="col" class="num">Worlds mastered</th><th scope="col" class="num">Overdue reviews</th><th scope="col">Last answer</th><th scope="col">Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $s): ?>
    <tr>
      <th scope="row"><a href="student.php?id=<?= $s['id'] ?>&amp;<?= http_build_query($period) ?>"><?= e($s['username']) ?></a></th>
      <td class="num mono"><?= $s['level'] ?></td>
      <td class="num mono"><?= $s['answers'] ?></td>
      <td class="num mono"><?= Export::pct($s['accuracy']) ?></td>
      <td class="num mono"><?= $s['mastered'] ?></td>
      <td class="num mono"><?= $s['audit_due'] ?></td>
      <td class="mono"><?= $last($s['last']) ?></td>
      <td><?php if (isset($flagged[$s['id']])): ?><span class="verdict bad" title="<?= e(implode(' ', $flagged[$s['id']])) ?>"><i class="ti ti-alert-triangle" aria-hidden="true"></i>At risk</span><?php else: ?><span class="verdict ok"><i class="ti ti-check" aria-hidden="true"></i>On track</span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
