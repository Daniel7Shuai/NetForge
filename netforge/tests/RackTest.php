<?php
declare(strict_types=1);

// Run: php tests/RackTest.php   (needs the netforge database with schema_racks.sql)
// Racks with U-slots, PDUs, Misc gear and interface modules. Makes its own student and removes it after.

require __DIR__ . '/../src/bootstrap.php';

use NetForge\Db;
use NetForge\GameError;
use NetForge\Progress\Income;
use NetForge\Racks;
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
Db::exec('INSERT INTO users (username, email, password_hash, role, credits, level, location) VALUES (?, ?, ?, "student", 1000, 9, "office")',
    ["{$tag}_rack", "{$tag}_rack@netforge.test", password_hash('x-password-1', PASSWORD_DEFAULT)]);
$uid = (int) Db::pdo()->lastInsertId();
$give = fn (string $code, int $n = 1) => Db::exec('INSERT INTO inventory (user_id, item_code, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)', [$uid, $code, $n]);
$id = fn (string $label) => (int) Db::one('SELECT id FROM devices WHERE user_id = ? AND label = ?', [$uid, $label])['id'];
$rate = fn () => round(Income::summary($uid)['gross'], 2); // before the electricity bill (PowerTest covers that)
[$rx, $ry] = [19, 3]; // a rack position in the office server room

