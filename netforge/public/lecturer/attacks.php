<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\Db;
use NetForge\Defence\Defence;
use NetForge\GameError;

// Module 5.0 settings. Admins change how often attacks happen (FR-5.1), which topic
// unlocks each attack type, and can send a test attack for demos. Lecturers can view.
$me = Staff::begin();
$isAdmin = $me['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        Staff::flash('error', 'Only an administrator can change attack settings.');
        Staff::back();
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'settings') {
        $min = Staff::int($_POST['min'] ?? '', 1, 1440, 20);
        $max = Staff::int($_POST['max'] ?? '', 1, 1440, 60);
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        foreach ([
            'attacks_enabled' => isset($_POST['enabled']) ? 1 : 0, 'attack_min_minutes' => $min, 'attack_max_minutes' => $max,
            'attack_seconds' => Staff::int($_POST['seconds'] ?? '', 15, 600, 60), 'attack_penalty_minutes' => Staff::int($_POST['penalty_minutes'] ?? '', 0, 600, 5),
            'attack_penalty_min' => Staff::int($_POST['penalty_min'] ?? '', 0, 1000000000, 100),
        ] as $k => $v) {
            Db::exec('UPDATE game_config SET value = ? WHERE name = ?', [(string) $v, $k]);
        }
        // pull forward anyone whose next attack is now further away than the new maximum
        Db::exec('UPDATE users SET next_attack_at = LEAST(next_attack_at, NOW() + INTERVAL ? MINUTE) WHERE next_attack_at IS NOT NULL', [$max]);
        Staff::flash('ok', 'Attack settings saved.');
    } elseif ($action === 'types') {
        $worlds = array_map('intval', array_column(Db::all('SELECT number FROM worlds'), 'number'));
        foreach ((array) ($_POST['type'] ?? []) as $code => $v) {
            $w = (int) ($v['world'] ?? -1);
            if (in_array($w, $worlds, true)) {
                Db::exec('UPDATE attack_types SET unlock_world = ?, enabled = ? WHERE code = ?', [$w, isset($v['enabled']) ? 1 : 0, (string) $code]);
            }
        }
        Staff::flash('ok', 'Attack types saved.');
    } elseif ($action === 'test') {
        $u = Db::one('SELECT id FROM users WHERE username = ? AND role = "student"', [trim((string) ($_POST['username'] ?? ''))]);
        try {
            if ($u === null) {
                throw new GameError('No student has that username.');
            }
            $type = ($_POST['type_code'] ?? '') ?: null;
            Defence::launch((int) $u['id'], $type);
            Staff::flash('ok', 'Test attack sent. It appears in the student’s game within 15 seconds.');
        } catch (GameError $e) {
            Staff::flash('error', $e->getMessage());
        }
    }
    Staff::back();
}

$s = Defence::settings();
$types = Db::all('SELECT t.*, (SELECT COUNT(*) FROM attack_scenarios s WHERE s.type_code = t.code) scenarios FROM attack_types t ORDER BY t.unlock_world');
$worlds = Db::all('SELECT number, title FROM worlds ORDER BY number');
$stats = Db::all(
    'SELECT t.name, COUNT(a.id) total, SUM(a.status = "defended") defended, SUM(a.status = "breached") breached
       FROM attack_types t LEFT JOIN attack_scenarios s ON s.type_code = t.code LEFT JOIN attacks a ON a.scenario_id = s.id AND a.status <> "incoming"
      GROUP BY t.code ORDER BY t.unlock_world'
);
$recent = Db::all(
    'SELECT a.status, a.created_at, a.penalty, u.username, t.name, s.title FROM attacks a JOIN users u ON u.id = a.user_id
       JOIN attack_scenarios s ON s.id = a.scenario_id JOIN attack_types t ON t.code = s.type_code ORDER BY a.id DESC LIMIT 15'
);
$dis = $isAdmin ? '' : 'disabled';

$title = 'Cyber attacks';
$active = 'attacks';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<div class="page-head">
  <div><h1>Cyber attacks</h1>
  <p class="lede">Students face random attacks built from topics they’ve mastered: a timed defence, a credit penalty if it gets through, and a security rating from their recent results.<?= $isAdmin ? '' : ' Only an administrator can change these settings.' ?></p></div>
</div>

