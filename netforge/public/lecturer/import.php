<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Content\QuestionInput;
use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\Db;

// FR-2.3: import questions from CSV. Step 1 checks every row and reports the rejected ones;
// step 2 imports the valid rows. Nothing is saved until the lecturer confirms.
$me = Staff::begin();
const COLUMNS = ['world', 'level', 'type', 'topic', 'rating', 'prompt', 'choices', 'answer', 'explanation'];
const MAX_ROWS = 1000;

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="netforge-questions-template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, COLUMNS);
    fputcsv($out, [0, 1, 'mcq', 'Network components', 800, 'Which of these is an end device?', 'Laptop|Switch|Router|Firewall', '1', 'Laptops are hosts; the others move traffic.']);
    fputcsv($out, [0, 2, 'matching', 'Network types', 1000, 'Match each network to its size.', 'LAN = One building|WAN = Between cities', '', 'LANs are local; WANs span distances.']);
    fputcsv($out, [0, 3, 'ordering', 'IOS modes', 950, 'Order these commands to rename a switch.', 'enable|configure terminal|hostname S1', '', 'Privileged EXEC, then global config.']);
    fputcsv($out, [0, '', 'fill', 'Client-server', 900, 'The host that offers a service is the ___.', '', 'server', 'Servers provide; clients request.']);
    fputcsv($out, [2, 1, 'blitz', 'Binary conversion', 950, 'Binary to decimal, 8 bits', 'mode=bin2dec bits=8 seconds=20', '', 'Add the place values 128 to 1.']);
    fputcsv($out, [5, 1, 'subnet', 'Subnetting', 1050, 'Network address, /24 to /30', 'mode=network min=24 max=30', '', 'Round down to a multiple of the block size.']);
    exit;
}

$report = $_SESSION['import'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? '';
    if ($step === 'cancel') {
        unset($_SESSION['import']);
        Staff::redirect('import.php');
    }
    if ($step === 'commit') {
        $rows = $report['valid'] ?? [];
        if (!$rows) {
            Staff::flash('error', 'There’s nothing to import. Upload a file first.');
            Staff::redirect('import.php');
        }
        Db::transaction(function () use ($rows): void {
            foreach ($rows as $r) {
                Db::exec(
                    'INSERT INTO questions (world_id, level_id, type, prompt, payload, answer, explanation, topic, rating) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$r['world_id'], $r['level_id'], $r['type'], $r['prompt'], json_encode($r['payload'], JSON_UNESCAPED_UNICODE),
                     $r['answer'] === null ? null : json_encode($r['answer'], JSON_UNESCAPED_UNICODE), $r['explanation'], $r['topic'], $r['rating']]
                );
            }
        });
        unset($_SESSION['import']);
        Staff::flash('ok', sprintf('Imported %d question%s.', count($rows), count($rows) === 1 ? '' : 's'));
        Staff::redirect('questions.php');
    }

    // step 1: check the uploaded file
    $file = $_FILES['csv'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        Staff::flash('error', 'Choose a CSV file to upload.');
        Staff::redirect('import.php');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        Staff::flash('error', 'That file is over 2 MB. Split it into smaller files.');
        Staff::redirect('import.php');
    }

    $worldIds = [];
    foreach (Db::all('SELECT id, number FROM worlds') as $w) {
        $worldIds[(int) $w['number']] = (int) $w['id'];
    }
    $levelIds = [];
    foreach (Db::all('SELECT id, world_id, sort_order FROM levels WHERE archived = 0') as $l) {
        $levelIds[(int) $l['world_id']][(int) $l['sort_order']] = (int) $l['id'];
    }

    $fh = fopen($file['tmp_name'], 'r');
    $header = fgetcsv($fh) ?: [];
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // Excel's BOM
    }
    $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
    $missing = array_diff(COLUMNS, $header);
    if ($missing) {
        fclose($fh);
        Staff::flash('error', 'The first row must name the columns. Missing: ' . implode(', ', $missing) . '. Download the template to see the layout.');
        Staff::redirect('import.php');
    }

    $valid = [];
    $rejected = [];
    $line = 1;
    while (($cells = fgetcsv($fh)) !== false) {
        $line++;
        if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
            continue; // blank line
        }
        if (count($valid) + count($rejected) >= MAX_ROWS) {
            $rejected[] = ['line' => $line, 'prompt' => '', 'errors' => ['Only the first ' . MAX_ROWS . ' rows are checked. Split the file.']];
            break;
        }
        $f = [];
        foreach (COLUMNS as $col) {
            $f[$col] = (string) ($cells[array_search($col, $header, true)] ?? '');
        }
        $parsed = QuestionInput::parse($f);
        $errors = $parsed['errors'];
        $wn = trim($f['world']);
        $worldId = ctype_digit($wn) ? ($worldIds[(int) $wn] ?? null) : null;
        if ($worldId === null) {
            array_unshift($errors, "World “{$f['world']}” doesn’t exist. Use the world number, 0 to 8.");
        }
        $levelId = null;
        $ln = trim($f['level']);
        if ($ln !== '' && $worldId !== null) {
            $levelId = ctype_digit($ln) ? ($levelIds[$worldId][(int) $ln] ?? null) : null;
            if ($levelId === null) {
                $errors[] = "World $wn has no level $ln. Leave level empty for exam-only questions.";
            }
        }
        if ($errors) {
            $rejected[] = ['line' => $line, 'prompt' => mb_strimwidth($f['prompt'], 0, 60, '…'), 'errors' => $errors];
        } else {
            $valid[] = $parsed['row'] + ['world_id' => $worldId, 'level_id' => $levelId, 'line' => $line];
        }
    }
    fclose($fh);
    $_SESSION['import'] = ['file' => $file['name'], 'valid' => $valid, 'rejected' => $rejected];
    Staff::redirect('import.php');
}

