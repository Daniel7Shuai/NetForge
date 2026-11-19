<?php
declare(strict_types=1);

// Run: php tests/QuestionInputTest.php
// The validator shared by the question editor and CSV import.

require __DIR__ . '/../src/Content/QuestionInput.php';

use NetForge\Content\QuestionInput as QI;

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
$base = ['prompt' => 'Q?', 'topic' => 'T', 'explanation' => 'E', 'rating' => '900'];

$r = QI::parse($base + ['type' => 'mcq', 'choices' => 'Switch|Laptop|Printer', 'answer' => 'switch']);
check('mcq by text', [$r['ok'], $r['row']['answer']], [true, ['correct' => 0]]);
$r = QI::parse($base + ['type' => 'mcq', 'choices' => "A\nB\nC", 'answer' => '3']);
check('mcq by number, newline list', $r['row']['answer'], ['correct' => 2]);
$r = QI::parse($base + ['type' => 'mcq', 'choices' => 'A|B', 'answer' => '5']);
check('mcq answer out of range rejected', $r['ok'], false);
$r = QI::parse($base + ['type' => 'mcq', 'choices' => 'A|a', 'answer' => '1']);
check('mcq duplicate options rejected', $r['ok'], false);

$r = QI::parse($base + ['type' => 'matching', 'choices' => 'LAN = small area | WAN = large area']);
check('matching pairs', $r['row']['payload'], ['left' => ['LAN', 'WAN'], 'right' => ['small area', 'large area']]);
$r = QI::parse($base + ['type' => 'matching', 'choices' => 'LAN small | WAN = large']);
check('matching without = rejected', $r['ok'], false);

$r = QI::parse($base + ['type' => 'ordering', 'choices' => 'enable|configure terminal|hostname S1']);
check('ordering', $r['row']['payload']['items'][2], 'hostname S1');

$r = QI::parse(['type' => 'fill', 'prompt' => 'The ___ provides, the ___ asks.', 'answer' => 'Server | client/host'] + $base);
check('fill blanks and alternatives', $r['row']['answer'], ['blanks' => [['server'], ['client', 'host']]]);
$r = QI::parse(['type' => 'fill', 'prompt' => 'One ___ only', 'answer' => 'a|b'] + $base);
check('fill blank count mismatch rejected', $r['ok'], false);

$r = QI::parse($base + ['type' => 'blitz', 'choices' => 'mode=dec2bin bits=8 seconds=15']);
check('blitz settings', $r['row']['payload'], ['mode' => 'dec2bin', 'bits' => 8, 'seconds' => 15]);
$r = QI::parse($base + ['type' => 'subnet', 'choices' => 'mode=network, min=24, max=30']);
check('subnet settings', $r['row']['payload'], ['mode' => 'network', 'minPrefix' => 24, 'maxPrefix' => 30]);
$r = QI::parse($base + ['type' => 'subnet', 'choices' => 'mode=network min=30 max=24']);
check('subnet min > max rejected', $r['ok'], false);

$r = QI::parse(['type' => 'quiz', 'prompt' => '', 'topic' => '', 'explanation' => '', 'rating' => 'hard']);
check('bad type, missing fields, bad rating: 5 errors', count($r['errors']), 5);
$r = QI::parse(['type' => 'mcq', 'choices' => 'A|B', 'answer' => '1', 'rating' => ''] + $base);
check('blank rating defaults to 1000', $r['row']['rating'], 1000);

// round trip: parse -> store -> format -> parse gives the same row
$orig = QI::parse($base + ['type' => 'matching', 'choices' => "A = 1\nB = 2"])['row'];
$stored = ['type' => 'matching', 'prompt' => 'Q?', 'topic' => 'T', 'rating' => 900, 'explanation' => 'E',
    'payload' => json_encode($orig['payload']), 'answer' => null];
check('format round trip', QI::parse(QI::format($stored))['row'], $orig);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
