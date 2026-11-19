<?php
declare(strict_types=1);

// Loads the CCNA question bank (database/content/ccna.php) into the database.
// Run after importing database/schema_content.sql:
//   C:\xampp\php\php.exe tools/seed-content.php
// Checks every question first and changes nothing if any has a problem. Safe to run again.

$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../src/bootstrap.php';

use NetForge\Content\Bank;

$bank = Bank::read();
$errors = Bank::errors($bank);
if ($errors) {
    fwrite(STDERR, count($errors) . " problem(s), nothing was loaded:\n  " . implode("\n  ", $errors) . "\n");
    exit(1);
}

$n = Bank::apply($bank);
$spread = Bank::spread($bank);
$total = array_sum($spread);
echo "Loaded the CCNA bank: {$n['levels']} new level(s), {$n['added']} new question(s), {$n['updated']} refreshed.\n";
echo "$total questions by domain:\n";
foreach ($spread as $domain => $count) {
    printf("  %-32s %3d  %2d%%\n", $domain, $count, round($count / $total * 100));
}
