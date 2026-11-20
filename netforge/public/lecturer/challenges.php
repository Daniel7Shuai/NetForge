<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\Db;
use NetForge\Social\Social;

// FR-2.5: publish the weekly challenge (raid) that every guild works towards.
$me = Staff::begin();
$thisWeek = Social::weekStart();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $n = Db::exec('DELETE FROM weekly_challenges WHERE id = ? AND week_start > ?', [(int) ($_POST['id'] ?? 0), $thisWeek]);
        Staff::flash($n ? 'ok' : 'error', $n ? 'Challenge removed.' : 'Only upcoming challenges can be removed.');
        Staff::back();
    }
    $date = (string) ($_POST['week'] ?? '');
    $week = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? Social::weekStart(strtotime($date)) : null;
    $title = trim((string) ($_POST['title'] ?? ''));
    $desc = trim((string) ($_POST['description'] ?? ''));
    $world = (int) ($_POST['world'] ?? 0) ?: null;
    $errors = [];
    if ($week === null) { $errors[] = 'Pick the week (any day in it).'; }
    elseif ($week < $thisWeek) { $errors[] = 'That week is over. Pick this week or a later one.'; }
    if ($title === '' || mb_strlen($title) > 80) { $errors[] = 'Give the challenge a title of up to 80 characters.'; }
    if ($desc === '' || mb_strlen($desc) > 255) { $errors[] = 'Describe the challenge in up to 255 characters.'; }
    if ($world !== null && !Db::one('SELECT id FROM worlds WHERE id = ?', [$world])) { $errors[] = 'Pick a topic, or Any topic.'; }
    if ($errors) {
        foreach ($errors as $er) { Staff::flash('error', $er); }
        Staff::back();
    }
    Db::exec(
        'INSERT INTO weekly_challenges (week_start, title, description, world_id, target, reward_xp, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), world_id = VALUES(world_id),
           target = VALUES(target), reward_xp = VALUES(reward_xp)',
        [$week, $title, $desc, $world, Staff::int($_POST['target'] ?? '', 5, 5000, 100), Staff::int($_POST['reward'] ?? '', 0, 5000, 100), $me['id']]
    );
    Staff::flash('ok', 'Challenge published for the week of ' . date('j M Y', strtotime($week)) . '. Students see it straight away.');
    Staff::back();
}

$list = Db::all(
    'SELECT c.*, w.number wnum, w.title wtitle, (SELECT COUNT(*) FROM raid_rewards r WHERE r.challenge_id = c.id) completed
       FROM weekly_challenges c LEFT JOIN worlds w ON w.id = c.world_id ORDER BY c.week_start DESC LIMIT 12'
);
$current = Social::challenge($thisWeek);
$guilds = $current ? array_map(fn ($g) => $g + ['progress' => Social::raidProgress((int) $g['id'], $current)],
    Db::all('SELECT g.id, g.name, g.tag, (SELECT COUNT(*) FROM guild_members m WHERE m.guild_id = g.id) members FROM guilds g ORDER BY g.name')) : [];
usort($guilds, fn ($a, $b) => $b['progress'] <=> $a['progress']);
$worlds = Staff::worldOptions();

$title = 'Weekly challenges';
$active = 'challenges';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<div class="page-head">
  <div><h1>Weekly challenges</h1>
  <p class="lede">Publish one raid per week. Every correct answer by a guild member counts towards their guild’s total; guilds that reach the target pay each contributor the reward once.</p></div>
</div>

<?php if ($current): ?>
<section class="panel">
  <h2>This week: <?= e($current['title']) ?></h2>
  <p class="help"><?= e($current['description']) ?> Target <?= (int) $current['target'] ?> correct answers<?= $current['world_id'] ? ' in W' . (int) $current['world_number'] : '' ?>, reward <?= (int) $current['reward_xp'] ?> XP each.</p>
  <?php if (!$guilds): ?><p class="empty">No guilds yet.</p><?php else: ?>
  <table class="data meters">
    <thead><tr><th scope="col">Guild</th><th scope="col" class="num">Members</th><th scope="col">Progress</th></tr></thead>
    <tbody>
    <?php foreach ($guilds as $g): $pct = (int) min(100, round($g['progress'] / max(1, (int) $current['target']) * 100)); ?>
      <tr><th scope="row"><span class="mono tag">[<?= e($g['tag']) ?>]</span> <?= e($g['name']) ?></th><td class="num mono"><?= (int) $g['members'] ?></td>
        <td><span class="meter" title="<?= (int) $g['progress'] ?> of <?= (int) $current['target'] ?>"><span class="track"><span class="fill" style="width:<?= max(2, $pct) ?>%"></span></span><span class="val mono"><?= (int) $g['progress'] ?></span></span></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php else: ?>
  <p class="flash error" role="status"><i class="ti ti-alert-triangle" aria-hidden="true"></i>There’s no challenge this week, so guilds have no raid. Publish one below.</p>
<?php endif; ?>

<section class="panel">
  <h2>Publish a challenge</h2>
  <form method="post" class="form">
    <?= Csrf::field() ?>
    <div class="grid3">
      <label>Week <input type="date" name="week" value="<?= e($current ? date('Y-m-d', strtotime($thisWeek . ' +7 days')) : $thisWeek) ?>" required><small>Any day in the week. Weeks run Monday to Sunday. Re-publishing a week replaces it.</small></label>
      <label class="span2">Title <input name="title" maxlength="80" required placeholder="Subnet Siege"></label>
    </div>
    <label>Description <input name="description" maxlength="255" required placeholder="Answer subnetting questions to break through the firewall."></label>
    <div class="grid3">
      <label>Counts answers in <select name="world"><option value="0">Any topic</option>
        <?php foreach ($worlds as $wid => $wl): ?><option value="<?= $wid ?>"><?= e($wl) ?></option><?php endforeach; ?></select></label>
      <label>Target per guild <input type="number" name="target" min="5" max="5000" value="100"><small>Correct answers.</small></label>
      <label>Reward per contributor <input type="number" name="reward" min="0" max="5000" value="100"><small>XP. Helps students level up and unlock gear.</small></label>
    </div>
    <div class="actions"><button class="btn primary" type="submit">Publish challenge</button></div>
  </form>
</section>

<section class="panel">
  <h2>Recent and upcoming</h2>
  <?php if (!$list): ?><p class="empty">Nothing published yet.</p><?php else: ?>
  <table class="data">
    <thead><tr><th scope="col">Week of</th><th scope="col">Challenge</th><th scope="col">Counts</th><th scope="col" class="num">Target</th><th scope="col" class="num">Reward</th><th scope="col" class="num">Guilds completed</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($list as $c): ?>
      <tr<?= $c['week_start'] === $thisWeek ? ' class="current"' : '' ?>>
        <td class="mono nowrap"><?= date('j M Y', strtotime($c['week_start'])) ?><?= $c['week_start'] === $thisWeek ? ' <small>this week</small>' : '' ?></td>
        <th scope="row"><?= e($c['title']) ?><small><?= e($c['description']) ?></small></th>
        <td><?= $c['wnum'] === null ? 'Any topic' : 'T' . (int) $c['wnum'] ?></td>
        <td class="num mono"><?= (int) $c['target'] ?></td><td class="num mono"><?= (int) $c['reward_xp'] ?></td>
        <td class="num mono"><?= (int) $c['completed'] ?></td>
        <td class="act"><?php if ($c['week_start'] > $thisWeek): ?>
          <form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn sm ghost">Remove</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
