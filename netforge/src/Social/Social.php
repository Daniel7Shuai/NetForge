<?php
declare(strict_types=1);

namespace NetForge\Social;

use NetForge\Db;
use NetForge\GameError;
use NetForge\Rules;

/**
 * Social and competition (module 6.0): guilds, weekly goals, raids, leaderboards,
 * shared milestones and friends. Leaderboards never show a full global ranking,
 * only the ranks around the student (FR-6.3), to avoid demotivating comparison.
 */
final class Social
{
    public const MAX_MEMBERS = 8;
    public const NEARBY = 2; // ranks shown above and below you

    public static function weekStart(?int $ts = null): string
    {
        $ts ??= time();
        return date('Y-m-d', strtotime('monday this week', $ts));
    }

    /* ======================= guilds ======================= */

    public static function membership(int $userId): ?array
    {
        return Db::one(
            'SELECT m.guild_id, m.role, g.name, g.tag, g.motto FROM guild_members m JOIN guilds g ON g.id = m.guild_id WHERE m.user_id = ?',
            [$userId]
        );
    }

    public static function create(int $userId, string $name, string $tag, string $motto): void
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $tag = strtoupper(trim($tag));
        $motto = trim($motto);
        if (!preg_match('/^[\p{L}\p{N} _\-]{3,32}$/u', $name)) {
            throw new GameError('Use 3 to 32 letters, numbers, spaces or dashes for the guild name.');
        }
        if (!preg_match('/^[A-Z0-9]{2,5}$/', $tag)) {
            throw new GameError('Use 2 to 5 letters or numbers for the tag, for example NOC.');
        }
        if (mb_strlen($motto) > 120) {
            throw new GameError('Keep the motto under 120 characters.');
        }
        Db::transaction(function () use ($userId, $name, $tag, $motto): void {
            self::requireStudent($userId);
            if (Db::one('SELECT user_id FROM guild_members WHERE user_id = ? FOR UPDATE', [$userId])) {
                throw new GameError('Leave your current guild first.');
            }
            if (Db::one('SELECT id FROM guilds WHERE name = ? OR tag = ?', [$name, $tag])) {
                throw new GameError('That guild name or tag is taken. Try another.');
            }
            Db::exec('INSERT INTO guilds (name, tag, motto) VALUES (?, ?, ?)', [$name, $tag, $motto]);
            $gid = (int) Db::pdo()->lastInsertId();
            Db::exec('INSERT INTO guild_members (user_id, guild_id, role) VALUES (?, ?, "leader")', [$userId, $gid]);
            self::feed($gid, $userId, 'join', self::name($userId) . ' founded the guild.');
            Rules::log($userId, 'ok', "You founded [$tag] $name.");
        });
    }

    public static function join(int $userId, int $guildId): void
    {
        Db::transaction(function () use ($userId, $guildId): void {
            self::requireStudent($userId);
            if (Db::one('SELECT user_id FROM guild_members WHERE user_id = ? FOR UPDATE', [$userId])) {
                throw new GameError('Leave your current guild first.');
            }
            $g = Db::one('SELECT id, name, tag FROM guilds WHERE id = ? FOR UPDATE', [$guildId]);
            if ($g === null) {
                throw new GameError('That guild doesn’t exist any more.');
            }
            $n = (int) Db::one('SELECT COUNT(*) n FROM guild_members WHERE guild_id = ?', [$guildId])['n'];
            if ($n >= self::MAX_MEMBERS) {
                throw new GameError(sprintf('[%s] is full (%d members).', $g['tag'], self::MAX_MEMBERS));
            }
            Db::exec('INSERT INTO guild_members (user_id, guild_id) VALUES (?, ?)', [$userId, $guildId]);
            self::feed($guildId, $userId, 'join', self::name($userId) . ' joined.');
            Rules::log($userId, 'ok', "You joined [{$g['tag']}] {$g['name']}.");
        });
    }

    /** Leaving hands leadership to the longest-serving member; the last one out closes the guild. */
    public static function leave(int $userId): void
    {
        Db::transaction(function () use ($userId): void {
            $m = Db::one('SELECT guild_id, role FROM guild_members WHERE user_id = ? FOR UPDATE', [$userId]);
            if ($m === null) {
                throw new GameError('You’re not in a guild.');
            }
            $gid = (int) $m['guild_id'];
            Db::exec('DELETE FROM guild_members WHERE user_id = ?', [$userId]);
            $next = Db::one('SELECT user_id FROM guild_members WHERE guild_id = ? ORDER BY joined_at, user_id LIMIT 1', [$gid]);
            if ($next === null) {
                Db::exec('DELETE FROM guilds WHERE id = ?', [$gid]);
                Rules::log($userId, 'info', 'You left your guild. It had no other members, so it was closed.');
                return;
            }
            $msg = self::name($userId) . ' left.';
            if ($m['role'] === 'leader') {
                Db::exec('UPDATE guild_members SET role = "leader" WHERE user_id = ?', [$next['user_id']]);
                $msg .= ' ' . self::name((int) $next['user_id']) . ' is the new leader.';
            }
            self::feed($gid, null, 'leave', $msg);
            Rules::log($userId, 'info', 'You left your guild.');
        });
    }

    public static function setGoal(int $userId, string $title, int $target): void
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 80) {
            throw new GameError('Give the goal a short title, up to 80 characters.');
        }
        if ($target < 5 || $target > 2000) {
            throw new GameError('Set a target from 5 to 2000 correct answers.');
        }
        $gid = self::requireLeader($userId);
        Db::exec(
            'INSERT INTO guild_goals (guild_id, week_start, title, target) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), target = VALUES(target)',
            [$gid, self::weekStart(), $title, $target]
        );
        self::feed($gid, $userId, 'goal', sprintf('%s set this week’s goal: %s (%d correct answers).', self::name($userId), $title, $target));
    }

    public static function kick(int $userId, int $memberId): void
    {
        $gid = self::requireLeader($userId);
        if ($memberId === $userId) {
            throw new GameError('Use Leave guild to leave it yourself.');
        }
        if (Db::exec('DELETE FROM guild_members WHERE user_id = ? AND guild_id = ?', [$memberId, $gid]) === 0) {
            throw new GameError('That student isn’t in your guild.');
        }
        self::feed($gid, null, 'leave', self::name($memberId) . ' was removed by the leader.');
        Rules::log($memberId, 'warn', 'You were removed from your guild.');
    }

    /* ======================= milestones (FR-6.4) ======================= */

    /** Things a student has achieved and can share with their guild. Keys are stable. */
    public static function milestones(int $userId): array
    {
        $out = [];
        foreach (Db::all(
            'SELECT w.number, w.title, p.exam_best FROM world_progress p JOIN worlds w ON w.id = p.world_id
              WHERE p.user_id = ? AND p.mastered_at IS NOT NULL ORDER BY w.number',
            [$userId]
        ) as $w) {
            $out['world' . $w['number']] = sprintf('Mastered World %d: %s', $w['number'], $w['title']);
            if ((int) $w['exam_best'] === 100) {
                $out['ace' . $w['number']] = sprintf('Scored 100%% on the World %d exam', $w['number']);
            }
        }
        $u = Db::one('SELECT level FROM users WHERE id = ?', [$userId]);
        for ($l = 2; $l <= (int) $u['level']; $l++) {
            $out['level' . $l] = "Reached level $l";
        }
        foreach (Db::all('SELECT b.code, b.name FROM user_badges ub JOIN badges b ON b.code = ub.badge WHERE ub.user_id = ? ORDER BY b.sort_order', [$userId]) as $b) {
            $out['badge_' . $b['code']] = 'Earned the ' . $b['name'] . ' badge';
        }
        $streak = \NetForge\Progress\Badges::streak($userId)['best'];
        if ($streak >= 3) {
            $out['streak' . $streak] = "Learned $streak days in a row";
        }
        $links = (int) Db::one('SELECT COUNT(*) n FROM cables WHERE user_id = ? AND status = "up"', [$userId])['n'];
        if ($links > 0) {
            $out['links' . $links] = sprintf('Has %d working network link%s', $links, $links === 1 ? '' : 's');
        }
        return $out;
    }

    public static function share(int $userId, string $key): void
    {
        $m = self::membership($userId);
        if ($m === null) {
            throw new GameError('Join a guild to share milestones.');
        }
        $all = self::milestones($userId);
        if (!isset($all[$key])) {
            throw new GameError('That milestone isn’t yours to share yet.');
        }
        $msg = self::name($userId) . ': ' . $all[$key] . '.';
        if (Db::one('SELECT id FROM guild_feed WHERE guild_id = ? AND user_id = ? AND kind = "share" AND message = ?', [$m['guild_id'], $userId, $msg])) {
            throw new GameError('You’ve already shared that one.');
        }
        self::feed((int) $m['guild_id'], $userId, 'share', $msg);
    }

    /* ======================= raids (FR-6.2) ======================= */

    public static function challenge(?string $week = null): ?array
    {
        return Db::one(
            'SELECT c.*, w.number world_number, w.title world_title FROM weekly_challenges c LEFT JOIN worlds w ON w.id = c.world_id WHERE c.week_start = ?',
            [$week ?? self::weekStart()]
        );
    }

    /** Called for every correct answer (Challenge::answer). Adds to the guild's tally and pays out a finished raid once. */
    public static function recordCorrect(int $userId, int $worldId): void
    {
        $m = Db::one('SELECT guild_id FROM guild_members WHERE user_id = ?', [$userId]);
        if ($m === null) {
            return;
        }
        $gid = (int) $m['guild_id'];
        $week = self::weekStart();
        Db::exec(
            'INSERT INTO raid_contributions (guild_id, user_id, week_start, world_id, correct) VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE correct = correct + 1',
            [$gid, $userId, $week, $worldId]
        );
        $c = self::challenge($week);
        if ($c === null || self::raidProgress($gid, $c) < (int) $c['target']) {
            return;
        }
        Db::transaction(function () use ($gid, $c, $week): void {
            if (Db::exec('INSERT IGNORE INTO raid_rewards (guild_id, challenge_id) VALUES (?, ?)', [$gid, $c['id']]) === 0) {
                return; // already paid
            }
            $members = array_column(Db::all(
                'SELECT DISTINCT r.user_id FROM raid_contributions r JOIN guild_members m ON m.user_id = r.user_id AND m.guild_id = r.guild_id
                  WHERE r.guild_id = ? AND r.week_start = ?',
                [$gid, $week]
            ), 'user_id');
            foreach ($members as $uid) {
                Rules::log((int) $uid, 'ok', sprintf('Raid complete: %s.%s', $c['title'], $c['reward_xp'] ? " +{$c['reward_xp']} XP." : ''));
                Rules::addXp((int) $uid, (int) $c['reward_xp']);
            }
            self::feed($gid, null, 'raid', sprintf('Raid complete: %s. %d contributor%s rewarded.', $c['title'], count($members), count($members) === 1 ? '' : 's'));
        });
    }

    public static function raidProgress(int $guildId, array $challenge): int
    {
        $sql = 'SELECT COALESCE(SUM(correct), 0) n FROM raid_contributions WHERE guild_id = ? AND week_start = ?';
        $args = [$guildId, $challenge['week_start']];
        if ($challenge['world_id'] !== null) {
            $sql .= ' AND world_id = ?';
            $args[] = $challenge['world_id'];
        }
        return (int) Db::one($sql, $args)['n'];
    }

    /* ======================= friends (FR-6.5) ======================= */

    public static function addFriend(int $userId, string $username): void
    {
        $f = Db::one('SELECT id, username FROM users WHERE username = ? AND role = "student" AND active = 1', [trim($username)]);
        if ($f === null) {
            throw new GameError('No student has that username.');
        }
        if ((int) $f['id'] === $userId) {
            throw new GameError('You can’t add yourself.');
        }
        if (Db::one('SELECT 1 FROM friends WHERE user_id = ? AND friend_id = ?', [$userId, $f['id']])) {
            throw new GameError($f['username'] . ' is already your friend.');
        }
        Db::exec('INSERT IGNORE INTO friends (user_id, friend_id) VALUES (?, ?), (?, ?)', [$userId, $f['id'], $f['id'], $userId]);
        Rules::log($userId, 'ok', "You and {$f['username']} are now friends.");
        Rules::log((int) $f['id'], 'info', self::name($userId) . ' added you as a friend.');
    }

    public static function removeFriend(int $userId, int $friendId): void
    {
        Db::exec('DELETE FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)', [$userId, $friendId, $friendId, $userId]);
    }

    /* ======================= overview for the Social panel ======================= */

    public static function overview(int $userId): array
    {
        $week = self::weekStart();
        $weekEnd = date('Y-m-d', strtotime($week . ' +6 days'));
        $m = self::membership($userId);
        $c = self::challenge($week);

        $guild = null;
        if ($m) {
            $gid = (int) $m['guild_id'];
            $members = Db::all(
                'SELECT u.id, u.username, u.level, m.role,
                        (SELECT COALESCE(SUM(correct), 0) FROM raid_contributions r WHERE r.guild_id = m.guild_id AND r.user_id = u.id AND r.week_start = ?) week_correct
                   FROM guild_members m JOIN users u ON u.id = m.user_id WHERE m.guild_id = ?
                  ORDER BY week_correct DESC, u.username',
                [$week, $gid]
            );
            $goal = Db::one('SELECT title, target FROM guild_goals WHERE guild_id = ? AND week_start = ?', [$gid, $week]);
            $weekTotal = (int) Db::one('SELECT COALESCE(SUM(correct), 0) n FROM raid_contributions WHERE guild_id = ? AND week_start = ?', [$gid, $week])['n'];
            $guild = [
                'id' => $gid, 'name' => $m['name'], 'tag' => $m['tag'], 'motto' => $m['motto'], 'myRole' => $m['role'],
                'members' => array_map(fn ($r) => ['id' => (int) $r['id'], 'username' => $r['username'], 'level' => (int) $r['level'],
                    'role' => $r['role'], 'weekCorrect' => (int) $r['week_correct'], 'me' => (int) $r['id'] === $userId], $members),
                'goal' => $goal ? ['title' => $goal['title'], 'target' => (int) $goal['target'], 'progress' => $weekTotal] : null,
                'raid' => $c ? [
                    'progress' => self::raidProgress($gid, $c),
                    'mine' => (int) Db::one('SELECT COALESCE(SUM(correct), 0) n FROM raid_contributions WHERE guild_id = ? AND user_id = ? AND week_start = ?' . ($c['world_id'] ? ' AND world_id = ' . (int) $c['world_id'] : ''), [$gid, $userId, $week])['n'],
                    'done' => (bool) Db::one('SELECT 1 FROM raid_rewards WHERE guild_id = ? AND challenge_id = ?', [$gid, $c['id']]),
                ] : null,
                'feed' => array_map(fn ($f) => ['kind' => $f['kind'], 'message' => $f['message'], 'at' => $f['created_at']],
                    Db::all('SELECT kind, message, created_at FROM guild_feed WHERE guild_id = ? ORDER BY id DESC LIMIT 15', [$gid])),
                'weekTotal' => $weekTotal,
            ];
        }

        return [
            'week' => ['start' => $week, 'end' => $weekEnd],
            'challenge' => $c ? ['title' => $c['title'], 'description' => $c['description'], 'target' => (int) $c['target'],
                'reward' => (int) $c['reward_xp'], 'world' => $c['world_number'] === null ? null : 'W' . $c['world_number'] . ' ' . $c['world_title']] : null,
            'guild' => $guild,
            'guilds' => $m ? [] : self::openGuilds($week),
            'milestones' => $m ? self::milestones($userId) : [],
            'leaderboard' => self::nearby($userId, $week),
            'guildBoard' => self::guildBoard($week, $m ? (int) $m['guild_id'] : null),
            'friends' => self::friends($userId, $week),
            'maxMembers' => self::MAX_MEMBERS,
        ];
    }

    /** Ranks by XP earned this week; returns only the student and NEARBY ranks either side (FR-6.3). */
    private static function nearby(int $userId, string $week): array
    {
        $rows = Db::all(
            'SELECT u.id, u.username, COALESCE(SUM(a.xp), 0) xp
               FROM users u LEFT JOIN attempts a ON a.user_id = u.id AND a.created_at >= ?
              WHERE u.role = "student" AND u.active = 1
              GROUP BY u.id ORDER BY xp DESC, u.username',
            [$week . ' 00:00:00']
        );
        $i = array_search($userId, array_map('intval', array_column($rows, 'id')), true);
        if ($i === false) {
            return ['rows' => [], 'rank' => null];
        }
        $from = max(0, $i - self::NEARBY);
        $slice = array_slice($rows, $from, ($i - $from) + self::NEARBY + 1); // never more than NEARBY either side
        return [
            'rank' => $i + 1,
            'rows' => array_map(fn ($r, $k) => ['rank' => $from + $k + 1, 'username' => $r['username'], 'xp' => (int) $r['xp'], 'me' => (int) $r['id'] === $userId],
                $slice, array_keys($slice)),
        ];
    }

    private static function guildBoard(string $week, ?int $mine): array
    {
        return array_map(fn ($r, $k) => ['rank' => $k + 1, 'name' => $r['name'], 'tag' => $r['tag'], 'members' => (int) $r['members'],
            'correct' => (int) $r['correct'], 'mine' => (int) $r['id'] === $mine], $rows = Db::all(
            'SELECT g.id, g.name, g.tag, (SELECT COUNT(*) FROM guild_members m WHERE m.guild_id = g.id) members,
                    (SELECT COALESCE(SUM(correct), 0) FROM raid_contributions r WHERE r.guild_id = g.id AND r.week_start = ?) correct
               FROM guilds g ORDER BY correct DESC, g.name',
            [$week]
        ), array_keys($rows));
    }

    private static function openGuilds(string $week): array
    {
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'tag' => $r['tag'], 'motto' => $r['motto'],
            'members' => (int) $r['members'], 'weekCorrect' => (int) $r['correct'], 'full' => (int) $r['members'] >= self::MAX_MEMBERS], Db::all(
            'SELECT g.id, g.name, g.tag, g.motto, (SELECT COUNT(*) FROM guild_members m WHERE m.guild_id = g.id) members,
                    (SELECT COALESCE(SUM(correct), 0) FROM raid_contributions r WHERE r.guild_id = g.id AND r.week_start = ?) correct
               FROM guilds g ORDER BY members < ? DESC, correct DESC, g.name LIMIT 30',
            [$week, self::MAX_MEMBERS]
        ));
    }

    private static function friends(int $userId, string $week): array
    {
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'username' => $r['username'], 'level' => (int) $r['level'],
            'weekXp' => (int) $r['week_xp'], 'mastered' => (int) $r['mastered'], 'last' => $r['last'], 'guild' => $r['tag']], Db::all(
            'SELECT u.id, u.username, u.level, g.tag,
                    (SELECT COALESCE(SUM(xp), 0) FROM attempts WHERE user_id = u.id AND created_at >= ?) week_xp,
                    (SELECT COUNT(*) FROM world_progress WHERE user_id = u.id AND mastered_at IS NOT NULL) mastered,
                    (SELECT MAX(created_at) FROM attempts WHERE user_id = u.id) last
               FROM friends f JOIN users u ON u.id = f.friend_id
               LEFT JOIN guild_members m ON m.user_id = u.id LEFT JOIN guilds g ON g.id = m.guild_id
              WHERE f.user_id = ? ORDER BY week_xp DESC, u.username',
            [$week . ' 00:00:00', $userId]
        ));
    }

    /* ======================= helpers ======================= */

    private static function requireLeader(int $userId): int
    {
        $m = Db::one('SELECT guild_id, role FROM guild_members WHERE user_id = ?', [$userId]);
        if ($m === null || $m['role'] !== 'leader') {
            throw new GameError('Only the guild leader can do that.');
        }
        return (int) $m['guild_id'];
    }

    private static function requireStudent(int $userId): void
    {
        if ((Db::one('SELECT role FROM users WHERE id = ?', [$userId])['role'] ?? '') !== 'student') {
            throw new GameError('Guilds are for students.');
        }
    }

    private static function name(int $userId): string
    {
        return (string) (Db::one('SELECT username FROM users WHERE id = ?', [$userId])['username'] ?? 'Someone');
    }

    private static function feed(int $guildId, ?int $userId, string $kind, string $message): void
    {
        Db::exec('INSERT INTO guild_feed (guild_id, user_id, kind, message) VALUES (?, ?, ?, ?)', [$guildId, $userId, $kind, mb_substr($message, 0, 255)]);
    }
}
