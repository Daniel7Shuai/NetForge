<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\Db;

// FR-2.4 / FR-4.2: the economy. Credits come from passive income (online devices earn per hour);
// XP from answering questions sets the student's level, and the level decides what they may buy.
// An item can also require a mastered world on top of the level.
$me = Staff::begin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $worldNumbers = array_map('intval', array_column(Db::all('SELECT number FROM worlds'), 'number'));
    $n = 0;
    Db::transaction(function () use ($worldNumbers, &$n): void {
        foreach ((array) ($_POST['item'] ?? []) as $id => $v) {
            $world = ($v['world'] ?? '') === '' ? null : (int) $v['world'];
            if ($world !== null && !in_array($world, $worldNumbers, true)) {
                continue;
            }
            $n += Db::exec(
                'UPDATE shop_items SET price = ?, quantity = ?, unlock_level = ?, unlock_world = ? WHERE id = ?',
                [Staff::int($v['price'] ?? '', 0, 100000, 100), Staff::int($v['quantity'] ?? '', 1, 50, 1),
                 Staff::int($v['level'] ?? '', 1, 100, 1), $world, (int) $id]
            );
        }
    });
    Staff::flash('ok', $n ? 'Shop updated. Students see the changes straight away.' : 'Nothing changed.');
    Staff::back();
}

$items = Db::all(
    'SELECT s.*, t.name, t.description, t.income_per_hour,
            (SELECT COUNT(*) FROM users u WHERE u.role = "student" AND u.active = 1 AND u.level >= s.unlock_level
               AND (s.unlock_world IS NULL OR EXISTS (SELECT 1 FROM world_progress p JOIN worlds w ON w.id = p.world_id
                    WHERE p.user_id = u.id AND w.number = s.unlock_world AND p.mastered_at IS NOT NULL))) with_access
       FROM shop_items s JOIN item_types t ON t.code = s.item_code ORDER BY s.sort_order, s.id'
);
$worlds = Db::all('SELECT number, title FROM worlds ORDER BY number');
$xpPerLevel = Db::configInt('xp_per_level', 1000);

$title = 'Unlocks and prices';
$active = 'unlocks';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<div class="page-head">
  <div><h1>Unlocks and prices</h1>
  <p class="lede">Students earn credits by renting out their network: every device online earns per hour. They earn XP by answering questions, and their level decides which equipment those credits can buy. Levels set how much learning each item needs; prices set how long the network has to run to afford it.</p></div>
</div>

<form method="post">
  <?= Csrf::field() ?>
  <table class="data">
    <thead><tr><th scope="col">Item</th><th scope="col" class="num">Level needed</th><th scope="col">Also requires</th><th scope="col" class="num">Price</th><th scope="col" class="num">Pack size</th><th scope="col" class="num">Earns per hour</th><th scope="col" class="num">Students with access</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): $sid = (int) $it['id']; ?>
      <tr>
        <th scope="row"><?= e($it['name']) ?><small><?= e($it['description']) ?></small></th>
        <td class="num"><label class="sr-only" for="l<?= $sid ?>">Level needed for <?= e($it['name']) ?></label><input class="num-in mono" id="l<?= $sid ?>" name="item[<?= $sid ?>][level]" type="number" min="1" max="100" value="<?= (int) $it['unlock_level'] ?>"></td>
        <td><label class="sr-only" for="w<?= $sid ?>">World also required for <?= e($it['name']) ?></label>
          <select id="w<?= $sid ?>" name="item[<?= $sid ?>][world]">
            <option value="" <?= $it['unlock_world'] === null ? 'selected' : '' ?>>Nothing else</option>
            <?php foreach ($worlds as $w): ?>
              <option value="<?= (int) $w['number'] ?>" <?= $it['unlock_world'] !== null && (int) $it['unlock_world'] === (int) $w['number'] ? 'selected' : '' ?>>Master W<?= (int) $w['number'] ?> <?= e($w['title']) ?></option>
            <?php endforeach; ?>
          </select></td>
        <td class="num"><label class="sr-only" for="p<?= $sid ?>">Price</label><input class="num-in mono" id="p<?= $sid ?>" name="item[<?= $sid ?>][price]" type="number" min="0" max="100000" value="<?= (int) $it['price'] ?>"></td>
        <td class="num"><label class="sr-only" for="q<?= $sid ?>">Pack size</label><input class="num-in mono" id="q<?= $sid ?>" name="item[<?= $sid ?>][quantity]" type="number" min="1" max="50" value="<?= (int) $it['quantity'] ?>"></td>
        <td class="num mono"><?= (int) $it['income_per_hour'] ?: '–' ?></td>
        <td class="num mono"><?= (int) $it['with_access'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="help">Each level takes <?= $xpPerLevel ?> XP. A correct answer at an even rating gives about 100 XP, less as students outgrow a world. A device only earns while it’s linked back to the ISP box.</p>
  <div class="actions"><button class="btn primary" type="submit">Save changes</button></div>
</form>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
