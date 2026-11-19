<?php
declare(strict_types=1);

// Run: php tests/AccountsTest.php   (needs the netforge database with schema_users.sql)
// Module 1.0: input checks, one-time reset links, and the admin rules that stop
// anyone locking every admin out. Creates its own accounts and removes them after.

$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../src/bootstrap.php';

use NetForge\Accounts;
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
/** The error message a call throws, or 'no error'. */
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
$made = [];
$mk = function (string $name, string $role) use ($tag, &$made): int {
    Db::exec('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)',
        ["{$tag}_$name", "{$tag}_$name@netforge.test", password_hash('old-password-1', PASSWORD_DEFAULT), $role]);
    return $made[] = (int) Db::pdo()->lastInsertId();
};

try {
    echo "Input checks\n";
    check('short username refused', str_starts_with(err(fn () => Accounts::checkUsername('ab')), 'Use 3 to 32'), true);
    check('email is lower-cased', Accounts::checkEmail(' Name@Mail.APU.edu.my '), 'name@mail.apu.edu.my');
    check('bad email refused', str_starts_with(err(fn () => Accounts::checkEmail('not-an-email')), 'Enter a valid email'), true);
    check('7-character password refused', str_starts_with(err(fn () => Accounts::checkPassword('1234567')), 'Use at least 8'), true);
    check('blank display name falls back to username', Accounts::checkDisplayName('   '), null);
    check('display name spaces tidied', Accounts::checkDisplayName('  Ada   L  '), 'Ada L');
    check('display name with < refused', str_starts_with(err(fn () => Accounts::checkDisplayName('<b>hi</b>')), 'Keep the display name'), true);
    check('numeric display name still a string', Accounts::name(['display_name' => 123, 'username' => 'x']), '123');

    echo "Reset links (FR-1.3)\n";
    $stu = $mk('stu', 'student');
    $link = Accounts::createResetLink($stu);
    $token = substr($link['url'], strpos($link['url'], 'token=') + 6);
    check('fresh link is valid', Accounts::tokenUser($token)['id'] ?? null, $stu);
    check('only the hash is stored', Db::one('SELECT 1 x FROM password_resets WHERE token_hash = ?', [$token]), null);
    check('mismatched passwords refused', err(fn () => Accounts::resetPassword($token, 'new-password-1', 'other')), 'The two passwords don’t match.');
    Accounts::resetPassword($token, 'new-password-1', 'new-password-1');
    check('new password works', password_verify('new-password-1', Db::one('SELECT password_hash h FROM users WHERE id = ?', [$stu])['h']), true);
    check('link works only once', Accounts::tokenUser($token), null);
    $second = Accounts::createResetLink($stu);
    $third = Accounts::createResetLink($stu);
    $t2 = substr($second['url'], strpos($second['url'], 'token=') + 6);
    $t3 = substr($third['url'], strpos($third['url'], 'token=') + 6);
    check('a newer link cancels the older one', Accounts::tokenUser($t2), null);
    Db::exec('UPDATE password_resets SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash = ?', [hash('sha256', $t3)]);
    check('expired link refused', Accounts::tokenUser($t3), null);
    check('junk token refused', Accounts::tokenUser('nope'), null);

    echo "Password change (FR-1.4)\n";
    check('wrong current password refused', err(fn () => Accounts::changePassword($stu, 'wrong', 'another-pass-1', 'another-pass-1')), 'Your current password isn’t right.');
    Accounts::changePassword($stu, 'new-password-1', 'another-pass-1', 'another-pass-1');
    check('password changed', password_verify('another-pass-1', Db::one('SELECT password_hash h FROM users WHERE id = ?', [$stu])['h']), true);

    echo "Admin rules (FR-1.5)\n";
    $a1 = $mk('a1', 'admin');
    $a2 = $mk('a2', 'admin');
    check('admin can’t demote themselves', str_starts_with(err(fn () => Accounts::update($a1, $a1, '', '', 'student')), 'You can’t change the role of your own'), true);
    check('admin can’t deactivate themselves', str_starts_with(err(fn () => Accounts::setActive($a1, $a1, false)), 'You can’t deactivate your own'), true);
    // pretend these two are the only active admins
    $others = array_column(Db::all('SELECT id FROM users WHERE role = "admin" AND active = 1 AND id NOT IN (?, ?)', [$a1, $a2]), 'id');
    foreach ($others as $o) {
        Db::exec('UPDATE users SET active = 0 WHERE id = ?', [$o]);
    }
    try {
        Accounts::setActive($a1, $a2, false);
        check('one admin can switch off another', (int) Db::one('SELECT active FROM users WHERE id = ?', [$a2])['active'], 0);
        check('…but not the last active admin', str_starts_with(err(fn () => Accounts::setActive($a2, $a1, false)), 'That’s the last active admin'), true);
    } finally {
        foreach ($others as $o) {
            Db::exec('UPDATE users SET active = 1 WHERE id = ?', [$o]);
        }
    }
    $link = Accounts::createResetLink($stu, $a1);
    $t4 = substr($link['url'], strpos($link['url'], 'token=') + 6);
    Accounts::setActive($a1, $stu, false);
    check('deactivating cancels reset links', Accounts::tokenUser($t4), null);
    check('changes are logged', count(Accounts::history($stu)) >= 1, true);
    Accounts::update($a1, $stu, 'Stu Dent', "{$tag}_new@netforge.test", 'lecturer');
    check('admin edit saved', Db::one('SELECT display_name, role FROM users WHERE id = ?', [$stu]), ['display_name' => 'Stu Dent', 'role' => 'lecturer']);
    check('duplicate email refused', err(fn () => Accounts::update($a1, $a2, '', "{$tag}_new@netforge.test", 'admin')), 'That email already has an account.');
} finally {
    foreach ($made as $id) {
        Db::exec('DELETE FROM users WHERE id = ?', [$id]);
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
