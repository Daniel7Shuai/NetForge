<?php
declare(strict_types=1);

// Run: php tests/PlaythroughTest.php   (needs the netforge database)
// Things a full playthrough as a new student turned up: the garage start, what a wrong first
// cable teaches, and a first desktop PC that fits the workbench. Makes its own student and removes it after.

require __DIR__ . '/../src/bootstrap.php';

use NetForge\Db;
use NetForge\Rules;
use NetForge\Starter;

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

$tag = 'zt' . random_int(1000, 9999);
Db::exec('INSERT INTO users (username, email, password_hash, role, credits, level, location) VALUES (?, ?, ?, "student", 5000, 3, "garage")',
    ["{$tag}_play", "{$tag}_play@netforge.test", password_hash('x-password-1', PASSWORD_DEFAULT)]);
$uid = (int) Db::pdo()->lastInsertId();
$give = fn (string $code, int $n = 1) => Rules::giveItem($uid, $code, $n);
$id = fn (string $label) => (int) Db::one('SELECT id FROM devices WHERE user_id = ? AND label = ?', [$uid, $label])['id'];

try {
    echo "The garage start\n";
    Starter::give($uid);
    $isp = (int) Db::one('SELECT id FROM devices WHERE user_id = ? AND item_code = "isp_box"', [$uid])['id'];
    check('the garage has its ISP box', $isp > 0, true);
    check('and the student has a laptop', (int) Db::one('SELECT quantity FROM inventory WHERE user_id = ? AND item_code = "laptop"', [$uid])['quantity'], 1);

    echo "A wrong first cable\n";
    $give('home_router');
    $give('copper', 3);
    $router = $id(Rules::place($uid, 'home_router', 6, 2));
    [$status, $message] = Rules::connect($uid, 'copper', $isp, 'LAN', $router, 'LAN1');
    check('ISP box into a router LAN port: the link stays down', $status, 'down');
    check('and the message points at the WAN port, not a crossover', str_contains($message, 'WAN port') && !str_contains($message, 'crossover'), true);
    $cable = (int) Db::one('SELECT id FROM cables WHERE user_id = ?', [$uid])['id'];
    Rules::unplug($uid, $cable);
    [$status] = Rules::connect($uid, 'copper', $isp, 'LAN', $router, 'WAN');
    check('into the WAN port it comes up', $status, 'up');
    check('a crossover between switch-style ports still says so', str_contains((string) Rules::linkProblem('copper', ['kind' => 'copper', 'role' => 'mdix'], ['kind' => 'copper', 'role' => 'mdix']), 'crossover'), true);

    echo "A first desktop PC\n";
    $give('pc');
    $laptop = Rules::place($uid, 'laptop', 2, 2);
    check('the laptop takes one workbench spot', $id($laptop) > 0, true);
    check('a desktop PC fits the other one, no desk needed', $id(Rules::place($uid, 'pc', 3, 2)) > 0, true);
} finally {
    Db::exec('DELETE FROM users WHERE id = ?', [$uid]);
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
