<?php
declare(strict_types=1);

// Run: php tests/SecurityTest.php   (needs the netforge database with schema_security.sql)
// Fixes from the security review: sign-in limits, reset links that can't follow a forged
// Host header, sessions that end when an account is switched off or left idle, and CSV
// cells Excel won't run as formulas. Makes and removes its own test data.

$_SERVER['REQUEST_METHOD'] = 'CLI';
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(1, 254); // a test address, so other runs don't interfere
require __DIR__ . '/../src/bootstrap.php';
ob_start(); // hold the output, so sign-in can renew the session ID like it does in a real request

use NetForge\Accounts;
use NetForge\Analytics\Export;
use NetForge\Auth;
use NetForge\Config;
use NetForge\Db;
use NetForge\GameError;

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

$tag = 'zs' . random_int(1000, 9999);
Db::exec('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, "student")',
    [$tag, "$tag@netforge.test", password_hash('right-password-1', PASSWORD_DEFAULT)]);
$uid = (int) Db::pdo()->lastInsertId();
$config = require (is_file(NF_ROOT . '/config/config.php') ? NF_ROOT . '/config/config.php' : NF_ROOT . '/config/config.example.php');

try {
    echo "CSV cells (finding 6)\n";
    check('formula is neutralised', Export::cell('=HYPERLINK("http://x")'), "'=HYPERLINK(\"http://x\")");
    check('@ and - text too', [Export::cell('@SUM(A1)'), Export::cell('-cmd')], ["'@SUM(A1)", "'-cmd"]);
    check('real numbers untouched', [Export::cell('-5'), Export::cell('+12'), Export::cell(-5), Export::cell('45%')], ['-5', '+12', -5, '45%']);
    check('null becomes empty', Export::cell(null), '');

    echo "Sign-in limit (finding 3)\n";
    for ($i = 0; $i < Auth::MAX_FAILS_PER_NAME - 1; $i++) {
        Auth::attempt($tag, 'wrong');
    }
    check('not locked after ' . (Auth::MAX_FAILS_PER_NAME - 1) . ' failures', Auth::lockedOut($tag), false);
    Auth::attempt($tag, 'wrong');
    check('locked after ' . Auth::MAX_FAILS_PER_NAME, Auth::lockedOut($tag), true);
    check('the lock ignores letter case', Auth::lockedOut(strtoupper($tag)), true);
    for ($i = 0; $i < Auth::MAX_FAILS_PER_NAME; $i++) {
        Auth::attempt("{$tag}_nobody", 'wrong');
    }
    check('names with no account lock the same way', Auth::lockedOut("{$tag}_nobody"), true);
    Db::exec('DELETE FROM login_failures WHERE ip = ?', [$_SERVER['REMOTE_ADDR']]);
    check('a success clears the failures', [Auth::attempt($tag, 'right-password-1') !== null, Auth::lockedOut($tag)], [true, false]);

    echo "Sessions end with the account (finding 2)\n";
    $_SESSION = ['uid' => $uid, 'seen' => time()];
    check('active account is signed in', (int) (Auth::current()['id'] ?? 0), $uid);
    Db::exec('UPDATE users SET active = 0 WHERE id = ?', [$uid]);
    check('switched-off account is signed out', Auth::current(), null);
    Db::exec('UPDATE users SET active = 1 WHERE id = ?', [$uid]);
    $_SESSION = ['uid' => $uid, 'seen' => time() - 3 * 3600];
    check('idle session is signed out', Auth::current(), null);

    echo "Reset links and the Host header (finding 1)\n";
    $_SERVER['HTTP_HOST'] = 'attacker.example';
    $mailNoBase = array_replace_recursive($config, ['mail' => ['transport' => 'mail'], 'app' => ['base_url' => '']]);
    Config::load($mailNoBase);
    $err = function (string $who): string {
        try {
            Accounts::requestReset($who);
            return 'sent';
        } catch (GameError $e) {
            return $e->getMessage();
        }
    };
    $forReal = $err($tag);
    check('no email is sent without app.base_url', str_starts_with($forReal, 'Password reset by email isn’t set up'), true);
    check('…and the answer is the same for an unknown name', $err("{$tag}_nobody"), $forReal);
    check('no link was made', (int) Db::one('SELECT COUNT(*) n FROM password_resets WHERE user_id = ?', [$uid])['n'], 0);
    Config::load(array_replace_recursive($config, ['app' => ['base_url' => 'https://netforge.example.edu/']]));
    check('with base_url set, links ignore the Host header', Accounts::createResetLink($uid)['url'] !== '' && str_starts_with(Accounts::createResetLink($uid)['url'], 'https://netforge.example.edu/reset.php?token='), true);
} finally {
    Config::load($config);
    Db::exec('DELETE FROM login_failures WHERE ip = ? OR username LIKE ?', [$_SERVER['REMOTE_ADDR'], "$tag%"]);
    Db::exec('DELETE FROM users WHERE id = ?', [$uid]);
}

ob_end_flush();
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
