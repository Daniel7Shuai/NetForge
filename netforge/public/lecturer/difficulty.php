<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Analytics\Export;
use NetForge\Analytics\Reports;
use NetForge\Content\Staff;

// FR-7.3: does each question behave as its Elo rating predicts?
$me = Staff::begin();
$worldId = (int) ($_GET['world'] ?? 0);
$min = Staff::int($_GET['min'] ?? '', 1, 1000, 1);
$rows = Reports::difficulty($worldId ?: null, $min);
$worlds = Staff::worldOptions();
$scope = $worldId ? $worlds[$worldId] ?? 'Unknown world' : 'All worlds';

if (in_array($_GET['format'] ?? '', ['csv', 'pdf'], true)) {
    Export::send($_GET['format'], 'netforge-question-difficulty', 'Question difficulty report', $scope . ', questions with at least ' . $min . ' answer(s)', [[
        'title' => 'Observed against expected % correct',
        'columns' => ['ID', 'World', 'Prompt', 'Rating', 'Answers', 'Expected', 'Observed', 'Gap', 'Verdict'],
        'widths' => [0.5, 0.6, 5, 0.8, 0.8, 0.9, 0.9, 0.7, 1.7], 'right' => [true, false, false, true, true, true, true, true, false],
        'rows' => array_map(fn ($r) => [$r['id'], $r['world'], $r['prompt'], $r['rating'], $r['answers'], $r['expected'] . '%', $r['observed'] . '%', sprintf('%+d', $r['gap']), $r['verdict']], $rows),
    ]], ['Expected is the average chance of a correct answer that the Elo model gave each student, from their rating when they answered and the question’s current rating. A gap of 15 points or more, with at least 5 answers, is flagged.']);
}

$verdictIcon = ['Harder than rated' => ['ti-trending-down', 'bad'], 'Easier than rated' => ['ti-trending-up', 'warn'], 'As expected' => ['ti-check', 'ok'], 'Too few answers' => ['ti-hourglass', 'muted']];
$counts = array_count_values(array_column($rows, 'verdict'));

$title = 'Question difficulty';
$active = 'analytics';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<p class="crumbs"><a href="analytics.php">Analytics</a> / Question difficulty</p>
<div class="page-head">
  <div><h1>Question difficulty report</h1>
  <p class="lede">For each question, the share of students the Elo model expected to answer correctly against the share who did. Big gaps mean the rating, or the question itself, needs a look.</p></div>
</div>

<form method="get" class="filters">
  <label>World <select name="world"><option value="0">All worlds</option>
    <?php foreach ($worlds as $wid => $wl): ?><option value="<?= $wid ?>" <?= $worldId === $wid ? 'selected' : '' ?>><?= e($wl) ?></option><?php endforeach; ?></select></label>
  <label>At least this many answers <input type="number" name="min" min="1" max="1000" value="<?= $min ?>"></label>
  <button class="btn" type="submit">Show</button>
  <span class="exports">
    <a class="btn sm" href="?<?= http_build_query(['world' => $worldId, 'min' => $min, 'format' => 'csv']) ?>"><i class="ti ti-file-spreadsheet" aria-hidden="true"></i>CSV</a>
    <a class="btn sm" href="?<?= http_build_query(['world' => $worldId, 'min' => $min, 'format' => 'pdf']) ?>"><i class="ti ti-file-type-pdf" aria-hidden="true"></i>PDF</a>
  </span>
</form>

<p class="count mono">
  <?php foreach ($verdictIcon as $v => [$icon, $cls]): ?><span class="vchip <?= $cls ?>"><i class="ti <?= $icon ?>" aria-hidden="true"></i><?= e($v) ?> <b><?= $counts[$v] ?? 0 ?></b></span><?php endforeach; ?>
</p>

<?php if (!$rows): ?>
  <p class="empty">No questions have been answered enough yet.</p>
<?php else: ?>
<table class="data">
  <thead><tr><th scope="col" class="num">ID</th><th scope="col">Where</th><th scope="col">Prompt</th><th scope="col" class="num">Rating</th><th scope="col" class="num">Answers</th>
    <th scope="col" class="num">Expected</th><th scope="col" class="num">Observed</th><th scope="col" class="num">Gap</th><th scope="col">Verdict</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): [$icon, $cls] = $verdictIcon[$r['verdict']]; ?>
    <tr>
      <td class="num mono"><a href="question.php?id=<?= $r['id'] ?>"><?= $r['id'] ?></a></td>
      <td class="mono"><?= e($r['world']) ?></td>
      <th scope="row" class="prompt"><?= e(mb_strimwidth($r['prompt'], 0, 80, '…')) ?><small><?= e($r['topic']) ?> · <?= e($r['type']) ?></small></th>
      <td class="num mono" title="<?= e($r['tier']) ?>"><?= $r['rating'] ?></td>
      <td class="num mono"><?= $r['answers'] ?></td>
      <td class="num mono"><?= $r['expected'] ?>%</td>
      <td class="num mono"><?= $r['observed'] ?>%</td>
      <td class="num mono"><?= sprintf('%+d', $r['gap']) ?></td>
      <td><span class="verdict <?= $cls ?>"><i class="ti <?= $icon ?>" aria-hidden="true"></i><?= e($r['verdict']) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="help">Expected uses each student’s rating at the time they answered and the question’s current rating. Ratings adjust themselves over time; a question that stays “harder than rated” may be worded confusingly.</p>
<?php endif; ?>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
