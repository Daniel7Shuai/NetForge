<?php
declare(strict_types=1);

// Creates an account from the command line, e.g. the first lecturer or admin:
//   php tools/create-user.php <username> <password> <student|lecturer|admin>
// Public sign-up only ever creates students.

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../src/bootstrap.php';

[$script, $username, $password, $role, $email] = $argv + [null, null, null, 'student', null];
if (!$username || !$password) {
    fwrite(STDERR, "Usage: php tools/create-user.php <username> <password> <student|lecturer|admin> [email]\n");
    exit(1);
}
$error = NetForge\Auth::register($username, $password, $role, false, $email);
if ($error !== null) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}
echo "Created $role account '$username'.\n";
