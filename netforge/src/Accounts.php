<?php
declare(strict_types=1);

namespace NetForge;

/**
 * Accounts beyond sign-in (module 1.0): profile editing (FR-1.4), one-time password
 * reset links (FR-1.3) and admin account management (FR-1.5).
 * Every method throws GameError with a message that's safe to show.
 */
final class Accounts
{
    /** Avatar colourways for the pixel player. Mirrors AVATARS in public/assets/js/game/art.js. */
    public const AVATARS = [
        'cyan' => 'Cyan hoodie', 'magenta' => 'Magenta jacket', 'lime' => 'Lime vest', 'amber' => 'Amber coat',
        'violet' => 'Violet hoodie', 'steel' => 'Steel jacket', 'ember' => 'Ember jacket', 'mint' => 'Mint hoodie',
    ];
    public const ROLES = ['student', 'lecturer', 'admin'];
    /** At most this many reset emails per account per hour, so the form can't be used to spam someone. */
    private const RESETS_PER_HOUR = 3;

    /* ---------- validation (shared by sign-up, profile and admin) ---------- */

    public static function checkUsername(string $u): string
    {
        $u = trim($u);
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $u)) {
            throw new GameError('Use 3 to 32 letters, numbers or underscores for the username.');
        }
        return $u;
    }

    public static function checkEmail(string $e): string
    {
        $e = strtolower(trim($e));
        if ($e === '' || strlen($e) > 190 || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
            throw new GameError('Enter a valid email address, like name@mail.apu.edu.my.');
        }
        return $e;
    }

    public static function checkPassword(string $p): string
    {
        if (strlen($p) < 8) {
            throw new GameError('Use at least 8 characters for the password.');
        }
        if (strlen($p) > 200) {
            throw new GameError('That password is too long. Use 200 characters or fewer.');
        }
        return $p;
    }

    public static function checkDisplayName(string $d): ?string
    {
        $d = trim(preg_replace('/\s+/u', ' ', $d));
        if ($d === '') {
            return null; // falls back to the username
        }
        if (mb_strlen($d) > 40 || preg_match('/[<>\x00-\x1F]/u', $d)) {
            throw new GameError('Keep the display name under 40 characters, without < or >.');
        }
        return $d;
    }

    private static function emailFree(string $email, ?int $exceptId = null): void
    {
        if (Db::one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $exceptId ?? 0])) {
            throw new GameError('That email already has an account.');
        }
    }

    /** What to call someone: their display name, or their username. */
    public static function name(array $u): string
    {
        $d = (string) ($u['display_name'] ?? '');
        return $d !== '' ? $d : (string) $u['username'];
    }

    /* ---------- profile (FR-1.4) ---------- */

    public static function profile(int $userId): array
    {
        return Db::one('SELECT id, username, email, display_name, avatar, role, created_at, last_login_at FROM users WHERE id = ?', [$userId]);
    }

    public static function updateProfile(int $userId, string $displayName, string $avatar, string $email): void
    {
        $display = self::checkDisplayName($displayName);
        if (!isset(self::AVATARS[$avatar])) {
            throw new GameError('Pick one of the avatars.');
        }
        $email = self::checkEmail($email);
        self::emailFree($email, $userId);
        Db::exec('UPDATE users SET display_name = ?, avatar = ?, email = ? WHERE id = ?', [$display, $avatar, $email, $userId]);
    }

    public static function changePassword(int $userId, string $current, string $new, string $confirm): void
    {
        $hash = Db::one('SELECT password_hash FROM users WHERE id = ?', [$userId])['password_hash'] ?? '';
        if (!password_verify($current, $hash)) {
            throw new GameError('Your current password isn’t right.');
        }
        if ($new !== $confirm) {
            throw new GameError('The two new passwords don’t match.');
        }
        self::checkPassword($new);
        Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $userId]);
        Db::exec('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    /* ---------- password reset (FR-1.3) ---------- */

    /** The site's address, for links in emails. Set app.base_url in config on a real server. */
    public static function baseUrl(): string
    {
        $configured = (string) Config::get('app.base_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $dir = preg_replace('#/(lecturer|api)(/.*)?$#', '', $dir); // back up to public/
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . $dir;
    }

    /** Makes a one-time link. Earlier unused links for the account stop working. */
    public static function createResetLink(int $userId, ?int $adminId = null): array
    {
        $minutes = max(5, Db::configInt('reset_minutes', 30));
        $token = bin2hex(random_bytes(32));
        Db::transaction(function () use ($userId, $adminId, $token, $minutes): void {
            Db::exec('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
            Db::exec(
                'INSERT INTO password_resets (token_hash, user_id, expires_at, created_by) VALUES (?, ?, NOW() + INTERVAL ? MINUTE, ?)',
                [hash('sha256', $token), $userId, $minutes, $adminId]
            );
        });
        return ['url' => self::baseUrl() . '/reset.php?token=' . $token, 'minutes' => $minutes];
    }

    /**
     * "Forgot password": emails a link if the username or email matches an active account.
     * Always looks the same from outside, so it can't be used to find out who has an account.
     * Returns where the email went (for the dev notice), or null if nothing was sent.
     */
    public static function requestReset(string $who): ?string
    {
        $who = trim($who);
        if ($who === '') {
            throw new GameError('Enter your username or email.');
        }
        // A real email must never take its link from the request's Host header: anyone could
        // send a forged one and receive the victim's token. So sending needs app.base_url.
        // Checked before looking up the account, so the answer is the same for everyone.
        if (Config::get('mail.transport', 'log') === 'mail' && trim((string) Config::get('app.base_url', '')) === '') {
            error_log('NetForge: password reset emails need app.base_url in config/config.php.');
            throw new GameError('Password reset by email isn’t set up on this server yet. Ask your lecturer for a reset link.');
        }
        $u = Db::one('SELECT id, username, email, display_name, active FROM users WHERE username = ? OR email = ?', [$who, strtolower($who)]);
        if ($u === null || !$u['active'] || !$u['email']) {
            return null;
        }
        $recent = (int) Db::one('SELECT COUNT(*) n FROM password_resets WHERE user_id = ? AND created_by IS NULL AND created_at > NOW() - INTERVAL 1 HOUR', [$u['id']])['n'];
        if ($recent >= self::RESETS_PER_HOUR) {
            return null;
        }
        $link = self::createResetLink((int) $u['id']);
        return Mailer::send($u['email'], 'Reset your NetForge password', implode("\n", [
            'Hi ' . self::name($u) . ',',
            '',
            'Someone asked to reset the password for your NetForge account (' . $u['username'] . ').',
            'Open this link to choose a new one. It works once, for ' . $link['minutes'] . ' minutes:',
            '',
            $link['url'],
            '',
            'If you didn’t ask, ignore this email. Your password stays the same.',
        ]));
    }

    /** The account a reset token belongs to, if the token is still valid. */
    public static function tokenUser(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return Db::one(
            'SELECT u.id, u.username, u.display_name FROM password_resets r JOIN users u ON u.id = r.user_id
              WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND u.active = 1',
            [hash('sha256', $token)]
        );
    }

    public static function resetPassword(string $token, string $new, string $confirm): void
    {
        Db::transaction(function () use ($token, $new, $confirm): void {
            $u = self::tokenUser($token);
            if ($u === null) {
                throw new GameError('This link has expired or was already used. Ask for a new one.');
            }
            if ($new !== $confirm) {
                throw new GameError('The two passwords don’t match.');
            }
            self::checkPassword($new);
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            Db::exec('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$u['id']]);
        });
    }

    /* ---------- admin (FR-1.5) ---------- */

    /** Accounts, filtered by text (username, name or email), role and status. */
    public static function search(string $q, string $role, string $status, int $limit = 200): array
    {
        $where = ['1 = 1'];
        $args = [];
        if ($q !== '') {
            $where[] = '(username LIKE ? OR display_name LIKE ? OR email LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%");
        }
        if (in_array($role, self::ROLES, true)) {
            $where[] = 'role = ?';
            $args[] = $role;
        }
        if ($status === 'active' || $status === 'inactive') {
            $where[] = 'active = ' . ($status === 'active' ? 1 : 0);
        }
        return Db::all(
            'SELECT id, username, email, display_name, avatar, role, active, level, created_at, last_login_at FROM users
              WHERE ' . implode(' AND ', $where) . ' ORDER BY active DESC, role, username LIMIT ' . max(1, min(500, $limit)),
            $args
        );
    }

    public static function create(int $adminId, string $username, string $email, string $role, string $password): int
    {
        $username = self::checkUsername($username);
        $email = self::checkEmail($email);
        self::checkPassword($password);
        if (!in_array($role, self::ROLES, true)) {
            throw new GameError('Pick a role.');
        }
        if (Db::one('SELECT id FROM users WHERE username = ?', [$username])) {
            throw new GameError('That username is taken.');
        }
        self::emailFree($email);
        $error = Auth::register($username, $password, $role, false, $email);
        if ($error !== null) {
            throw new GameError($error);
        }
        $id = (int) Db::one('SELECT id FROM users WHERE username = ?', [$username])['id'];
        self::log($adminId, $id, "Created the account as $role");
        return $id;
    }

    public static function update(int $adminId, int $userId, string $displayName, string $email, string $role): void
    {
        $u = Db::one('SELECT id, role, active, email, display_name FROM users WHERE id = ?', [$userId]);
        if ($u === null) {
            throw new GameError('That account doesn’t exist.');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new GameError('Pick a role.');
        }
        $display = self::checkDisplayName($displayName);
        $email = trim($email) === '' ? null : self::checkEmail($email);
        if ($email !== null) {
            self::emailFree($email, $userId);
        }
        if ($u['role'] === 'admin' && $role !== 'admin') {
            self::keepAnAdmin($adminId, $userId, 'change the role of');
        }
        Db::exec('UPDATE users SET display_name = ?, email = ?, role = ? WHERE id = ?', [$display, $email, $role, $userId]);
        $changes = [];
        if ($u['role'] !== $role) {
            $changes[] = "role {$u['role']} → $role";
        }
        if ($u['email'] !== $email) {
            $changes[] = 'email';
        }
        if ($u['display_name'] !== $display) {
            $changes[] = 'display name';
        }
        if ($changes) {
            self::log($adminId, $userId, 'Changed ' . implode(', ', $changes));
        }
    }

    /** Deactivated accounts can't sign in and are signed out on their next request. Nothing is deleted. */
    public static function setActive(int $adminId, int $userId, bool $active): void
    {
        $u = Db::one('SELECT id, role FROM users WHERE id = ?', [$userId]);
        if ($u === null) {
            throw new GameError('That account doesn’t exist.');
        }
        if (!$active && $u['role'] === 'admin') {
            self::keepAnAdmin($adminId, $userId, 'deactivate');
        }
        Db::exec('UPDATE users SET active = ? WHERE id = ?', [(int) $active, $userId]);
        if (!$active) {
            Db::exec('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
        }
        self::log($adminId, $userId, $active ? 'Reactivated the account' : 'Deactivated the account');
    }

    /** Never lock everyone out: an admin can't demote or deactivate themselves, or the last active admin. */
    private static function keepAnAdmin(int $adminId, int $userId, string $what): void
    {
        if ($adminId === $userId) {
            throw new GameError("You can’t $what your own admin account. Ask another admin.");
        }
        $admins = (int) Db::one('SELECT COUNT(*) n FROM users WHERE role = "admin" AND active = 1 AND id <> ?', [$userId])['n'];
        if ($admins === 0) {
            throw new GameError("That’s the last active admin. Make someone else an admin first.");
        }
    }

    public static function log(int $adminId, int $userId, string $action): void
    {
        Db::exec('INSERT INTO admin_log (admin_id, user_id, action) VALUES (?, ?, ?)', [$adminId, $userId, mb_substr($action, 0, 160)]);
    }

    public static function history(int $userId, int $limit = 10): array
    {
        return Db::all(
            'SELECT l.action, l.created_at, a.username admin FROM admin_log l JOIN users a ON a.id = l.admin_id
              WHERE l.user_id = ? ORDER BY l.id DESC LIMIT ' . max(1, min(50, $limit)),
            [$userId]
        );
    }
}
