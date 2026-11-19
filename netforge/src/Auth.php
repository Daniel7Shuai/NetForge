<?php
declare(strict_types=1);

namespace NetForge;

/**
 * Sign-in, sign-up and role checks (module 1.0).
 * Roles: student (plays), lecturer (manages content), admin (everything a lecturer can, plus settings).
 * The role is read from the database on every request, so a change takes effect immediately.
 */
final class Auth
{
    public const STAFF = ['lecturer', 'admin'];

    public static function userId(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    /** The signed-in, active user, or null (stale sessions are cleared). */
    public static function current(): ?array
    {
        $id = self::userId();
        if ($id === null) {
            return null;
        }
        $u = Db::one('SELECT id, username, role, active FROM users WHERE id = ?', [$id]);
        if ($u === null || !$u['active']) {
            self::logout(); // account deleted, deactivated, or database rebuilt
            return null;
        }
        return $u;
    }

    /** Where each role lands after signing in (FR-1.2). Paths are relative to public/. */
    public static function home(string $role): string
    {
        return in_array($role, self::STAFF, true) ? 'lecturer/index.php' : 'play.php';
    }

    /** For the game page: any signed-in user (staff can preview the game). */
    public static function requirePage(): int
    {
        $u = self::current();
        if ($u === null) {
            header('Location: login.php');
            exit;
        }
        return (int) $u['id'];
    }

    /**
     * For staff pages: sends guests to sign in and students back to the game (NFR-3: checked on the server).
     * $root is the relative path from the page to public/, e.g. '../'.
     */
    public static function requireRole(array $roles, string $root = '../'): array
    {
        $u = self::current();
        if ($u === null) {
            header('Location: ' . $root . 'login.php');
            exit;
        }
        if (!in_array($u['role'], $roles, true)) {
            http_response_code(403);
            header('Location: ' . $root . self::home($u['role']));
            exit;
        }
        return $u;
    }

    /** Returns an error message, or null when the account was created. */
    public static function register(string $username, string $password, string $role = 'student', bool $signIn = true): ?string
    {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            return 'Use 3 to 32 letters, numbers or underscores for your username.';
        }
        if (strlen($password) < 8) {
            return 'Use at least 8 characters for your password.';
        }
        if (!in_array($role, ['student', 'lecturer', 'admin'], true)) {
            return 'Unknown role.';
        }
        if (Db::one('SELECT id FROM users WHERE username = ?', [$username])) {
            return 'That username is taken. Try another.';
        }
        $start = Db::configInt('start_credits', 0);
        Db::transaction(function () use ($username, $password, $role, $start, $signIn): void {
            Db::exec(
                'INSERT INTO users (username, password_hash, role, credits) VALUES (?, ?, ?, ?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $role, $start]
            );
            $id = (int) Db::pdo()->lastInsertId();
            Starter::give($id);
            if ($signIn) {
                self::login($id);
            }
        });
        return null;
    }

    /** Returns the user's home page on success, or null. */
    public static function attempt(string $username, string $password): ?string
    {
        $row = Db::one('SELECT id, password_hash, role, active FROM users WHERE username = ?', [trim($username)]);
        if ($row === null || !$row['active'] || !password_verify($password, $row['password_hash'])) {
            return null;
        }
        self::login((int) $row['id']);
        return self::home($row['role']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    private static function login(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $id;
    }
}
