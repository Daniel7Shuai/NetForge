<?php
declare(strict_types=1);

// Run: php tests/IncomeTest.php   (needs the netforge database with schema_balance.sql)
// Who earns: end devices earn; routers and switches earn nothing but boost every device behind them.
// Makes its own student and removes it after.

require __DIR__ . '/../src/bootstrap.php';

use NetForge\Db;
use NetForge\Money;
use NetForge\Progress\Income;

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
Db::exec('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, "student")',
    ["{$tag}_inc", "{$tag}_inc@netforge.test", password_hash('x-password-1', PASSWORD_DEFAULT)]);
$uid = (int) Db::pdo()->lastInsertId();
$n = 0;
$dev = function (string $code, int $tier = 1) use ($uid, &$n): int {
    Db::exec('INSERT INTO devices (user_id, item_code, label, location, tile_x, tile_y, fixed, tier) VALUES (?, ?, ?, "garage", ?, 0, 0, ?)',
        [$uid, $code, $code . '-' . ++$n, $n, $tier]);
    return (int) Db::pdo()->lastInsertId();
};
$link = fn (int $a, int $b) => Db::exec('INSERT INTO cables (user_id, cable_code, a_device_id, a_port, b_device_id, b_port, status) VALUES (?, "copper", ?, "p", ?, "p", "up")',
    [$uid, $a, $b]);
$rate = fn () => round(Income::summary($uid)['perSec'], 2);
$laptop = (int) Db::one('SELECT income_per_sec r FROM item_types WHERE code = "laptop"')['r'];

try {
    echo "Only end devices earn\n";
    $isp = $dev('isp_box');
    $lap = $dev('laptop');
    $link($isp, $lap);
    check('laptop straight to the ISP box', $rate(), (float) $laptop);

    Db::exec('DELETE FROM cables WHERE user_id = ?', [$uid]);
    $router = $dev('home_router');
    $link($isp, $router);
    check('a router on its own earns nothing', $rate(), 0.0);
    $link($router, $lap);
    check('a home router boosts the laptop behind it by 25%', $rate(), round($laptop * 1.25, 2));

    Db::exec('UPDATE devices SET tier = 3 WHERE id = ?', [$router]);
    check('a tier-3 router boosts twice as much (50%)', $rate(), round($laptop * 1.5, 2));
    Db::exec('UPDATE devices SET tier = 1 WHERE id = ?', [$router]);

    // ISP -> router -> switch -> laptop: boosts multiply
    Db::exec('DELETE FROM cables WHERE user_id = ?', [$uid]);
    $switch = $dev('switch8');
    $link($isp, $router);
    $link($router, $switch);
    $link($switch, $lap);
    check('router and switch in a row multiply (x1.25 x1.4)', $rate(), round($laptop * 1.25 * 1.4, 2));
    check('only the laptop shows coins', array_keys(Income::summary($uid)['byDevice']), [$lap]);

    echo "Money in messages\n";
    check('thousands', Money::short(4000), '4k');
    check('millions', Money::short(3141592), '3.14M');
    check('hundreds of millions keep their zeros', Money::short(120000000), '120M');
    check('billions', Money::short(1400000000), '1.4B');
    check('under a thousand', Money::short(950), '950');
} finally {
    Db::exec('DELETE FROM users WHERE id = ?', [$uid]);
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
