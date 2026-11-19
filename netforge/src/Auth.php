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
        // close the session after a spell of inactivity (FR-1.2)
        $idle = max(5, (int) Config::get('app.idle_minutes', 120)) * 60;
        if (isset($_SESSION['seen']) && time() - (int) $_SESSION['seen'] > $idle) {
            self::logout();
            $_SESSION['notice'] = 'You were signed out after a while without activity. Sign in again.';
            return null;
        }
        $_SESSION['seen'] = time();
        $u = Db::one('SELECT id, username, display_name, avatar, role, active FROM users WHERE id = ?', [$id]);
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

    /** Shows a one-off message on the next page (e.g. after an idle sign-out). */
    public static function takeNotice(): ?string
    {
        $n = $_SESSION['notice'] ?? null;
        unset($_SESSION['notice']);
        return $n;
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
    public static function register(string $username, string $password, string $role = 'student', bool $signIn = true, ?string $email = null): ?string
    {
        if ($email !== null) {
            try {
                $email = Accounts::checkEmail($email);
            } catch (GameError $e) {
                return $e->getMessage();
            }
            if (Db::one('SELECT id FROM users WHERE email = ?', [$email])) {
                return 'That email already has an account. Sign in, or reset your password.';
            }
        }
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
        Db::transaction(function () use ($username, $password, $role, $start, $signIn, $email): void {
            Db::exec(
                'INSERT INTO users (username, email, password_hash, role, credits) VALUES (?, ?, ?, ?, ?)',
                [$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $start]
            );
            $id = (int) Db::pdo()->lastInsertId();
            Starter::give($id);
            if ($signIn) {
                self::login($id);
            }
        });
        return null;
    }

    /** Sign-in limits: this many failures within LOCK_MINUTES locks that username, or that address. */
    public const MAX_FAILS_PER_NAME = 5;
    public const MAX_FAILS_PER_IP = 20;
    public const LOCK_MINUTES = 15;

    private static function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    }

    /**
     * Whether sign-in is paused for this username (as typed) or this address, after too many failures.
     * Works the same for names that don't exist, so it reveals nothing about which accounts are real.
     */
    public static function lockedOut(string $username): bool
    {
        $since = 'NOW() - INTERVAL ' . self::LOCK_MINUTES . ' MINUTE';
        $byName = (int) Db::one("SELECT COUNT(*) n FROM login_failures WHERE username = ? AND created_at > $since", [mb_strtolower(trim($username))])['n'];
        $byIp = (int) Db::one("SELECT COUNT(*) n FROM login_failures WHERE ip = ? AND created_at > $since", [self::clientIp()])['n'];
        return $byName >= self::MAX_FAILS_PER_NAME || $byIp >= self::MAX_FAILS_PER_IP;
    }

    /** Signs in with a username or email. Returns the user's home page on success, or null. */
    public static function attempt(string $username, string $password): ?string
    {
        $who = trim($username);
        $row = Db::one('SELECT id, password_hash, role, active FROM users WHERE username = ? OR email = ?', [$who, strtolower($who)]);
        if ($row === null || !$row['active'] || !password_verify($password, $row['password_hash'])) {
            Db::exec('INSERT INTO login_failures (username, ip) VALUES (?, ?)', [mb_substr(mb_strtolower($who), 0, 190), self::clientIp()]);
            Db::exec('DELETE FROM login_failures WHERE created_at < NOW() - INTERVAL 1 DAY'); // keep the table small
            return null;
        }
        Db::exec('DELETE FROM login_failures WHERE username = ?', [mb_strtolower($who)]);
        self::login((int) $row['id']);
        Db::exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$row['id']]);
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
        $_SESSION['seen'] = time();
    }
}
