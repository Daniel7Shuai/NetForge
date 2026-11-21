<?php
declare(strict_types=1);

// Run: php tests/MapTest.php   (needs the netforge database)
// The redesigned floor plans: every room and spot can be walked to, and old gear moves onto them.
// Makes its own student and removes it after.

require __DIR__ . '/../src/bootstrap.php';

use NetForge\Db;
use NetForge\Layout;
use NetForge\MapMigration;

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

echo "Floor plans\n";
foreach (['garage', 'office', 'datacentre'] as $code) {
    $loc = Layout::location($code);
    [$cols, $rows] = Layout::size($code);
    // what the player can't walk through: walls, the partition, furniture, fixtures and built-in spots
    $blocked = Layout::staticBlocked($code);
    $spawn = $loc['spawn']['x'] . ',' . $loc['spawn']['y'];
    check("$code: the spawn is open floor", isset($blocked[$spawn]), false);
    $seen = [$spawn => true];
    $queue = [[$loc['spawn']['x'], $loc['spawn']['y']]];
    while ($queue) {
        [$x, $y] = array_shift($queue);
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $nx = $x + $dx;
            $ny = $y + $dy;
            $k = "$nx,$ny";
            if ($nx >= 0 && $ny >= 0 && $nx < $cols && $ny < $rows && !isset($blocked[$k]) && !isset($seen[$k])) {
                $seen[$k] = true;
                $queue[] = [$nx, $ny];
            }
        }
    }
    $near = function (int $x, int $y, int $h) use ($seen): bool { // a walkable tile next to the spot
        for ($j = -1; $j <= $h; $j++) {
            foreach ([[$x - 1, $y + $j], [$x + 1, $y + $j], [$x, $y + $j]] as [$ax, $ay]) {
                if (isset($seen["$ax,$ay"])) {
                    return true;
                }
            }
        }
        return false;
    };
    $unreachable = array_values(array_filter($loc['slots'], fn ($s) => !$near($s['x'], $s['y'], $s['h'])));
    check("$code: every spot can be walked up to", array_map(fn ($s) => "{$s['kind']} {$s['x']},{$s['y']}", $unreachable), []);
    $closed = array_values(array_filter($loc['rooms'] ?? [], function ($r) use ($seen): bool {
        for ($x = $r['x']; $x < $r['x'] + $r['w']; $x++) {
            for ($y = $r['y']; $y < $r['y'] + $r['h']; $y++) {
                if (isset($seen["$x,$y"])) {
                    return false;
                }
            }
        }
        return true;
    }));
    check("$code: every room can be walked into", array_column($closed, 'name'), []);
    $onWalls = array_values(array_filter($loc['slots'], fn ($s) => isset(Layout::wallTiles($code)[$s['x'] . ',' . $s['y']])));
    check("$code: no spot is inside a wall", count($onWalls), 0);
}
check('the datacentre has its four rooms', array_column(Layout::location('datacentre')['rooms'], 'name'), ['Data hall', 'Power room', 'Operations centre', 'Meeting room']);
check('the datacentre still has 48 rack spots', count(array_filter(Layout::location('datacentre')['slots'], fn ($s) => $s['kind'] === 'rack')), 48);
check('the office still has 12 rack spots', count(array_filter(Layout::location('office')['slots'], fn ($s) => $s['kind'] === 'rack')), 12);

echo "Moving old gear\n";
$tag = 'zt' . random_int(1000, 9999);
Db::exec('INSERT INTO users (username, email, password_hash, role, credits, level, location, pos_x, pos_y) VALUES (?, ?, ?, "student", 1000, 9, "datacentre", 20, 3)',
    ["{$tag}_map", "{$tag}_map@netforge.test", password_hash('x-password-1', PASSWORD_DEFAULT)]);
$uid = (int) Db::pdo()->lastInsertId();
$dev = function (string $code, int $x, int $y, ?int $u = null) use ($uid): int {
    Db::exec('INSERT INTO devices (user_id, item_code, label, location, tile_x, tile_y, rack_u) VALUES (?, ?, ?, "datacentre", ?, ?, ?)', [$uid, $code, strtoupper($code) . '-T', $x, $y, $u]);
    return (int) Db::pdo()->lastInsertId();
};
$qty = fn (string $code) => (int) (Db::one('SELECT quantity FROM inventory WHERE user_id = ? AND item_code = ?', [$uid, $code])['quantity'] ?? 0);
try {
    $pc = $dev('pc', 4, 16);                 // on the old desk spot
    $ap = $dev('ap_pt', 23, 3);              // where the switchgear now stands
    $phone = $dev('smartphone', 25, 6);      // open floor in the power room: stays
    Db::exec('INSERT INTO cables (user_id, cable_code, a_device_id, a_port, b_device_id, b_port, status) VALUES (?, "copper", ?, "Fa0", ?, "Port0", "up")', [$uid, $pc, $ap]);
    Db::exec('INSERT INTO user_furniture (user_id, item_code, location, tile_x, tile_y) VALUES (?, "server_rack", "datacentre", 20, 9)', [$uid]); // on the new wall
    $srv = $dev('server', 20, 9, 1);
    MapMigration::run($uid);
    $pcAt = Db::one('SELECT tile_x, tile_y FROM devices WHERE id = ?', [$pc]);
    check('the PC moved to the desk in the operations centre', [(int) $pcAt['tile_x'], (int) $pcAt['tile_y']], [25, 10]);
    check('the access point under the switchgear went back', [Db::one('SELECT 1 x FROM devices WHERE id = ?', [$ap]), $qty('ap_pt')], [null, 1]);
    check('its cable came back too', $qty('copper'), 1);
    check('the phone on open floor stayed', Db::one('SELECT 1 x FROM devices WHERE id = ?', [$phone]) !== null, true);
    check('the bought rack on the new wall went back, server and all', [$qty('server_rack'), $qty('server'), Db::one('SELECT 1 x FROM devices WHERE id = ?', [$srv])], [1, 1, null]);
    $u = Db::one('SELECT pos_x, pos_y FROM users WHERE id = ?', [$uid]);
    check('a student inside a new wall moved to the entrance', [(int) $u['pos_x'], (int) $u['pos_y']], [10, 17]);
    check('the student is told', str_contains((string) Db::one('SELECT message FROM event_log WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid])['message'], 'new rooms'), true);
    Db::exec('UPDATE devices SET tile_x = 23, tile_y = 3 WHERE id = ?', [$phone]);
    MapMigration::run($uid);
    check('it only runs once', Db::one('SELECT 1 x FROM devices WHERE id = ?', [$phone]) !== null, true);
} finally {
    Db::exec('DELETE FROM users WHERE id = ?', [$uid]);
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
