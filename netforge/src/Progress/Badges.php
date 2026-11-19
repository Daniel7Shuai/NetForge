<?php
declare(strict_types=1);

namespace NetForge\Progress;

use NetForge\Db;
use NetForge\Defence\Defence;
use NetForge\Rules;

/**
 * Badges for milestones and the daily learning streak (FR-4.5).
 * Each badge's rule is a query over what the student has actually done, so badges
 * can't be earned by just clicking around; they're checked whenever the game state loads.
 */
final class Badges
{
    /** @return array<string, callable(int):bool> badge code => rule */
    private static function rules(): array
    {
        $exists = fn (string $sql) => fn (int $u) => Db::one($sql, [$u]) !== null;
        $count = fn (string $sql, int $n) => fn (int $u) => (int) Db::one($sql, [$u])['n'] >= $n;
        return [
            'first_boot'   => $exists('SELECT 1 FROM devices WHERE user_id = ? AND item_code = "laptop"'),
            'first_lesson' => $exists('SELECT 1 FROM level_progress WHERE user_id = ? AND completed_at IS NOT NULL'),
            'online'       => fn (int $u) => Income::online($u) !== [],
            'shopper'      => $exists('SELECT 1 FROM event_log WHERE user_id = ? AND message LIKE "Bought %"'),
            'world_master' => $exists('SELECT 1 FROM world_progress WHERE user_id = ? AND mastered_at IS NOT NULL'),
            'perfect_exam' => $exists('SELECT 1 FROM world_progress WHERE user_id = ? AND exam_best = 100'),
            'blitz_10'     => $count('SELECT COUNT(*) n FROM attempts a JOIN questions q ON q.id = a.question_id WHERE a.user_id = ? AND a.correct = 1 AND q.type = "blitz"', 10),
            'subnet_10'    => $count('SELECT COUNT(*) n FROM attempts a JOIN questions q ON q.id = a.question_id WHERE a.user_id = ? AND a.correct = 1 AND q.type = "subnet"', 10),
            'auditor'      => $count('SELECT COUNT(*) n FROM attempts WHERE user_id = ? AND mode = "audit" AND correct = 1', 5),
            'cabler'       => $count('SELECT COUNT(*) n FROM cables WHERE user_id = ? AND status = "up"', 3),
            'defender'     => $count('SELECT COUNT(*) n FROM attacks WHERE user_id = ? AND status = "defended"', 3),
            'fortress'     => function (int $u) { $r = Defence::rating($u); return $r !== null && $r['count'] >= 5 && $r['grade'] === 'A'; },
            'team_player'  => $exists('SELECT 1 FROM raid_rewards rr JOIN weekly_challenges c ON c.id = rr.challenge_id
                                        JOIN raid_contributions r ON r.guild_id = rr.guild_id AND r.week_start = c.week_start WHERE r.user_id = ?'),
            'streak_3'     => fn (int $u) => self::streak($u)['best'] >= 3,
            'streak_7'     => fn (int $u) => self::streak($u)['best'] >= 7,
        ];
    }

    /**
     * Daily learning streak: consecutive days with at least one correct answer.
     * The current streak survives until the end of tomorrow, so learning later today still counts.
     * @return array{current:int, best:int, today:bool}
     */
    public static function streak(int $userId): array
    {
        static $cache = [];
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }
        $days = array_column(Db::all(
            'SELECT DISTINCT DATE(created_at) d FROM attempts WHERE user_id = ? AND correct = 1 AND created_at >= CURDATE() - INTERVAL 400 DAY ORDER BY d DESC',
            [$userId]
        ), 'd');
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $best = $run = 0;
        $prev = null;
        foreach ($days as $d) {
            $run = ($prev !== null && date('Y-m-d', strtotime($prev . ' -1 day')) === $d) ? $run + 1 : 1;
            $best = max($best, $run);
            $prev = $d;
        }
        $current = 0;
        if ($days && in_array($days[0], [$today, $yesterday], true)) {
            $current = 1;
            for ($i = 1; $i < count($days) && date('Y-m-d', strtotime($days[$i - 1] . ' -1 day')) === $days[$i]; $i++) {
                $current++;
            }
        }
        return $cache[$userId] = ['current' => $current, 'best' => $best, 'today' => ($days[0] ?? null) === $today];
    }

    /** Awards any newly earned badges. Returns them (code, name, icon) so the game can celebrate. */
    public static function award(int $userId): array
    {
        if ((Db::one('SELECT role FROM users WHERE id = ?', [$userId])['role'] ?? '') !== 'student') {
            return [];
        }
        $have = array_column(Db::all('SELECT badge FROM user_badges WHERE user_id = ?', [$userId]), 'badge');
        $new = [];
        foreach (self::rules() as $code => $rule) {
            if (!in_array($code, $have, true) && $rule($userId)
                && Db::exec('INSERT IGNORE INTO user_badges (user_id, badge) VALUES (?, ?)', [$userId, $code]) > 0) {
                $b = Db::one('SELECT code, name, icon, description FROM badges WHERE code = ?', [$code]);
                if ($b) {
                    $new[] = $b;
                    Rules::log($userId, 'ok', "Badge earned: {$b['name']}. {$b['description']}");
                }
            }
        }
        return $new;
    }

    /** Every badge, with when this student earned it (null if not yet). */
    public static function catalog(int $userId): array
    {
        return array_map(fn ($r) => ['code' => $r['code'], 'name' => $r['name'], 'description' => $r['description'],
            'icon' => $r['icon'], 'earnedAt' => $r['earned_at']], Db::all(
            'SELECT b.*, ub.earned_at FROM badges b LEFT JOIN user_badges ub ON ub.badge = b.code AND ub.user_id = ? ORDER BY b.sort_order',
            [$userId]
        ));
    }
}
