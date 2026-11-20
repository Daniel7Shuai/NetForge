<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\Db;

// FR-2.1: edit a world's settings and exam, and reorder or archive its levels.
$me = Staff::begin();
$id = (int) ($_GET['id'] ?? 0);
$w = Db::one('SELECT * FROM worlds WHERE id = ?', [$id]);
if ($w === null) {
    Staff::flash('error', 'That topic doesn’t exist.');
    Staff::redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $summary = trim((string) ($_POST['summary'] ?? ''));
        if ($title === '' || mb_strlen($title) > 80 || $summary === '' || mb_strlen($summary) > 255) {
            Staff::flash('error', 'Give the topic a title (up to 80 characters) and a summary (up to 255).');
            Staff::back();
        }
        Db::exec(
            'UPDATE worlds SET title = ?, summary = ?, ccna_modules = ?, requires_location = ?, exam_questions = ?, exam_minutes = ?, exam_pass_pct = ?, archived = ? WHERE id = ?',
            [
                $title, $summary, mb_substr(trim((string) ($_POST['ccna_modules'] ?? '')), 0, 80),
                isset(\NetForge\Layout::data()['locations'][$_POST['requires_location'] ?? '']) && ($_POST['requires_location'] ?? '') !== 'garage' ? $_POST['requires_location'] : null,
                Staff::int($_POST['exam_questions'] ?? '', 3, 50, 10), Staff::int($_POST['exam_minutes'] ?? '', 1, 120, 10),
                Staff::int($_POST['exam_pass_pct'] ?? '', 1, 100, 70), isset($_POST['archived']) ? 1 : 0, $id,
            ]
        );
        Staff::flash('ok', 'Topic saved.');
    } elseif ($action === 'move') {
        // swap sort_order with the neighbouring level
        $lvl = Db::one('SELECT id, sort_order FROM levels WHERE id = ? AND world_id = ?', [(int) ($_POST['level'] ?? 0), $id]);
        $up = ($_POST['dir'] ?? '') === 'up';
        if ($lvl) {
            $other = Db::one(
                'SELECT id, sort_order FROM levels WHERE world_id = ? AND sort_order ' . ($up ? '<' : '>') . ' ? ORDER BY sort_order ' . ($up ? 'DESC' : 'ASC') . ' LIMIT 1',
                [$id, $lvl['sort_order']]
            );
            if ($other) {
                Db::transaction(function () use ($lvl, $other): void {
                    Db::exec('UPDATE levels SET sort_order = ? WHERE id = ?', [$other['sort_order'], $lvl['id']]);
                    Db::exec('UPDATE levels SET sort_order = ? WHERE id = ?', [$lvl['sort_order'], $other['id']]);
                });
                Staff::flash('ok', 'Level order updated.');
            }
        }
    } elseif ($action === 'archive') {
        $arch = ($_POST['archived'] ?? '1') === '1' ? 1 : 0;
        Db::exec('UPDATE levels SET archived = ? WHERE id = ? AND world_id = ?', [$arch, (int) ($_POST['level'] ?? 0), $id]);
        Staff::flash('ok', $arch ? 'Level archived. Students no longer see it.' : 'Level restored.');
    }
    Staff::back();
}

$levels = Db::all(
    'SELECT l.*, (SELECT COUNT(*) FROM questions q WHERE q.level_id = l.id AND q.archived = 0) questions,
            (SELECT COUNT(*) FROM level_progress p WHERE p.level_id = l.id AND p.completed_at IS NOT NULL) completed
       FROM levels l WHERE l.world_id = ? ORDER BY l.sort_order, l.id',
    [$id]
);
$examPool = (int) Db::one('SELECT COUNT(*) n FROM questions WHERE world_id = ? AND archived = 0', [$id])['n'];

$title = 'T' . $w['number'] . ' ' . $w['title'];
$active = 'index';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<p class="crumbs"><a href="index.php">Content</a> / Topic <?= (int) $w['number'] ?></p>
<div class="page-head">
  <h1><span class="mono tag">T<?= (int) $w['number'] ?></span> <?= e($w['title']) ?></h1>
  <a class="btn" href="questions.php?world=<?= $id ?>"><i class="ti ti-list-details" aria-hidden="true"></i>Questions in this topic</a>
</div>

