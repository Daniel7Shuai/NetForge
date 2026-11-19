<?php
declare(strict_types=1);

namespace NetForge\Learning;

use NetForge\Db;
use NetForge\GameError;

/** The roadmap: which worlds and levels a student can see, and their progress. */
final class Learning
{
    /** Full roadmap for the Learn screen. */
    public static function roadmap(int $userId): array
    {
        $worlds = Db::all('SELECT * FROM worlds WHERE archived = 0 ORDER BY number');
        $levels = Db::all('SELECT id, world_id, sort_order, title, questions_per_run, pass_pct FROM levels WHERE archived = 0 ORDER BY world_id, sort_order');
        $lp = [];
        foreach (Db::all('SELECT level_id, best_pct, completed_at FROM level_progress WHERE user_id = ?', [$userId]) as $r) {
            $lp[(int) $r['level_id']] = $r;
        }
        $wp = [];
        foreach (Db::all('SELECT * FROM world_progress WHERE user_id = ?', [$userId]) as $r) {
            $wp[(int) $r['world_id']] = $r;
        }

        $out = [];
        $open = true; // a world is open once every earlier world that has content is mastered
        foreach ($worlds as $w) {
            $wid = (int) $w['id'];
            $mine = array_values(array_filter($levels, fn ($l) => (int) $l['world_id'] === $wid));
            $lvls = array_map(fn ($l, $i) => [
                'id'        => (int) $l['id'],
                'number'    => $i + 1,
                'title'     => $l['title'],
                'questions' => (int) $l['questions_per_run'],
                'passPct'   => (int) $l['pass_pct'],
                'bestPct'   => isset($lp[(int) $l['id']]) ? (int) $lp[(int) $l['id']]['best_pct'] : null,
                'completed' => !empty($lp[(int) $l['id']]['completed_at']),
            ], $mine, array_keys($mine));

            $p = $wp[$wid] ?? null;
            $rating = $p ? (int) $p['rating'] : Elo::START_RATING;
            $mastered = $p && $p['mastered_at'] !== null;
            $hasContent = $lvls !== [];
            $status = !$hasContent ? 'soon' : ($mastered ? 'mastered' : ($open ? 'open' : 'locked'));

            $out[] = [
                'id'          => $wid,
                'number'      => (int) $w['number'],
                'title'       => $w['title'],
                'summary'     => $w['summary'],
                'ccnaModules' => $w['ccna_modules'],
                'status'      => $status,
                'rating'      => $rating,
                'tier'        => Elo::tier($rating),
                'levels'      => $lvls,
                'exam'        => [
                    'questions' => (int) $w['exam_questions'],
                    'minutes'   => (int) $w['exam_minutes'],
                    'passPct'   => (int) $w['exam_pass_pct'],
                    'best'      => $p && $p['exam_best'] !== null ? (int) $p['exam_best'] : null,
                    'ready'     => $hasContent && !in_array(false, array_column($lvls, 'completed'), true),
                ],
            ];
            if ($hasContent && !$mastered) {
                $open = false;
            }
        }
        return $out;
    }

    public static function world(int $userId, int $worldId): array
    {
        foreach (self::roadmap($userId) as $w) {
            if ($w['id'] === $worldId) {
                return $w;
            }
        }
        throw new GameError('That world doesn’t exist.');
    }

    /** Throws unless the student may play in this world. */
    public static function requireOpen(int $userId, int $worldId): array
    {
        $w = self::world($userId, $worldId);
        if ($w['status'] === 'locked') {
            throw new GameError('Master the earlier worlds first.');
        }
        if ($w['status'] === 'soon') {
            throw new GameError('This world has no lessons yet.');
        }
        return $w;
    }

    public static function lesson(int $userId, int $levelId): array
    {
        $l = Db::one('SELECT id, world_id, title, lesson, questions_per_run, pass_pct FROM levels WHERE id = ? AND archived = 0', [$levelId]);
        if ($l === null) {
            throw new GameError('That lesson doesn’t exist.');
        }
        $w = self::requireOpen($userId, (int) $l['world_id']);
        return [
            'id'        => (int) $l['id'],
            'title'     => $l['title'],
            'lesson'    => $l['lesson'],
            'questions' => (int) $l['questions_per_run'],
            'passPct'   => (int) $l['pass_pct'],
            'world'     => ['id' => $w['id'], 'number' => $w['number'], 'title' => $w['title']],
        ];
    }

    /** Daily Security Audit overview: what's due now and what's coming (FR-3.4). */
    public static function audit(int $userId): array
    {
        $rows = Db::all(
            'SELECT a.stage, a.due_on, q.topic, w.number FROM audit_queue a
               JOIN questions q ON q.id = a.question_id JOIN worlds w ON w.id = q.world_id
              WHERE a.user_id = ? AND q.archived = 0 ORDER BY a.due_on',
            [$userId]
        );
        $today = date('Y-m-d');
        $due = array_values(array_filter($rows, fn ($r) => $r['due_on'] <= $today));
        $later = array_values(array_filter($rows, fn ($r) => $r['due_on'] > $today));
        $upcoming = [];
        foreach ($later as $r) {
            $upcoming[$r['due_on']] = ($upcoming[$r['due_on']] ?? 0) + 1;
        }
        $topics = [];
        foreach ($due as $r) {
            $topics[$r['topic']] = ($topics[$r['topic']] ?? 0) + 1;
        }
        arsort($topics);
        return [
            'due'      => count($due),
            'total'    => count($rows),
            'stages'   => array_map(fn ($s) => count(array_filter($rows, fn ($r) => (int) $r['stage'] === $s)), [1 => 1, 2 => 2, 3 => 3]),
            'upcoming' => array_map(fn ($d, $n) => ['date' => $d, 'count' => $n], array_keys($upcoming), $upcoming),
            'topics'   => array_map(fn ($t, $n) => ['topic' => $t, 'count' => $n], array_keys($topics), $topics),
            'perRun'   => 10,
        ];
    }

    public static function auditDue(int $userId): int
    {
        return (int) Db::one(
            'SELECT COUNT(*) n FROM audit_queue a JOIN questions q ON q.id = a.question_id
              WHERE a.user_id = ? AND a.due_on <= CURDATE() AND q.archived = 0',
            [$userId]
        )['n'];
    }

    /** World numbers this student has mastered (used for shop unlocks). */
    public static function masteredNumbers(int $userId): array
    {
        return array_map('intval', array_column(Db::all(
            'SELECT w.number FROM world_progress p JOIN worlds w ON w.id = p.world_id WHERE p.user_id = ? AND p.mastered_at IS NOT NULL',
            [$userId]
        ), 'number'));
    }

    /** Creates the progress row if missing and locks it for this transaction. */
    public static function lockProgress(int $userId, int $worldId): array
    {
        Db::exec('INSERT IGNORE INTO world_progress (user_id, world_id, rating) VALUES (?, ?, ?)', [$userId, $worldId, Elo::START_RATING]);
        return Db::one('SELECT * FROM world_progress WHERE user_id = ? AND world_id = ? FOR UPDATE', [$userId, $worldId]);
    }
}
