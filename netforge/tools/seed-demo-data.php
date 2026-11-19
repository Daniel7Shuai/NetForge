<?php
declare(strict_types=1);

// DEVELOPMENT / DEMO ONLY. Creates six demo students with three weeks of backdated
// answers so the analytics pages (module 7.0) have something realistic to show:
//   demo_alice  strong, masters World 0          demo_ben   steady, about 70%
//   demo_chen   struggling: low accuracy, big review backlog
//   demo_dina   went quiet 12 days ago            demo_eli   joined 5 days ago, never answered
//   demo_faiz   started well, rating falling this week
// Run: php tools/seed-demo-data.php     (re-running replaces the demo students)
// All demo passwords: your-demo-password

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../src/bootstrap.php';

use NetForge\Auth;
use NetForge\Db;
use NetForge\Learning\Elo;

mt_srand(2026); // same data every run

Db::exec('DELETE FROM users WHERE username LIKE "demo\_%"');
$world = Db::one('SELECT id FROM worlds WHERE number = 0');
$questions = Db::all('SELECT id, rating FROM questions WHERE world_id = ? AND archived = 0', [$world['id']]);
if (!$questions) {
    exit("Import seed_learning.sql first.\n");
}

// name => [joined days ago, active from day, active to day (days ago), answers per active day, chance correct, late chance]
$plans = [
    'demo_alice' => [21, 20, 0, 6, 0.90, null],
    'demo_ben'   => [21, 19, 1, 4, 0.70, null],
    'demo_chen'  => [18, 17, 0, 5, 0.32, null],
    'demo_dina'  => [21, 20, 12, 5, 0.75, null],
    'demo_eli'   => [5, null, null, 0, 0, null],
    'demo_faiz'  => [20, 19, 0, 5, 0.85, 0.15], // after day 6 ago, accuracy drops to 15%
];

foreach ($plans as $name => [$joined, $fromDay, $toDay, $perDay, $p, $lateP]) {
    if ($err = Auth::register($name, 'your-demo-password', 'student', false)) {
        exit("$name: $err\n");
    }
    $uid = (int) Db::one('SELECT id FROM users WHERE username = ?', [$name])['id'];
    Db::exec('UPDATE users SET created_at = NOW() - INTERVAL ? DAY WHERE id = ?', [$joined, $uid]);
    if ($fromDay === null) {
        echo "$name: no activity\n";
        continue;
    }
    $rating = Elo::START_RATING;
    $credits = $xp = $n = $right = 0;
    for ($day = $fromDay; $day >= $toDay; $day--) {
        if (mt_rand(1, 10) <= 2) {
            continue; // some days off
        }
        $chance = ($lateP !== null && $day <= 6) ? $lateP : $p;
        for ($i = 0; $i < $perDay; $i++) {
            $q = $questions[mt_rand(0, count($questions) - 1)];
            $ok = mt_rand() / mt_getrandmax() < $chance;
            $u = Elo::update($rating, (int) $q['rating'], $ok);
            $rw = Elo::rewards($rating, $u['student']);
            Db::exec(
                'INSERT INTO attempts (user_id, question_id, mode, correct, rating_before, rating_after, credits, xp, seconds, created_at)
                 VALUES (?, ?, "practice", ?, ?, ?, ?, ?, ?, NOW() - INTERVAL ? DAY - INTERVAL ? MINUTE)',
                [$uid, $q['id'], (int) $ok, $rating, $u['student'], $rw['credits'], $rw['xp'], mt_rand(5, 40), $day, mt_rand(0, 600)]
            );
            if (!$ok) {
                Db::exec(
                    'INSERT INTO audit_queue (user_id, question_id, stage, due_on) VALUES (?, ?, 1, CURDATE() - INTERVAL ? DAY)
                     ON DUPLICATE KEY UPDATE due_on = VALUES(due_on)',
                    [$uid, $q['id'], max(1, $day - 1)]
                );
            }
            $rating = $u['student'];
            $credits += $rw['credits'];
            $xp += $rw['xp'];
            $n++;
            $right += (int) $ok;
        }
    }
    if (in_array($name, ['demo_alice', 'demo_ben'], true)) {
        // steady students keep up with their reviews: nothing overdue
        Db::exec('UPDATE audit_queue SET stage = 2, due_on = CURDATE() + INTERVAL 2 DAY WHERE user_id = ?', [$uid]);
    }
    $mastered = $name === 'demo_alice';
    Db::exec(
        'INSERT INTO world_progress (user_id, world_id, rating, answered, exam_best, mastered_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$uid, $world['id'], $rating, $n, $mastered ? 90 : null, $mastered ? date('Y-m-d H:i:s', strtotime('-4 days')) : null]
    );
    Db::exec('UPDATE users SET credits = ?, xp = ?, level = ? WHERE id = ?', [$credits, $xp, intdiv($xp, 1000) + 1, $uid]);
    printf("%s: %d answers, %d%% correct, rating %d\n", $name, $n, $n ? round($right / $n * 100) : 0, $rating);
}
// Two demo guilds. This week's correct answers count towards their raid tally (module 6.0).
Db::exec('DELETE FROM guilds WHERE tag IN ("PKT", "NOC")');
$week = NetForge\Social\Social::weekStart();
foreach ([
    ['Packet Pushers', 'PKT', 'No packet left behind', ['demo_alice', 'demo_ben', 'demo_chen']],
    ['Night Shift', 'NOC', 'We keep the lights blinking', ['demo_dina', 'demo_faiz']],
] as [$gName, $gTag, $motto, $members]) {
    Db::exec('INSERT INTO guilds (name, tag, motto, created_at) VALUES (?, ?, ?, NOW() - INTERVAL 14 DAY)', [$gName, $gTag, $motto]);
    $gid = (int) Db::pdo()->lastInsertId();
    foreach ($members as $i => $name) {
        $uid = (int) Db::one('SELECT id FROM users WHERE username = ?', [$name])['id'];
        Db::exec('INSERT INTO guild_members (user_id, guild_id, role, joined_at) VALUES (?, ?, ?, NOW() - INTERVAL 14 DAY)', [$uid, $gid, $i === 0 ? 'leader' : 'member']);
        Db::exec('INSERT INTO guild_feed (guild_id, user_id, kind, message, created_at) VALUES (?, ?, "join", ?, NOW() - INTERVAL 14 DAY)', [$gid, $uid, $name . ($i === 0 ? ' founded the guild.' : ' joined.')]);
        Db::exec(
            'INSERT INTO raid_contributions (guild_id, user_id, week_start, world_id, correct)
             SELECT ?, a.user_id, ?, q.world_id, COUNT(*) FROM attempts a JOIN questions q ON q.id = a.question_id
              WHERE a.user_id = ? AND a.correct = 1 AND a.created_at >= ? GROUP BY q.world_id',
            [$gid, $week, $uid, $week . ' 00:00:00']
        );
    }
    $total = (int) Db::one('SELECT COALESCE(SUM(correct), 0) n FROM raid_contributions WHERE guild_id = ? AND week_start = ?', [$gid, $week])['n'];
    echo "[$gTag] $gName: " . count($members) . " members, $total correct answers this week\n";
}
Db::exec('INSERT INTO guild_feed (guild_id, user_id, kind, message) SELECT g.id, u.id, "share", "demo_alice: Mastered World 0: Networking basics." FROM guilds g, users u WHERE g.tag = "PKT" AND u.username = "demo_alice"');

echo "Done. Demo passwords: your-demo-password\n";
