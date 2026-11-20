<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\Db;

// FR-2.4 / FR-4.2: the economy. Credits come from passive income (online devices earn per hour);
// XP from answering questions sets the student's level, and the level decides what they may buy.
// An item can also require a mastered topic on top of the level.
$me = Staff::begin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $worldNumbers = array_map('intval', array_column(Db::all('SELECT number FROM worlds'), 'number'));
    $n = 0;
    Db::transaction(function () use ($worldNumbers, &$n): void {
        // what each device earns per second while online (FR-4.4); fixed building parts and cables earn nothing
        foreach ((array) ($_POST['income'] ?? []) as $code => $v) {
            $n += Db::exec(
                'UPDATE item_types SET income_per_sec = ? WHERE code = ? AND category = "device" AND code NOT IN ("isp_box", "isp_fiber")',
                [Staff::int($v, 0, 1000000000, 0), (string) $code]
            );
        }
        foreach ((array) ($_POST['item'] ?? []) as $id => $v) {
            $world = ($v['world'] ?? '') === '' ? null : (int) $v['world'];
            if ($world !== null && !in_array($world, $worldNumbers, true)) {
                continue;
            }
            $n += Db::exec(
                'UPDATE shop_items SET price = ?, quantity = ?, unlock_level = ?, unlock_world = ? WHERE id = ?',
                [Staff::int($v['price'] ?? '', 0, 1000000000000, 100), Staff::int($v['quantity'] ?? '', 1, 50, 1),
                 Staff::int($v['level'] ?? '', 1, 100, 1), $world, (int) $id]
            );
        }
    });
    Staff::flash('ok', $n ? 'Shop updated. Students see the changes straight away.' : 'Nothing changed.');
    Staff::back();
}

$items = Db::all(
    'SELECT s.*, t.name, t.description, t.category, t.income_per_sec,
            (SELECT COUNT(*) FROM users u WHERE u.role = "student" AND u.active = 1 AND u.level >= s.unlock_level
               AND (s.unlock_world IS NULL OR EXISTS (SELECT 1 FROM world_progress p JOIN worlds w ON w.id = p.world_id
                    WHERE p.user_id = u.id AND w.number = s.unlock_world AND p.mastered_at IS NOT NULL))) with_access
       FROM shop_items s JOIN item_types t ON t.code = s.item_code ORDER BY s.sort_order, s.id'
);
$worlds = Db::all('SELECT number, title FROM worlds ORDER BY number');
$xpPerLevel = Db::configInt('xp_per_level', 1000);

// Pacing: how long a typical network from the area before takes to save for each lease (tier 1, all online).
$rate = fn (string $code) => (int) (Db::one('SELECT income_per_sec r FROM item_types WHERE code = ?', [$code])['r'] ?? 0);
$laptopRate = $rate('laptop');
$cap = Db::configInt('income_cap_hours', 8);
$setups = [
    'office'     => ['label' => 'a garage with the laptop and two home routers', 'gear' => ['laptop' => 1, 'home_router' => 2]],
    'datacentre' => ['label' => 'that garage plus an office with 4 PCs, a switch and 2 routers', 'gear' => ['laptop' => 1, 'home_router' => 2, 'pc' => 4, 'switch8' => 1, 'router' => 2]],
];
$pacing = [];
foreach (\NetForge\Layout::data()['locations'] as $code => $loc) {
    if (($loc['leaseCost'] ?? 0) > 0 && isset($setups[$code])) {
        $perSec = array_sum(array_map(fn ($c, $n) => $rate($c) * $n, array_keys($setups[$code]['gear']), $setups[$code]['gear']));
        $pacing[] = ['name' => $loc['name'], 'cost' => (int) $loc['leaseCost'], 'level' => (int) $loc['unlockLevel'],
            'setup' => $setups[$code]['label'], 'rate' => $perSec, 'hours' => $perSec > 0 ? round($loc['leaseCost'] / $perSec / 3600, 1) : null];
    }
}

$title = 'Unlocks and prices';
$active = 'unlocks';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<div class="page-head">
  <div><h1>Unlocks and prices</h1>
  <p class="lede">Students earn credits by renting out their network: every device online earns credits every second. They earn XP by answering questions, and their level decides which equipment those credits can buy. Levels set how much learning each item needs; prices set how long the network has to run to afford it.</p></div>
