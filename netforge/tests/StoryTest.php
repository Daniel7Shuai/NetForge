<?php
declare(strict_types=1);

// Run: php tests/StoryTest.php   (needs the netforge database with schema_datatech.sql)
// The ending: DataTech needs every topic mastered and 999B; buying it ends the game, and the
// student can go back to just before. Makes its own student and removes it after.

require __DIR__ . '/../src/bootstrap.php';

use NetForge\Db;
use NetForge\Ending;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Layout;
use NetForge\Rules;

$passed = 0;
$failed = 0;
function check(string $name, mixed $got, mixed $want): void
{
    global $passed, $failed;
    if ($got === $want) {
        $passed++;
        echo "  ok    $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
    }
}
function err(callable $fn): string
{
    try {
        $fn();
        return 'no error';
    } catch (GameError $e) {
        return $e->getMessage();
    }
}

$tag = 'zt' . random_int(1000, 9999);
Db::exec('INSERT INTO users (username, email, password_hash, role, credits) VALUES (?, ?, ?, "student", 2000000000000)',
    ["{$tag}_end", "{$tag}_end@netforge.test", password_hash('x-password-1', PASSWORD_DEFAULT)]);
$uid = (int) Db::pdo()->lastInsertId();
$credits = fn () => (int) Db::one('SELECT credits FROM users WHERE id = ?', [$uid])['credits'];
$shopId = (int) Db::one('SELECT id FROM shop_items WHERE item_code = "datatech"')['id'];
$price = (int) Db::one('SELECT price FROM shop_items WHERE item_code = "datatech"')['price'];

try {
    echo "Buying DataTech\n";
    check('it costs 999 billion', $price, 999000000000);
    check('locked until every topic is mastered', str_contains(err(fn () => Rules::buy($uid, $shopId)), 'Master every topic'), true);
    $row = array_values(array_filter(GameState::load($uid)['shop'], fn ($r) => $r['code'] === 'datatech'))[0];
    check('the shop shows it locked, with the topics to go', [$row['locked'], count($row['topicsLeft']) > 0], [true, true]);

    foreach (Db::all('SELECT id FROM worlds WHERE archived = 0') as $w) {
        Db::exec('INSERT INTO world_progress (user_id, world_id, rating, mastered_at) VALUES (?, ?, 1000, NOW())
                  ON DUPLICATE KEY UPDATE mastered_at = NOW()', [$uid, $w['id']]);
    }
    check('every topic mastered: nothing left', Ending::topicsLeft($uid), []);
    $before = $credits();
    Rules::buy($uid, $shopId);
    check('buying takes 999B', $credits(), $before - $price);
    check('the game has ended', GameState::load($uid)['user']['gameEnded'], true);
    check('a company isn’t carried in the inventory', Db::one('SELECT 1 FROM inventory WHERE user_id = ? AND item_code = "datatech"', [$uid]), null);
    check('it can’t be bought twice', err(fn () => Rules::buy($uid, $shopId)), 'You already own DataTech.');

    echo "Going back\n";
    Ending::rewind($uid);
    check('the 999B comes back', $credits(), $before);
    check('the game carries on', Ending::ended($uid), false);
    check('can’t go back twice', str_contains(err(fn () => Ending::rewind($uid)), 'haven’t bought'), true);

    echo "Placement hints\n";
    check('a Catalyst goes on a shelf or in a rack', str_starts_with(Layout::hint(['shelf', 'rack']), 'It goes on a shelf or in a rack slot'), true);
} finally {
    Db::exec('DELETE FROM users WHERE id = ?', [$uid]);
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
