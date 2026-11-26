<?php
declare(strict_types=1);

// Run: php tests/CliTest.php   (needs the netforge database with schema_staff.sql)
// The Work Laptop's console: a small Cisco IOS command line, and the bonus for a configured device.
// Makes its own student and removes it after.

require __DIR__ . '/../src/bootstrap.php';

use NetForge\Cli;
use NetForge\Db;
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

$tag = 'zt' . random_int(1000, 9999);
Db::exec('INSERT INTO users (username, email, password_hash, role, credits, level, location) VALUES (?, ?, ?, "student", 1000, 9, "office")',
    ["{$tag}_cli", "{$tag}_cli@netforge.test", password_hash('x-password-1', PASSWORD_DEFAULT)]);
$uid = (int) Db::pdo()->lastInsertId();
$dev = function (string $code, int $x, int $y, string $label) use ($uid): int {
    Db::exec('INSERT INTO devices (user_id, item_code, label, location, tile_x, tile_y) VALUES (?, ?, ?, "office", ?, ?)', [$uid, $code, $label, $x, $y]);
    return (int) Db::pdo()->lastInsertId();
};
$cable = fn (string $code, int $a, string $ap, int $b, string $bp) => Db::exec(
    'INSERT INTO cables (user_id, cable_code, a_device_id, a_port, b_device_id, b_port, status) VALUES (?, ?, ?, ?, ?, ?, "up")', [$uid, $code, $a, $ap, $b, $bp]);

try {
    $laptop = $dev('work_laptop', 2, 5, 'WL-1');
    $router = $dev('r1941', 3, 5, 'R-1');
    $switch = $dev('switch8', 4, 5, 'SW-1');
    $home = $dev('home_router', 5, 5, 'WRT-1');

    echo "Which devices, and the console cable\n";
    $row = fn (int $id) => Db::one('SELECT d.id, d.config, d.modules, t.sprite, t.ports FROM devices d JOIN item_types t ON t.code = d.item_code WHERE d.id = ?', [$id]);
    check('a Cisco router has a console', Cli::supports($row($router)), true);
    check('a Catalyst switch has a console', Cli::supports($row($switch)), true);
    check('a home router has none', Cli::supports($row($home)), false);
    check('no console cable: no terminal', Cli::link($uid, $router), null);
    $cable('console', $laptop, 'Console', $router, 'Con0');
    check('a console cable from a Work Laptop opens one', Cli::link($uid, $router)[1]['label'] ?? null, 'R-1');

    echo "A session\n";
    $mode = 'user';
    $iface = null;
    $out = '';
    $prompt = '';
    $type = function (string $line) use ($uid, $router, &$mode, &$iface, &$out, &$prompt): string {
        [$out, $mode, $iface, $prompt] = Cli::run($uid, $router, $line, $mode, $iface);
        return $out;
    };
    $type('');
    check('it starts in user mode', $prompt, 'Router>');
    $type('en');
    check('enable (abbreviated) gives privileged mode', $prompt, 'Router#');
    check('an ambiguous abbreviation says so', str_contains($type('co'), 'Ambiguous'), true);
    $type('conf t');
    check('configure terminal', $prompt, 'Router(config)#');
    $type('hostname R1');
    check('hostname changes the prompt', $prompt, 'R1(config)#');
    $type('enable secret class');
    check('a typo is shown with a caret', str_contains($type('hostnmae R2'), "Invalid input detected at '^' marker"), true);
    check('so is a word too many', [str_contains($type('hostname R2 extra'), "Invalid input"), $prompt], [true, 'R1(config)#']);
    $type('int g0/0');
    check('int g0/0 finds GigabitEthernet0/0', [$prompt, $iface], ['R1(config-if)#', 'Gi0/0']);
    check('a bad mask is refused', $type('ip address 192.168.1.1 255.0.255.0'), '% Bad mask 255.0.255.0 for address 192.168.1.1');
    $type('ip address 192.168.1.1 255.255.255.0');
    check('no shutdown brings it up (no cable: down)', str_contains($type('no shut'), 'changed state to down'), true);
    $type('end');
    check('show ip interface brief lists the address', str_contains($type('sh ip int br'), '192.168.1.1'), true);
    check('show running-config has the hostname and secret', (fn ($r) => str_contains($r, 'hostname R1') && str_contains($r, 'enable secret 5'))($type('show run')), true);
    $list = Cli::checklist($row($router)['config'], $row($router));
    check('everything but saving is ticked', array_column($list, 'done'), [true, true, true, false]);
    check('not configured until saved', Cli::configured($row($router)['config'], $row($router)), false);

    echo "Saving, and the bonus\n";
    $boostBefore = Income::networkBoost(Db::one('SELECT d.*, t.shop_group, t.boost_pct, t.sprite, t.ports FROM devices d JOIN item_types t ON t.code = d.item_code WHERE d.id = ?', [$router]));
    check('copy run start saves', str_contains($type('copy run start'), '[OK]'), true);
    check('now it is configured', Cli::configured($row($router)['config'], $row($router)), true);
    $boostAfter = Income::networkBoost(Db::one('SELECT d.*, t.shop_group, t.boost_pct, t.sprite, t.ports FROM devices d JOIN item_types t ON t.code = d.item_code WHERE d.id = ?', [$router]));
    check('a configured router boosts 25% more', round($boostAfter / $boostBefore, 2), 1.25);

    echo "The enable secret\n";
    $type('disable');
    $type('enable');
    check('enable now asks for the secret', $prompt, 'Password:');
    check('a wrong one is refused', [$type('wrong'), $prompt], ['% Access denied', 'R1>']);
    $type('enable');
    $type('class');
    check('the right one lets you in', $prompt, 'R1#');

    echo "A switch\n";
    $cable('console', $laptop, 'Eth0', $switch, 'Con0');
    [$mode, $iface] = ['priv', null];
    [, $mode] = Cli::run($uid, $switch, 'conf t', $mode, $iface);
    [, $mode, $iface] = Cli::run($uid, $switch, 'int fa0/1', $mode, $iface);
    [$out] = Cli::run($uid, $switch, 'ip address 10.0.0.2 255.255.255.0', $mode, $iface);
    check('a switch port takes no IP (Layer 2)', str_contains($out, 'Vlan1'), true);
    [, $mode, $iface] = Cli::run($uid, $switch, 'int vlan 1', $mode, $iface);
    [$out] = Cli::run($uid, $switch, 'ip address 10.0.0.2 255.255.255.0', $mode, $iface);
    check('interface Vlan1 does', [$iface, $out], ['Vlan1', '']);
} finally {
    Db::exec('DELETE FROM users WHERE id = ?', [$uid]);
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
