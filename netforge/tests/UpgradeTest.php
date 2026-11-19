<?php
declare(strict_types=1);

// Run: php tests/UpgradeTest.php   (needs the netforge database with schema_upgrades.sql)
// Equipment tiers (FR-4.3): what each upgrade costs, the level it needs, and the income it adds.

require __DIR__ . '/../src/bootstrap.php';

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

echo "Income per tier\n";
check('tier 1 earns x1', Rules::tierIncome(1), 1.0);
check('tier 2 earns x1.5', Rules::tierIncome(2), 1.5);
check('tier 3 earns x2', Rules::tierIncome(3), 2.0);

echo "Upgrade cost and level (home router: 1,500 credits, level 1)\n";
check('tier 1 -> 2', Rules::upgradeInfo('home_router', 1, 3), ['tier' => 2, 'cost' => 900, 'level' => 3]);
check('tier 2 -> 3', Rules::upgradeInfo('home_router', 2, 3), ['tier' => 3, 'cost' => 1800, 'level' => 5]);
check('no upgrade past max tier', Rules::upgradeInfo('home_router', 3, 3), null);

echo "Firewall (100,000 credits, level 4)\n";
check('tier 1 -> 2', Rules::upgradeInfo('firewall', 1, 3), ['tier' => 2, 'cost' => 60000, 'level' => 6]);
check('tier 2 -> 3', Rules::upgradeInfo('firewall', 2, 3), ['tier' => 3, 'cost' => 120000, 'level' => 8]);

echo "Items not sold in the shop\n";
check('laptop is priced like a 1,000-credit device', Rules::upgradeInfo('laptop', 1, 3), ['tier' => 2, 'cost' => 600, 'level' => 3]);
check('fixed ISP panel has one tier', Rules::upgradeInfo('isp_fiber', 1, 1), null);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
