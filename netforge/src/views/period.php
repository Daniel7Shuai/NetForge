<?php
/** Period picker + export buttons shared by the analytics pages. @var array $period  @var array $keep extra query params */
$keep ??= [];
$q = fn (array $extra) => http_build_query($keep + $period + $extra);
$preset = fn (int $days) => http_build_query($keep + ['from' => date('Y-m-d', strtotime('-' . ($days - 1) . ' days')), 'to' => date('Y-m-d')]);
?>
<form method="get" class="filters period">
  <?php foreach ($keep as $k => $v): ?><input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
  <label>From <input type="date" name="from" value="<?= e($period['from']) ?>"></label>
  <label>To <input type="date" name="to" value="<?= e($period['to']) ?>"></label>
  <button class="btn" type="submit">Show</button>
  <span class="presets">
    <a href="?<?= $preset(7) ?>">Last 7 days</a>
    <a href="?<?= $preset(30) ?>">Last 30 days</a>
    <a href="?<?= $preset(90) ?>">Last 90 days</a>
  </span>
  <span class="exports">
    <a class="btn sm" href="?<?= $q(['format' => 'csv']) ?>"><i class="ti ti-file-spreadsheet" aria-hidden="true"></i>CSV</a>
    <a class="btn sm" href="?<?= $q(['format' => 'pdf']) ?>"><i class="ti ti-file-type-pdf" aria-hidden="true"></i>PDF</a>
  </span>
</form>
