<?php
declare(strict_types=1);

// Run: php tests/ContentTest.php
// The CCNA question bank (database/content/ccna.php): every question passes the editor's
// checks, the bank follows the blueprint weights, and every answer key actually marks as
// correct when served the way the game serves it (shuffled). No database needed.

$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../src/bootstrap.php';

use NetForge\Content\Bank;
use NetForge\Content\QuestionInput;
use NetForge\Learning\Grader;

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

$bank = Bank::read();

echo "Checks\n";
$errors = Bank::errors($bank);
check('every question passes the editor’s checks', $errors, []);

echo "Blueprint weights\n";
$spread = Bank::spread($bank);
$total = array_sum($spread);
check('at least 100 questions', $total >= 100, true);
foreach (['Network Fundamentals' => 20, 'Network Access' => 20, 'IP Connectivity' => 25, 'IP Services' => 10, 'Security Fundamentals' => 15, 'Automation and Programmability' => 10] as $domain => $pct) {
    check("$domain is $pct%", (int) round(($spread[$domain] ?? 0) / $total * 100), $pct);
}
check('one world per area: Garage, Office, Datacentre', array_keys($bank), [0, 1, 2]);

echo "Answer keys (each question served 5 times, shuffled)\n";
$bad = [];
foreach ($bank as $world => $levels) {
    foreach ($levels as $l) {
        foreach ($l['questions'] as $q) {
            $fields = (new ReflectionMethod(Bank::class, 'fields'))->invoke(null, $q);
            $r = QuestionInput::parse($fields)['row'];
            $row = ['id' => 0, 'type' => $r['type'], 'topic' => $r['topic'], 'prompt' => $r['prompt'],
                'payload' => json_encode($r['payload']), 'answer' => $r['answer'] === null ? null : json_encode($r['answer'])];
            for ($i = 0; $i < 5; $i++) {
                $s = Grader::serve($row);
                // answer exactly as a student who knows it would
                $answer = $r['type'] === 'fill' ? array_map(fn ($acc) => strtoupper($acc[0]), $s['key']) : $s['key'];
                if (!Grader::grade($r['type'], $s['view'], $s['key'], $answer)['correct']) {
                    $bad[] = $r['prompt'];
                    break;
                }
                // and a wrong answer must not pass (mcq: pick another option)
                if ($r['type'] === 'mcq' && Grader::grade('mcq', $s['view'], $s['key'], ($s['key'] + 1) % count($s['view']['options']))['correct']) {
                    $bad[] = 'wrong answer accepted: ' . $r['prompt'];
                    break;
                }
            }
        }
    }
}
check("all $total answer keys mark correctly", $bad, []);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
