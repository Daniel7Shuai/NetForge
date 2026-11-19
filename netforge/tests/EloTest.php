<?php
declare(strict_types=1);

// Run: php tests/EloTest.php
// Hand-calculated cases for objective 3 ("rating updates match hand-calculated
// updates in at least ten unit test cases").

require __DIR__ . '/../src/Learning/Elo.php';
require __DIR__ . '/../src/Learning/Generators.php';

use NetForge\Learning\Elo;
use NetForge\Learning\Generators;

$passed = 0;
$failed = 0;
function check(string $name, mixed $got, mixed $want): void
{
    global $passed, $failed;
    $ok = is_float($want) ? abs($got - $want) < 0.0005 : $got === $want;
    if ($ok) {
        $passed++;
        echo "  ok    $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
    }
}

echo "Elo\n";
// 1. equal ratings -> 50%
check('expected, equal ratings', Elo::expected(1000, 1000), 0.5);
// 2. question 400 above student -> 1/(1+10) = 0.0909
check('expected, question +400', Elo::expected(1000, 1400), 0.0909);
// 3. question 200 below student -> 1/(1+10^-0.5) = 0.7597
check('expected, question -200', Elo::expected(1000, 800), 0.7597);
// 4. K factor bands
check('K below 1000', Elo::kFactor(800), 40);
check('K 1000-1199', Elo::kFactor(1100), 32);
check('K 1200-1399', Elo::kFactor(1250), 24);
check('K 1400+', Elo::kFactor(1500), 16);
// 5. 800 vs 800, correct: 800 + 40*(1-0.5) = 820; question 800 - 16*0.5 = 792
$u = Elo::update(800, 800, true);
check('student 800 v 800 correct', $u['student'], 820);
check('question 800 v 800 correct', $u['question'], 792);
// 6. 800 vs 800, wrong: 800 + 40*(0-0.5) = 780; question 808
$u = Elo::update(800, 800, false);
check('student 800 v 800 wrong', $u['student'], 780);
check('question 800 v 800 wrong', $u['question'], 808);
// 7. 1000 vs 1400 correct: E=0.0909, 1000 + 32*0.9091 = 1029.09 -> 1029
check('upset win 1000 v 1400', Elo::update(1000, 1400, true)['student'], 1029);
// 8. 1100 vs 700 correct (easy question): E=0.9091, 1100 + 32*0.0909 = 1102.9 -> 1103
check('easy win 1100 v 700', Elo::update(1100, 700, true)['student'], 1103);
// 9. 1500 vs 1500 wrong: 1500 + 16*(-0.5) = 1492
check('high rating loss', Elo::update(1500, 1500, false)['student'], 1492);
// 10. tiers
check('tier 999', Elo::tier(999), 'Bronze');
check('tier 1000', Elo::tier(1000), 'Silver');
check('tier 1250', Elo::tier(1250), 'Gold');
check('tier 1400', Elo::tier(1400), 'Platinum');
// 11. rewards only for gains
check('rewards for +20: XP only, no credits', Elo::rewards(800, 820), ['credits' => 0, 'xp' => 100]);
check('no rewards for a loss', Elo::rewards(800, 780), ['credits' => 0, 'xp' => 0]);

echo "Subnet generator\n";
// 12. 192.168.10.77/26 -> network .64, broadcast .127, 62 hosts, mask .192
check('network of .77/26', Generators::subnetAnswer('network', '192.168.10.77', 26), '192.168.10.64');
check('broadcast of .77/26', Generators::subnetAnswer('broadcast', '192.168.10.77', 26), '192.168.10.127');
check('hosts in /26', Generators::subnetAnswer('hosts', '192.168.10.77', 26), '62');
check('mask of /26', Generators::subnetAnswer('mask', '192.168.10.77', 26), '255.255.255.192');
check('network of 172.16.77.9/20', Generators::subnetAnswer('network', '172.16.77.9', 20), '172.16.64.0');
check('hosts in /30', Generators::subnetAnswer('hosts', '10.0.0.1', 30), '2');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