$title = 'CSV import';
$active = 'import';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<div class="page-head">
  <div><h1>Import questions from CSV</h1>
  <p class="lede">Every row is checked first. You’ll see which rows would be rejected and why, then choose whether to import the rest.</p></div>
  <a class="btn" href="import.php?template=1"><i class="ti ti-download" aria-hidden="true"></i>Download template</a>
</div>

<?php if ($report): ?>
  <section class="panel">
    <h2>Check results for <?= e($report['file']) ?></h2>
    <p class="result-line"><span class="okc mono"><?= count($report['valid']) ?></span> ready to import
      <span class="sep">·</span> <span class="badc mono"><?= count($report['rejected']) ?></span> rejected</p>
    <?php if ($report['rejected']): ?>
      <table class="data">
        <thead><tr><th scope="col" class="num">Line</th><th scope="col">Prompt</th><th scope="col">Why it was rejected</th></tr></thead>
        <tbody>
        <?php foreach ($report['rejected'] as $r): ?>
          <tr><td class="num mono"><?= (int) $r['line'] ?></td><td><?= e($r['prompt']) ?></td>
            <td><ul class="errs"><?php foreach ($r['errors'] as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="help">Fix these rows in your spreadsheet and upload them again later. Importing now adds only the valid rows.</p>
    <?php endif; ?>
    <form method="post" class="actions">
      <?= Csrf::field() ?>
      <button class="btn ghost" name="step" value="cancel">Start over</button>
      <button class="btn primary" name="step" value="commit" <?= $report['valid'] ? '' : 'disabled' ?>>Import <?= count($report['valid']) ?> question<?= count($report['valid']) === 1 ? '' : 's' ?></button>
    </form>
  </section>
<?php else: ?>
  <section class="panel">
    <form method="post" enctype="multipart/form-data" class="form">
      <?= Csrf::field() ?><input type="hidden" name="step" value="check">
      <label>CSV file <input type="file" name="csv" accept=".csv,text/csv" required></label>
      <div class="actions"><button class="btn primary" type="submit">Check file</button></div>
    </form>
  </section>
<?php endif; ?>

<section class="panel">
  <h2>Columns</h2>
  <table class="data spec">
    <tbody>
      <tr><th scope="row" class="mono">world</th><td>World number, 0 to 8.</td></tr>
      <tr><th scope="row" class="mono">level</th><td>Level position in that world (1 = first). Leave empty for exam-only questions.</td></tr>
      <tr><th scope="row" class="mono">type</th><td>mcq, matching, ordering, fill, blitz or subnet.</td></tr>
      <tr><th scope="row" class="mono">topic</th><td>Topic tag, used in exam and audit breakdowns.</td></tr>
      <tr><th scope="row" class="mono">rating</th><td>Starting difficulty, 400 to 2000. Empty means 1000.</td></tr>
      <tr><th scope="row" class="mono">prompt</th><td>The question. For fill, write ___ for each blank.</td></tr>
      <tr><th scope="row" class="mono">choices</th><td>Items separated by <code>|</code>. mcq: the options. matching: <code>left = right</code> pairs. ordering: items in the correct order. blitz: <code>mode=bin2dec bits=8 seconds=20</code>. subnet: <code>mode=network min=24 max=30</code>.</td></tr>
      <tr><th scope="row" class="mono">answer</th><td>mcq: the correct option’s number (1 = first) or its text. fill: one answer per blank separated by <code>|</code>, alternatives by <code>/</code>. Others: leave empty.</td></tr>
      <tr><th scope="row" class="mono">explanation</th><td>Shown to students after they answer.</td></tr>
    </tbody>
  </table>
</section>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
