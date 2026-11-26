<?php
declare(strict_types=1);

// Run: php tests/StaffTest.php   (needs the netforge database with schema_staff.sql)
// Hiring from resumes, desktops that need someone at them, what each role does and what it costs.
// Makes its own student and removes it after.

require __DIR__ . '/../src/bootstrap.php';

use NetForge\Db;
use NetForge\Facility;
use NetForge\GameError;
use NetForge\Progress\Income;
use NetForge\Staff;

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
Db::exec('INSERT INTO users (username, email, password_hash, role, credits, level, location) VALUES (?, ?, ?, "student", 1000, 9, "garage")',
    ["{$tag}_staff", "{$tag}_staff@netforge.test", password_hash('x-password-1', PASSWORD_DEFAULT)]);
$uid = (int) Db::pdo()->lastInsertId();
$dev = function (string $code, string $loc, int $x, int $y, string $label) use ($uid): int {
    Db::exec('INSERT INTO devices (user_id, item_code, label, location, tile_x, tile_y) VALUES (?, ?, ?, ?, ?, ?)', [$uid, $code, $label, $loc, $x, $y]);
    return (int) Db::pdo()->lastInsertId();
};
$link = fn (int $a, string $ap, int $b, string $bp) => Db::exec(
    'INSERT INTO cables (user_id, cable_code, a_device_id, a_port, b_device_id, b_port, status) VALUES (?, "copper", ?, ?, ?, ?, "up")', [$uid, $a, $ap, $b, $bp]);
$staff = function (string $role, int $level, int $pct, string $loc) use ($uid): int {
    Db::exec('INSERT INTO staff (user_id, name, role, level, salary_pct, location, resume) VALUES (?, ?, ?, ?, ?, ?, "{}")', [$uid, "T $role", $role, $level, $pct, $loc]);
    return (int) Db::pdo()->lastInsertId();
};
$earns = fn (int $id) => round(Income::summary($uid)['byDevice'][$id] ?? 0.0, 2);
$pcRate = (int) Db::one('SELECT income_per_sec r FROM item_types WHERE code = "pc"')['r'];

try {
    echo "Resumes\n";
    $cands = Staff::candidates($uid);
    check('three resumes are waiting', count($cands), 3);
    $earnerEvery = true;
    for ($k = 0; $k < 12; $k++) { // reject the earners: one always comes back
        foreach (Staff::candidates($uid) as $c) {
            if ($c['role'] === 'earner') {
                Staff::reject($uid, $c['id']);
            }
        }
        $earnerEvery = $earnerEvery && in_array('earner', array_column(Staff::candidates($uid), 'role'), true);
    }
    check('there is always an earner among them', $earnerEvery, true);
    $cands = Staff::candidates($uid);
    check('each says what they do and what they cost', $cands[0]['does'] !== '' && str_contains($cands[0]['costs'], '%'), true);
    check('no hiring in the garage', str_contains(err(fn () => Staff::hire($uid, $cands[0]['id'])), 'office or the datacentre'), true);
    Staff::reject($uid, $cands[0]['id']);
    check('a rejected resume is replaced', [count(Staff::candidates($uid)), in_array($cands[0]['id'], array_column(Staff::candidates($uid), 'id'), true)], [3, false]);
    Db::exec('UPDATE users SET location = "office" WHERE id = ?', [$uid]);
    $hire = Staff::candidates($uid)[0];
    Staff::hire($uid, $hire['id']);
    check('hiring in the office adds them to the team', array_column(Staff::team($uid), 'name'), [$hire['name']]);
    check('and a new resume arrives', count(Staff::candidates($uid)), 3);
    Db::exec('DELETE FROM staff WHERE user_id = ?', [$uid]);

    echo "Desktops need someone at them\n";
    $gIsp = $dev('isp_box', 'garage', 10, 1, 'ISP-G');
    $gPc1 = $dev('pc', 'garage', 2, 2, 'PC-G1');
    $gPc2 = $dev('pc', 'garage', 3, 2, 'PC-G2');
    $link($gIsp, 'LAN', $gPc1, 'Fa0');
    check('in the garage you work the first desktop yourself', $earns($gPc1), (float) $pcRate);
    $link($gPc2, 'Fa0', $dev('switch8', 'garage', 6, 2, 'SW-G'), 'Fa0/1');
    check('a second garage desktop has no one at it', $earns($gPc2), 0.0);
    $oIsp = $dev('isp_box', 'office', 14, 1, 'ISP-O');
    $oPc = $dev('pc', 'office', 4, 3, 'PC-O');
    $link($oIsp, 'LAN', $oPc, 'Fa0');
    check('an office desktop earns nothing with no one at it', $earns($oPc), 0.0);
    $e = $staff('earner', 1, 50, 'office');
    check('a junior earner works it at x1', $earns($oPc), (float) $pcRate);
    check('and takes 50% of it as salary', round(Income::summary($uid)['pay'][$e], 2), round($pcRate * 0.5, 2));
    Db::exec('UPDATE staff SET level = 3 WHERE id = ?', [$e]);
    check('a mid-level earner makes it earn x1.5', $earns($oPc), round($pcRate * 1.5, 2));

    echo "The other roles\n";
    $a = $staff('admin', 2, 50, 'office');
    check('a level-2 admin lifts the office 6%', $earns($oPc), round($pcRate * 1.5 * 1.06, 2));
    check('and takes half of what they add', round(Income::summary($uid)['pay'][$a], 2), round($pcRate * 1.5 * 0.06 * 0.5, 2));
    check('the admin does nothing for the garage', $earns($gPc1), (float) $pcRate);
    $tempBefore = Facility::all($uid)['office']['temp'];
    $t = $staff('technician', 3, 3, 'office');
    check('a technician cools the office', Facility::all($uid)['office']['temp'] < $tempBefore, true);
    check('for 3% of the office’s income', round(Income::summary($uid)['pay'][$t], 2), round($pcRate * 1.5 * 1.06 * 0.03, 2));
    $shieldBefore = Income::shield($uid);
    $staff('security', 2, 2, 'office');
    check('a level-2 analyst blocks 10% more of an attack', Income::shield($uid) - $shieldBefore, 10);
    $sum = Income::summary($uid);
    check('salaries come off what you take home', round($sum['perSec'], 2), round($sum['gross'] - $sum['bill'] - $sum['salaries'], 2));

    echo "Room for staff\n";
    for ($i = Db::one('SELECT COUNT(*) n FROM staff WHERE user_id = ? AND location = "office"', [$uid])['n']; $i < Staff::PLACES['office']; $i++) {
        $staff('earner', 1, 50, 'office');
    }
    check('a full office refuses another hire', str_contains(err(fn () => Staff::hire($uid, Staff::candidates($uid)[0]['id'])), 'full'), true);
    Staff::fire($uid, $e);
    check('letting someone go frees a place', count(array_filter(Staff::team($uid), fn ($s) => $s['location'] === 'office')), Staff::PLACES['office'] - 1);
} finally {
    Db::exec('DELETE FROM users WHERE id = ?', [$uid]);
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