try {
    echo "Mounting\n";
    $give('srv_dns', 7);
    $give('switch8', 2);
    $give('pdu', 2);
    $give('patch_panel');
    $dns1 = Db::transaction(fn () => Racks::mount($uid, 'srv_dns', $rx, $ry));
    check('a 2U server goes in at U1', (int) Db::one('SELECT rack_u FROM devices WHERE user_id = ? AND label = ?', [$uid, $dns1])['rack_u'], 1);
    $sw = Db::transaction(fn () => Racks::mount($uid, 'switch8', $rx, $ry, 5));
    check('a switch at a chosen U', (int) Db::one('SELECT rack_u FROM devices WHERE id = ?', [$id($sw)])['rack_u'], 5);
    check('can’t overlap another device', str_contains(err(fn () => Db::transaction(fn () => Racks::mount($uid, 'srv_dns', $rx, $ry, 4))), 'needs U4 to U5 free'), true);
    check('can’t go past the top', str_contains(err(fn () => Db::transaction(fn () => Racks::mount($uid, 'srv_dns', $rx, $ry, 12))), 'U12 to U13'), true);
    check('not in a place that isn’t a rack', err(fn () => Db::transaction(fn () => Racks::mount($uid, 'srv_dns', 2, 6))), 'There’s no rack there.');
    // fill it up: U3-4, U6-7, U8-9, U10-11; then a 2U server no longer fits
    for ($i = 0; $i < 4; $i++) {
        Db::transaction(fn () => Racks::mount($uid, 'srv_dns', $rx, $ry));
    }
    check('a full rack refuses', str_contains(err(fn () => Db::transaction(fn () => Racks::mount($uid, 'srv_dns', $rx, $ry))), 'no 2U gap'), true);
    $pdu = Db::transaction(fn () => Racks::mount($uid, 'pdu', $rx, $ry));
    check('the 1U PDU still fits at U12', (int) Db::one('SELECT rack_u FROM devices WHERE id = ?', [$id($pdu)])['rack_u'], 12);
    check('placing on a rack position from the map mounts it', str_contains(Rules::place($uid, 'switch8', 20, 3), 'SW'), true);
    Racks::shift($uid, $id($sw), 5);
    check('slide refused into a server', str_contains(err(fn () => Racks::shift($uid, $id($sw), 3)), 'needs U3 to U3 free'), true);

    echo "Power\n";
    $isp = (int) (Db::one('SELECT id FROM devices WHERE user_id = ? AND item_code = "isp_box" AND location = "office"', [$uid])['id'] ?? 0);
    if (!$isp) {
        Db::exec('INSERT INTO devices (user_id, item_code, label, location, tile_x, tile_y, fixed) VALUES (?, "isp_box", "ISP-T", "office", 14, 1, 1)', [$uid]);
        $isp = (int) Db::pdo()->lastInsertId();
    }
    $link = function (int $a, string $ap, int $b, string $bp) use ($uid): void {
        Db::exec('INSERT INTO cables (user_id, cable_code, a_device_id, a_port, b_device_id, b_port, status) VALUES (?, "copper", ?, ?, ?, ?, "up")', [$uid, $a, $ap, $b, $bp]);
    };
    $dnsRate = (int) Db::one('SELECT income_per_sec r FROM item_types WHERE code = "srv_dns"')['r'];
    $link($isp, 'LAN', $id($dns1), 'Eth0');
    check('a server in a rack with a PDU earns', $rate(), (float) $dnsRate);
    Db::exec('DELETE FROM devices WHERE id = ?', [$id($pdu)]);
    check('without a PDU the rack has no power', $rate(), 0.0);
    $pdu = Db::transaction(fn () => Racks::mount($uid, 'pdu', $rx, $ry));

    echo "Misc gear\n";
    $give('kvm');
    Db::exec('DELETE FROM devices WHERE user_id = ? AND item_code = "srv_dns" AND label <> ?', [$uid, $dns1]);
    Db::transaction(fn () => Racks::mount($uid, 'kvm', $rx, $ry));
    check('a KVM lifts its rack by 5%', $rate(), round($dnsRate * 1.05, 2));
    $give('ups', 2);
    check('no UPS: no extra hours', Income::upsHours($uid), 0);
    Db::transaction(fn () => Racks::mount($uid, 'ups', $rx, $ry));
    check('a UPS in a powered rack: 2 more hours away', Income::upsHours($uid), 2);
    check('patch panel ports take any copper cable', Rules::linkProblem('copper', ['kind' => 'copper', 'role' => 'pass'], ['kind' => 'copper', 'role' => 'mdi']), null);

    echo "Modules\n";
    $give('pt_router');
    $give('nm_1fge', 3);
    $give('wmp300n');
    $r = Rules::place($uid, 'pt_router', 21, 3);
    $rid = $id($r);
    check('a router module needs the right device', str_contains(err(fn () => Racks::installModule($uid, $id($dns1), 'nm_1fge')), 'doesn’t fit'), true);
    Racks::installModule($uid, $rid, 'nm_1fge');
    Racks::installModule($uid, $rid, 'nm_1fge');
    check('two bays, two modules', Racks::moduleList(Db::one('SELECT modules FROM devices WHERE id = ?', [$rid])['modules']), ['nm_1fge', 'nm_1fge']);
    check('no third bay', str_contains(err(fn () => Racks::installModule($uid, $rid, 'nm_1fge')), 'no free module bay'), true);
    $row = Db::one('SELECT d.modules, t.ports FROM devices d JOIN item_types t ON t.code = d.item_code WHERE d.id = ?', [$rid]);
    $names = array_column(Racks::ports($row['ports'], $row['modules']), 'name');
    check('modules add Gi1/0 and Gi2/0', [in_array('Gi1/0', $names, true), in_array('Gi2/0', $names, true)], [true, true]);
    $other = (int) Db::one('SELECT id FROM devices WHERE user_id = ? AND item_code = "switch8" LIMIT 1', [$uid])['id'];
    Db::exec('INSERT INTO cables (user_id, cable_code, a_device_id, a_port, b_device_id, b_port, status) VALUES (?, "fiber", ?, "Gi1/0", ?, "Gi0/1", "up")', [$uid, $rid, $other]);
    check('a cabled module stays in', str_contains(err(fn () => Racks::removeModule($uid, $rid, 0)), 'Unplug'), true);
    Racks::removeModule($uid, $rid, 1);
    check('an unplugged one comes out', (int) Db::one('SELECT quantity FROM inventory WHERE user_id = ? AND item_code = "nm_1fge"', [$uid])['quantity'], 2);

    $give('pc');
    $give('ap_pt');
    Db::exec('INSERT INTO devices (user_id, item_code, label, location, tile_x, tile_y) VALUES (?, "pc", "PC-T", "office", 4, 3)', [$uid]);
    $pc = (int) Db::pdo()->lastInsertId();
    $ap = Rules::place($uid, 'ap_pt', 11, 7);
    $link($isp, 'LAN2', $id($ap), 'Port0');
    $pcRate = (int) Db::one('SELECT income_per_sec r FROM item_types WHERE code = "pc"')['r'];
    $before = $rate();
    Racks::installModule($uid, $pc, 'wmp300n');
    check('a PC with a WMP300N joins the Wi-Fi', round($rate() - $before, 2), round($pcRate * 1.15, 2));

    echo "Starter kit
";
    $before = (int) (Db::one('SELECT quantity FROM inventory WHERE user_id = ? AND item_code = "server"', [$uid])['quantity'] ?? 0);
    check('the rack guide’s kit comes once', [Racks::starterKit($uid), Racks::starterKit($uid)], [true, false]);
    check('with a server in it', (int) Db::one('SELECT quantity FROM inventory WHERE user_id = ? AND item_code = "server"', [$uid])['quantity'], $before + 1);
} finally {
    Db::exec('DELETE FROM users WHERE id = ?', [$uid]);
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