<div class="two-col">
  <section class="panel">
    <h2>Frequency and stakes</h2>
    <form method="post" class="form">
      <?= Csrf::field() ?><input type="hidden" name="action" value="settings">
      <label class="check"><input type="checkbox" name="enabled" <?= $s['enabled'] ? 'checked' : '' ?> <?= $dis ?>> Random attacks are on</label>
      <div class="grid2">
        <label>Shortest gap (minutes of play) <input type="number" name="min" min="1" max="1440" value="<?= $s['min'] ?>" <?= $dis ?>></label>
        <label>Longest gap (minutes of play) <input type="number" name="max" min="1" max="1440" value="<?= $s['max'] ?>" <?= $dis ?>></label>
        <label>Time to respond (seconds) <input type="number" name="seconds" min="15" max="600" value="<?= $s['seconds'] ?>" <?= $dis ?>></label>
        <label>Breach costs (minutes of the student’s income) <input type="number" name="penalty_minutes" min="0" max="600" value="<?= $s['penaltyMinutes'] ?>" <?= $dis ?>></label>
        <label>Smallest breach penalty (credits) <input type="number" name="penalty_min" min="0" max="1000000000" value="<?= $s['penaltyMin'] ?>" <?= $dis ?>></label>
      </div>
      <p class="help">Attacks only start while a student has the game open, never during a lesson or exam, and never take credits below zero.</p>
      <?php if ($isAdmin): ?><div class="actions"><button class="btn primary">Save settings</button></div><?php endif; ?>
    </form>
  </section>

  <section class="panel">
    <h2>Send a test attack</h2>
    <form method="post" class="form">
      <?= Csrf::field() ?><input type="hidden" name="action" value="test">
      <label>Student username <input name="username" required <?= $dis ?>></label>
      <label>Attack type <select name="type_code" <?= $dis ?>><option value="">Any the student has unlocked</option>
        <?php foreach ($types as $t): ?><option value="<?= e($t['code']) ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></label>
      <p class="help">Useful for demos. A chosen type is sent even if the student hasn’t unlocked it yet.</p>
      <?php if ($isAdmin): ?><div class="actions"><button class="btn">Send attack</button></div><?php endif; ?>
    </form>
  </section>
</div>

<section class="panel">
  <h2>Attack types</h2>
  <form method="post">
    <?= Csrf::field() ?><input type="hidden" name="action" value="types">
    <table class="data">
      <thead><tr><th scope="col">Type</th><th scope="col">Unlocked by mastering</th><th scope="col" class="num">Scenarios</th><th scope="col" class="num">Faced</th><th scope="col" class="num">Defended</th><th scope="col">On</th></tr></thead>
      <tbody>
      <?php foreach ($types as $i => $t): $st = $stats[$i]; ?>
        <tr>
          <th scope="row"><?= e($t['name']) ?><small><?= e($t['summary']) ?></small></th>
          <td><select name="type[<?= e($t['code']) ?>][world]" aria-label="Topic that unlocks <?= e($t['name']) ?>" <?= $dis ?>>
            <?php foreach ($worlds as $w): ?><option value="<?= (int) $w['number'] ?>" <?= (int) $w['number'] === (int) $t['unlock_world'] ? 'selected' : '' ?>>T<?= (int) $w['number'] ?> <?= e($w['title']) ?></option><?php endforeach; ?></select></td>
          <td class="num mono"><?= (int) $t['scenarios'] ?></td>
          <td class="num mono"><?= (int) $st['total'] ?></td>
          <td class="num mono"><?= (int) $st['total'] ? round($st['defended'] / $st['total'] * 100) . '%' : '–' ?></td>
          <td><input type="checkbox" name="type[<?= e($t['code']) ?>][enabled]" aria-label="<?= e($t['name']) ?> enabled" <?= $t['enabled'] ? 'checked' : '' ?> <?= $dis ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($isAdmin): ?><div class="actions"><button class="btn primary">Save attack types</button></div><?php endif; ?>
  </form>
</section>

<section class="panel">
  <h2>Recent attacks</h2>
  <?php if (!$recent): ?><p class="empty">No attacks yet.</p><?php else: ?>
  <table class="data">
    <thead><tr><th scope="col">When</th><th scope="col">Student</th><th scope="col">Attack</th><th scope="col">Outcome</th><th scope="col" class="num">Credits lost</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr><td class="mono nowrap"><?= date('j M H:i', strtotime($r['created_at'])) ?></td><td><?= e($r['username']) ?></td>
        <th scope="row"><?= e($r['name']) ?><small><?= e($r['title']) ?></small></th>
        <td><?php if ($r['status'] === 'defended'): ?><span class="verdict ok"><i class="ti ti-shield-check" aria-hidden="true"></i>Defended</span><?php elseif ($r['status'] === 'breached'): ?><span class="verdict bad"><i class="ti ti-shield-x" aria-hidden="true"></i>Breached</span><?php else: ?><span class="verdict warn"><i class="ti ti-clock" aria-hidden="true"></i>In progress</span><?php endif; ?></td>
        <td class="num mono"><?= number_format((int) $r['penalty']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