<section class="panel">
  <h2>Levels</h2>
  <?php if (!$levels): ?>
    <p class="empty">No levels yet. Students see this topic as “coming soon” until it has one.</p>
  <?php else: ?>
  <table class="data">
    <thead><tr><th scope="col" class="num">#</th><th scope="col">Title</th><th scope="col" class="num">Questions</th><th scope="col" class="num">Per run</th><th scope="col" class="num">Pass</th><th scope="col" class="num">Completed by</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($levels as $i => $l): ?>
      <tr<?= $l['archived'] ? ' class="dim"' : '' ?>>
        <td class="num mono"><?= $i + 1 ?></td>
        <th scope="row"><?= e($l['title']) ?><?= $l['archived'] ? ' <small>(archived)</small>' : '' ?></th>
        <td class="num mono"><?= (int) $l['questions'] ?></td>
        <td class="num mono"><?= (int) $l['questions_per_run'] ?></td>
        <td class="num mono"><?= (int) $l['pass_pct'] ?>%</td>
        <td class="num mono"><?= (int) $l['completed'] ?></td>
        <td class="act">
          <form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="level" value="<?= (int) $l['id'] ?>">
            <button class="icon" name="dir" value="up" aria-label="Move <?= e($l['title']) ?> up" <?= $i === 0 ? 'disabled' : '' ?>><i class="ti ti-arrow-up"></i></button>
            <button class="icon" name="dir" value="down" aria-label="Move <?= e($l['title']) ?> down" <?= $i === count($levels) - 1 ? 'disabled' : '' ?>><i class="ti ti-arrow-down"></i></button>
          </form>
          <a class="btn sm" href="level.php?id=<?= (int) $l['id'] ?>">Edit</a>
          <form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="level" value="<?= (int) $l['id'] ?>">
            <button class="btn sm ghost" name="archived" value="<?= $l['archived'] ? '0' : '1' ?>"><?= $l['archived'] ? 'Restore' : 'Archive' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <p><a class="btn" href="level.php?world=<?= $id ?>"><i class="ti ti-plus" aria-hidden="true"></i>Add level</a></p>
</section>

<section class="panel">
  <h2>Topic settings and exam</h2>
  <form method="post" class="form">
    <?= Csrf::field() ?><input type="hidden" name="action" value="save">
    <div class="grid2">
      <label>Title <input name="title" value="<?= e($w['title']) ?>" maxlength="80" required></label>
      <label>CCNA modules <input name="ccna_modules" value="<?= e($w['ccna_modules']) ?>" maxlength="80"><small>The blueprint domains it covers, for example Network Access.</small></label>
    </div>
    <label>Taught in <select name="requires_location">
      <?php foreach (\NetForge\Layout::data()['locations'] as $code => $loc): ?>
        <option value="<?= e($code) ?>" <?= ($w['requires_location'] ?? 'garage') === $code ? 'selected' : '' ?>><?= e($loc['name']) ?><?= $loc['leaseCost'] ? ', students must lease it first' : '' ?></option>
      <?php endforeach; ?></select>
      <small>Students only open this topic once they’ve leased that area in the game (and mastered the topics before it).</small></label>
    <label>Summary <input name="summary" value="<?= e($w['summary']) ?>" maxlength="255" required></label>
    <div class="grid3">
      <label>Exam questions <input name="exam_questions" type="number" min="3" max="50" value="<?= (int) $w['exam_questions'] ?>"></label>
      <label>Exam minutes <input name="exam_minutes" type="number" min="1" max="120" value="<?= (int) $w['exam_minutes'] ?>"></label>
      <label>Pass mark (%) <input name="exam_pass_pct" type="number" min="1" max="100" value="<?= (int) $w['exam_pass_pct'] ?>"><small>Passing the exam masters the topic and unlocks its gear.</small></label>
    </div>
    <p class="help">The exam draws from all <?= $examPool ?> questions in this topic.<?= $examPool < (int) $w['exam_questions'] ? ' That’s fewer than the exam length, so some questions will repeat.' : '' ?></p>
    <label class="check"><input type="checkbox" name="archived" <?= $w['archived'] ? 'checked' : '' ?>> Archive this topic (students won’t see it)</label>
    <div class="actions"><button class="btn primary" type="submit">Save topic</button></div>
  </form>
</section>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
