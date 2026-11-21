<?php
declare(strict_types=1);

// Run: php tests/PowerTest.php   (needs the netforge database with schema_power.sql)
// Electricity bill, efficiency upgrades, heat and cooling, brownouts and generators.
// Makes its own student and removes it after.

require __DIR__ . '/../src/bootstrap.php';

use NetForge\Db;
use NetForge\Facility;
use NetForge\GameError;
use NetForge\Progress\Income;
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

echo "The model\n";
$office = Facility::PLACES['office'];
$idle = Facility::compute($office, 0, 0, 0, []);
check('nothing running: room temperature', $idle['temp'], 22.0);
check('nothing running: full speed', $idle['factor'], 1.0);
$hot = Facility::compute($office, 9000, 0, 0, []);
check('9 kW against 6 kW of cooling: 40°C', $hot['temp'], 40.0);
check('40°C: 3% slower a degree over 30', $hot['heatFactor'], 0.7);
check('never slower than half from heat', Facility::compute($office, 60000, 0, 0, [])['heatFactor'], 0.5);
check('a cooling unit brings it down', Facility::compute($office, 9000, 0, 25000, [])['temp'], 25.5);
check('over the supply: browns out', Facility::compute($office, 20000, 0, 100000, [])['powerFactor'], 0.75);
check('a generator covers it', Facility::compute($office, 20000, 25000, 100000, [])['powerFactor'], 1.0);
check('upgrades cut the bill', Facility::compute($office, 0, 0, 0, [['item_code' => 'a', 'bill_cut' => 4, 'heat_cut' => 0, 'cool_boost' => 0]])['billPct'], 8);
check('but not below 2%', Facility::compute($office, 0, 0, 0, array_fill(0, 9, ['item_code' => 'a', 'bill_cut' => 4, 'heat_cut' => 0, 'cool_boost' => 0]))['billPct'], 2);

$tag = 'zt' . random_int(1000, 9999);
Db::exec('INSERT INTO users (username, email, password_hash, role, credits, level, location) VALUES (?, ?, ?, "student", 100000000, 9, "office")',
    ["{$tag}_pow", "{$tag}_pow@netforge.test", password_hash('x-password-1', PASSWORD_DEFAULT)]);
$uid = (int) Db::pdo()->lastInsertId();
$dev = function (string $code, int $x, int $y, string $label) use ($uid): int {
    Db::exec('INSERT INTO devices (user_id, item_code, label, location, tile_x, tile_y) VALUES (?, ?, ?, "office", ?, ?)', [$uid, $code, $label, $x, $y]);
    return (int) Db::pdo()->lastInsertId();
};
$here = fn () => Facility::all($uid)['office'];
$sum = fn () => Income::summary($uid);
$shopId = fn (string $code) => (int) Db::one('SELECT id FROM shop_items WHERE item_code = ?', [$code])['id'];

try {
    echo "The bill\n";
    $isp = (int) (Db::one('SELECT id FROM devices WHERE user_id = ? AND item_code = "isp_box" AND location = "office"', [$uid])['id'] ?? 0)
        ?: $dev('isp_box', 14, 1, 'ISP-T');
    $lap = $dev('laptop', 4, 3, 'LAP-T');
    Db::exec('INSERT INTO cables (user_id, cable_code, a_device_id, a_port, b_device_id, b_port, status) VALUES (?, "copper", ?, "LAN", ?, "Eth0", "up")', [$uid, $isp, $lap]);
    check('a laptop online earns 20/s before the bill', round($sum()['gross'], 2), 20.0);
    check('the office bill takes 12%', round($sum()['perSec'], 2), 17.6);

    echo "Upgrades\n";
    Rules::buy($uid, $shopId('led_lighting'));
    check('LED lighting: the bill drops to 10%', round($sum()['perSec'], 2), 18.0);
    check('it goes in the place, not the inventory', Db::one('SELECT 1 x FROM inventory WHERE user_id = ? AND item_code = "led_lighting"', [$uid]), null);
    check('only once per place', str_contains(err(fn () => Rules::buy($uid, $shopId('led_lighting'))), 'already has'), true);
    check('datacentre-only upgrades stay there', str_contains(err(fn () => Rules::buy($uid, $shopId('aisle_contain'))), 'for the datacentre'), true);
    check('a refused upgrade costs nothing', (int) Db::one('SELECT credits FROM users WHERE id = ?', [$uid])['credits'], 100000000 - 25000);

    echo "Heat\n";
    for ($i = 0; $i < 30; $i++) {
        $dev('pc', 1 + $i % 20, 10 + intdiv($i, 20), "PC-$i"); // 30 PCs, 7.5 kW, none online
    }
    check('30 PCs and the laptop (7.56 kW) heat the office to 37.1°C', $here()['temp'], 37.1);
    check('so the laptop earns less', round($sum()['gross'], 2), round(20 * $here()['factor'], 2));
    $dev('crac', 25, 12, 'CRAC-T');
    check('a CRAC unit cools it below 30°C', $here()['temp'] < 30, true);
    check('back to full speed', $here()['heatFactor'], 1.0);

    echo "Power\n";
    for ($i = 0; $i < 3; $i++) {
        $dev('gpu_rig_pro', 22 + $i, 14, "GPU-$i"); // 5.4 kW more, standing outside a rack
    }
    $f = $here();
    check('over 15 kW: a brownout', $f['load'] > $f['supply'] && $f['powerFactor'] < 1, true);
    $dev('gen_diesel', 26, 14, 'GEN-T');
    check('a diesel generator covers it', $here()['powerFactor'], 1.0);
    $before = $here()['load'];
    Db::exec('INSERT INTO devices (user_id, item_code, label, location, tile_x, tile_y, rack_u) VALUES (?, "server", "SRV-T", "office", 19, 3, 1)', [$uid]);
    check('a server in a rack without a PDU draws nothing', $here()['load'], $before);
} finally {
    Db::exec('DELETE FROM users WHERE id = ?', [$uid]);
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
