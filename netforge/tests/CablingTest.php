<?php
declare(strict_types=1);

// Run: php tests/CablingTest.php
// Packet Tracer-style cable rules: which cables plug into which sockets,
// and which combinations give a working link.

require __DIR__ . '/../src/Rules.php';

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

$host = ['kind' => 'copper', 'role' => 'mdi'];   // laptop Eth0, router WAN
$sw = ['kind' => 'copper', 'role' => 'mdix'];    // switch port, home router LAN, ISP box LAN
$fib = ['kind' => 'fiber'];
$con = ['kind' => 'console'];

echo "Fits the socket\n";
check('copper into copper', Rules::cableFits('copper', 'copper'), true);
check('crossover into copper', Rules::cableFits('crossover', 'copper'), true);
check('copper into fiber', Rules::cableFits('copper', 'fiber'), false);
check('fiber into fiber', Rules::cableFits('fiber', 'fiber'), true);
check('console into console', Rules::cableFits('console', 'console'), true);
check('console into copper', Rules::cableFits('console', 'copper'), false);

echo "Link comes up\n";
check('straight: laptop to router LAN', Rules::linkProblem('copper', $host, $sw), null);
check('straight: ISP LAN to router WAN', Rules::linkProblem('copper', $sw, $host), null);
check('straight: switch to switch is down', Rules::linkProblem('copper', $sw, $sw) !== null, true);
check('straight: laptop to laptop is down', Rules::linkProblem('copper', $host, $host) !== null, true);
check('crossover: switch to switch', Rules::linkProblem('crossover', $sw, $sw), null);
check('crossover: laptop to laptop', Rules::linkProblem('crossover', $host, $host), null);
check('crossover: laptop to switch is down', Rules::linkProblem('crossover', $host, $sw) !== null, true);
check('fiber always up', Rules::linkProblem('fiber', $fib, $fib), null);
check('console always up', Rules::linkProblem('console', $con, $con), null);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
