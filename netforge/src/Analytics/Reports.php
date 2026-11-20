<?php
declare(strict_types=1);

namespace NetForge\Analytics;

use NetForge\Db;
use NetForge\Learning\Elo;

/**
 * Lecturer analytics (module 7.0). Every number here comes straight from the
 * attempts table, so it can be checked by hand against the raw data (objective 7).
 * A period is a pair of inclusive dates ['from' => 'Y-m-d', 'to' => 'Y-m-d'].
 */
final class Reports
{
    /** At-risk rules (FR-7.2). Change the thresholds here. */
    public const RISK_MIN_ANSWERS = 10;     // need this many answers before judging accuracy
    public const RISK_LOW_ACCURACY = 50;    // % correct in the period
    public const RISK_INACTIVE_DAYS = 7;    // days since the last answer
    public const RISK_RATING_DROP = 50;     // Elo points lost in one topic during the period
    public const RISK_AUDIT_BACKLOG = 10;   // review questions overdue

    /** Reads ?from=&to= (defaults to the last 30 days, today included). */
    public static function period(array $get): array
    {
        $ok = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
        $to = $ok($get['to'] ?? null) ? $get['to'] : date('Y-m-d');
        $from = $ok($get['from'] ?? null) ? $get['from'] : date('Y-m-d', strtotime($to . ' -29 days'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return ['from' => $from, 'to' => $to];
    }

    private static function range(array $p): array
    {
        return [$p['from'] . ' 00:00:00', $p['to'] . ' 23:59:59'];
    }

    public static function overview(array $p): array
    {
        $r = Db::one(
            'SELECT COUNT(*) answers, AVG(a.correct) accuracy, COUNT(DISTINCT a.user_id) active
               FROM attempts a JOIN users u ON u.id = a.user_id
              WHERE u.role = "student" AND a.created_at BETWEEN ? AND ?',
            self::range($p)
        );
        $m = Db::one(
            'SELECT COUNT(*) n FROM world_progress p JOIN users u ON u.id = p.user_id
              WHERE u.role = "student" AND p.mastered_at BETWEEN ? AND ?',
            self::range($p)
        );
        return [
            'students' => (int) Db::one('SELECT COUNT(*) n FROM users WHERE role = "student" AND active = 1')['n'],
            'active'   => (int) $r['active'],
            'answers'  => (int) $r['answers'],
            'accuracy' => $r['accuracy'] === null ? null : (int) round($r['accuracy'] * 100),
            'mastered' => (int) $m['n'],
        ];
    }

    /** Accuracy per topic in the period (FR-7.1). */
    public static function byWorld(array $p): array
    {
        return array_map(fn ($r) => [
            'number' => (int) $r['number'], 'title' => $r['title'], 'answers' => (int) $r['answers'],
            'students' => (int) $r['students'], 'accuracy' => $r['accuracy'] === null ? null : (int) round($r['accuracy'] * 100),
        ], Db::all(
            'SELECT w.number, w.title, COUNT(a.id) answers, COUNT(DISTINCT a.user_id) students, AVG(a.correct) accuracy
               FROM worlds w
               LEFT JOIN questions q ON q.world_id = w.id
               LEFT JOIN attempts a ON a.question_id = q.id AND a.created_at BETWEEN ? AND ?
                    AND a.user_id IN (SELECT id FROM users WHERE role = "student")
              WHERE w.archived = 0
              GROUP BY w.id ORDER BY w.number',
            self::range($p)
        ));
    }

    /** Accuracy per topic tag in the period, weakest first (FR-7.1). */
    public static function byTopic(array $p): array
    {
        return array_map(fn ($r) => [
            'topic' => $r['topic'], 'worlds' => $r['worlds'], 'answers' => (int) $r['answers'],
            'students' => (int) $r['students'], 'accuracy' => (int) round($r['accuracy'] * 100),
        ], Db::all(
            'SELECT q.topic, GROUP_CONCAT(DISTINCT CONCAT("T", w.number) ORDER BY w.number) worlds,
                    COUNT(a.id) answers, COUNT(DISTINCT a.user_id) students, AVG(a.correct) accuracy
               FROM attempts a JOIN questions q ON q.id = a.question_id JOIN worlds w ON w.id = q.world_id
               JOIN users u ON u.id = a.user_id
              WHERE u.role = "student" AND a.created_at BETWEEN ? AND ?
              GROUP BY q.topic ORDER BY accuracy ASC, answers DESC',
            self::range($p)
        ));
    }

    /**
     * Students who may need help, each with the reasons (FR-7.2).
     * @return list<array{id:int, username:string, last:?string, reasons:list<string>}>
     */
    public static function atRisk(array $p): array
    {
        $out = [];
        foreach (self::students($p) as $s) {
            $reasons = [];
            if ($s['answers'] >= self::RISK_MIN_ANSWERS && $s['accuracy'] < self::RISK_LOW_ACCURACY) {
                $reasons[] = sprintf('Low accuracy: %d%% over %d answers in this period.', $s['accuracy'], $s['answers']);
            }
            if ($s['last'] === null) {
                if ($s['days_since_join'] >= 3) {
                    $reasons[] = sprintf('Hasn’t answered a question since joining %d days ago.', $s['days_since_join']);
                }
            } elseif ($s['days_inactive'] >= self::RISK_INACTIVE_DAYS) {
                $reasons[] = sprintf('Inactive for %d days.', $s['days_inactive']);
            }
            if ($s['worst_drop'] >= self::RISK_RATING_DROP) {
                $reasons[] = sprintf('Rating fell %d points in %s this period.', $s['worst_drop'], $s['worst_drop_world']);
            }
            if ($s['audit_due'] >= self::RISK_AUDIT_BACKLOG) {
                $reasons[] = sprintf('%d review questions overdue in the Daily Security Audit.', $s['audit_due']);
            }
            if ($reasons) {
                $out[] = ['id' => $s['id'], 'username' => $s['username'], 'last' => $s['last'], 'reasons' => $reasons];
            }
        }
        return $out;
    }

    /** One row per student with activity in (and around) the period. */
    public static function students(array $p): array
    {
        [$from, $to] = self::range($p);
        $rows = Db::all(
            'SELECT u.id, u.username, u.level, u.xp, u.credits, u.created_at,
                    (SELECT MAX(created_at) FROM attempts WHERE user_id = u.id) last,
                    (SELECT COUNT(*) FROM attempts WHERE user_id = u.id AND created_at BETWEEN ? AND ?) answers,
                    (SELECT AVG(correct) FROM attempts WHERE user_id = u.id AND created_at BETWEEN ? AND ?) accuracy,
                    (SELECT COUNT(*) FROM world_progress WHERE user_id = u.id AND mastered_at IS NOT NULL) mastered,
                    (SELECT COUNT(*) FROM audit_queue WHERE user_id = u.id AND due_on < CURDATE()) audit_due
               FROM users u WHERE u.role = "student" AND u.active = 1 ORDER BY u.username',
            [$from, $to, $from, $to]
        );
        // biggest rating fall in one topic during the period: first rating_before vs last rating_after
        $drops = [];
        foreach (Db::all(
            'SELECT a.user_id, w.number, a.rating_before, a.rating_after
               FROM attempts a JOIN questions q ON q.id = a.question_id JOIN worlds w ON w.id = q.world_id
              WHERE a.created_at BETWEEN ? AND ? ORDER BY a.id',
            [$from, $to]
        ) as $a) {
            $k = $a['user_id'] . ':' . $a['number'];
            $drops[$k]['first'] ??= (int) $a['rating_before'];
            $drops[$k]['last'] = (int) $a['rating_after'];
            $drops[$k]['user'] = (int) $a['user_id'];
            $drops[$k]['world'] = 'T' . $a['number'];
        }
        $worst = [];
        foreach ($drops as $d) {
            $fall = $d['first'] - $d['last'];
            if ($fall > ($worst[$d['user']][0] ?? 0)) {
                $worst[$d['user']] = [$fall, $d['world']];
            }
        }
        $now = time();
        return array_map(fn ($r) => [
            'id' => (int) $r['id'], 'username' => $r['username'], 'level' => (int) $r['level'], 'xp' => (int) $r['xp'],
            'credits' => (int) $r['credits'], 'last' => $r['last'], 'answers' => (int) $r['answers'],
            'accuracy' => $r['accuracy'] === null ? null : (int) round($r['accuracy'] * 100),
            'mastered' => (int) $r['mastered'], 'audit_due' => (int) $r['audit_due'],
            'days_inactive' => $r['last'] === null ? null : (int) floor(($now - strtotime($r['last'])) / 86400),
            'days_since_join' => (int) floor(($now - strtotime($r['created_at'])) / 86400),
            'worst_drop' => $worst[(int) $r['id']][0] ?? 0, 'worst_drop_world' => $worst[(int) $r['id']][1] ?? '',
        ], $rows);
    }

    /** One student's progress report for the period (FR-7.4). */
    public static function student(int $id, array $p): ?array
    {
        $u = Db::one('SELECT id, username, level, xp, credits, created_at FROM users WHERE id = ? AND role = "student"', [$id]);
        if ($u === null) {
            return null;
        }
        [$from, $to] = self::range($p);
        $worlds = array_map(fn ($r) => [
            'number' => (int) $r['number'], 'title' => $r['title'], 'rating' => $r['rating'] === null ? null : (int) $r['rating'],
            'tier' => $r['rating'] === null ? null : Elo::tier((int) $r['rating']), 'exam_best' => $r['exam_best'] === null ? null : (int) $r['exam_best'],
            'mastered' => $r['mastered_at'], 'answers' => (int) $r['answers'],
            'accuracy' => $r['accuracy'] === null ? null : (int) round($r['accuracy'] * 100),
        ], Db::all(
            'SELECT w.number, w.title, p.rating, p.exam_best, p.mastered_at,
                    (SELECT COUNT(*) FROM attempts a JOIN questions q ON q.id = a.question_id WHERE a.user_id = ? AND q.world_id = w.id AND a.created_at BETWEEN ? AND ?) answers,
                    (SELECT AVG(a.correct) FROM attempts a JOIN questions q ON q.id = a.question_id WHERE a.user_id = ? AND q.world_id = w.id AND a.created_at BETWEEN ? AND ?) accuracy
               FROM worlds w LEFT JOIN world_progress p ON p.world_id = w.id AND p.user_id = ?
              WHERE w.archived = 0 ORDER BY w.number',
            [$id, $from, $to, $id, $from, $to, $id]
        ));
        $topics = array_map(fn ($r) => ['topic' => $r['topic'], 'answers' => (int) $r['answers'], 'accuracy' => (int) round($r['accuracy'] * 100)], Db::all(
            'SELECT q.topic, COUNT(*) answers, AVG(a.correct) accuracy FROM attempts a JOIN questions q ON q.id = a.question_id
              WHERE a.user_id = ? AND a.created_at BETWEEN ? AND ? GROUP BY q.topic ORDER BY accuracy, answers DESC',
            [$id, $from, $to]
        ));
        $days = array_map(fn ($r) => ['day' => $r['day'], 'answers' => (int) $r['answers'], 'correct' => (int) $r['correct']], Db::all(
            'SELECT DATE(created_at) day, COUNT(*) answers, SUM(correct) correct FROM attempts
              WHERE user_id = ? AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY day',
            [$id, $from, $to]
        ));
        $modes = Db::all(
            'SELECT mode, COUNT(*) answers, AVG(correct) accuracy, SUM(credits) credits, SUM(xp) xp FROM attempts
              WHERE user_id = ? AND created_at BETWEEN ? AND ? GROUP BY mode',
            [$id, $from, $to]
        );
        $risk = array_values(array_filter(self::atRisk($p), fn ($r) => $r['id'] === $id));
        return ['user' => $u, 'worlds' => $worlds, 'topics' => $topics, 'days' => $days, 'modes' => $modes,
            'reasons' => $risk[0]['reasons'] ?? []];
    }

    /**
     * Question difficulty report (FR-7.3): observed % correct against the % the
     * Elo model expected, given each student's rating when they answered.
     */
    public static function difficulty(?int $worldId, int $minAnswers = 1): array
    {
        $rows = Db::all(
            'SELECT q.id, q.type, q.prompt, q.topic, q.rating, w.number, COUNT(a.id) answers, AVG(a.correct) observed,
                    AVG(1 / (1 + POW(10, (q.rating - a.rating_before) / 400))) expected
               FROM questions q JOIN worlds w ON w.id = q.world_id
               JOIN attempts a ON a.question_id = q.id
               JOIN users u ON u.id = a.user_id AND u.role = "student"
              WHERE q.archived = 0' . ($worldId ? ' AND q.world_id = ' . (int) $worldId : '') . '
              GROUP BY q.id HAVING answers >= ?',
            [$minAnswers]
        );
        $out = array_map(function ($r) {
            $obs = (int) round($r['observed'] * 100);
            $exp = (int) round($r['expected'] * 100);
            $gap = $obs - $exp;
            $verdict = (int) $r['answers'] < 5 ? 'Too few answers'
                : ($gap <= -15 ? 'Harder than rated' : ($gap >= 15 ? 'Easier than rated' : 'As expected'));
            return ['id' => (int) $r['id'], 'world' => 'T' . $r['number'], 'type' => $r['type'], 'prompt' => $r['prompt'],
                'topic' => $r['topic'], 'rating' => (int) $r['rating'], 'tier' => Elo::tier((int) $r['rating']),
                'answers' => (int) $r['answers'], 'observed' => $obs, 'expected' => $exp, 'gap' => $gap, 'verdict' => $verdict];
        }, $rows);
        usort($out, fn ($a, $b) => $a['gap'] <=> $b['gap']); // most "harder than rated" first
        return $out;
    }
}