</div>

<form method="post">
  <?= Csrf::field() ?>
  <table class="data">
    <thead><tr><th scope="col">Item</th><th scope="col" class="num">Level needed</th><th scope="col">Also requires</th><th scope="col" class="num">Price</th><th scope="col" class="num">Pack size</th><th scope="col" class="num">Earns per second</th><th scope="col" class="num">Students with access</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): $sid = (int) $it['id']; ?>
      <tr>
        <th scope="row"><?= e($it['name']) ?><small><?= e($it['description']) ?></small></th>
        <td class="num"><label class="sr-only" for="l<?= $sid ?>">Level needed for <?= e($it['name']) ?></label><input class="num-in mono" id="l<?= $sid ?>" name="item[<?= $sid ?>][level]" type="number" min="1" max="100" value="<?= (int) $it['unlock_level'] ?>"></td>
        <td><label class="sr-only" for="w<?= $sid ?>">Topic also required for <?= e($it['name']) ?></label>
          <select id="w<?= $sid ?>" name="item[<?= $sid ?>][world]">
            <option value="" <?= $it['unlock_world'] === null ? 'selected' : '' ?>>Nothing else</option>
            <?php foreach ($worlds as $w): ?>
              <option value="<?= (int) $w['number'] ?>" <?= $it['unlock_world'] !== null && (int) $it['unlock_world'] === (int) $w['number'] ? 'selected' : '' ?>>Master W<?= (int) $w['number'] ?> <?= e($w['title']) ?></option>
            <?php endforeach; ?>
          </select></td>
        <td class="num"><label class="sr-only" for="p<?= $sid ?>">Price</label><input class="num-in mono" id="p<?= $sid ?>" name="item[<?= $sid ?>][price]" type="number" min="0" max="1000000000000" value="<?= (int) $it['price'] ?>"></td>
        <td class="num"><label class="sr-only" for="q<?= $sid ?>">Pack size</label><input class="num-in mono" id="q<?= $sid ?>" name="item[<?= $sid ?>][quantity]" type="number" min="1" max="50" value="<?= (int) $it['quantity'] ?>"></td>
        <td class="num"><?php if (in_array($it['category'], ['device'], true) && $it['item_code'] !== 'firewall'): ?><label class="sr-only" for="i<?= $sid ?>">Credits per second for <?= e($it['name']) ?></label><input class="num-in mono" id="i<?= $sid ?>" name="income[<?= e($it['item_code']) ?>]" type="number" min="0" max="1000000000" value="<?= (int) $it['income_per_sec'] ?>"><?php else: ?><span class="mono">–</span><?php endif; ?></td>
        <td class="num mono"><?= (int) $it['with_access'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="grid2 economy-extra">
    <label class="field">Starter laptop earns per second <small>Every student starts with it; it isn’t sold in the shop.</small>
      <input class="num-in mono" name="income[laptop]" type="number" min="0" max="1000000000" value="<?= $laptopRate ?>"></label>
    <div class="pacing">
      <strong>Pacing</strong>
      <p class="help">How long each lease takes to save for, with the network a student typically has by then, all online. Income saves up for at most <?= $cap ?> hours while a student is away.</p>
      <ul><?php foreach ($pacing as $p): ?>
        <li><b><?= e($p['name']) ?></b>, <?= number_format($p['cost']) ?> credits at level <?= $p['level'] ?>: <?= e($p['setup']) ?> earns <b class="mono"><?= number_format($p['rate']) ?>/s</b>, so <?= $p['hours'] === null ? 'never' : "about <b class=\"mono\">{$p['hours']} h</b>" ?><?= $p['hours'] !== null && $p['hours'] > $cap ? ', more than one break away' : '' ?>.</li>
      <?php endforeach; ?></ul>
      <p class="help">Upgrades (x1.5, x2) and extra gear make it faster. The office and datacentre also open new learning topics, so long waits here hold back learning.</p>
    </div>
  </div>
  <p class="help">Each level takes <?= $xpPerLevel ?> XP. A correct answer at an even rating gives about 100 XP, less as students outgrow a topic. A device only earns while it’s linked back to the ISP box.</p>
  <div class="actions"><button class="btn primary" type="submit">Save changes</button></div>
</form>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
