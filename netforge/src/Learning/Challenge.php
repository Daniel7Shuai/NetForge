<?php
declare(strict_types=1);

namespace NetForge\Learning;

use NetForge\Db;
use NetForge\GameError;
use NetForge\Rules;

/**
 * A challenge run (a level's practice set, or a world exam) lives in the PHP
 * session, so answer keys and timers stay on the server (NFR-4).
 * Every answer updates Elo, pays credits and XP from rating gains only, and
 * sends wrong answers to the Daily Security Audit queue.
 */
final class Challenge
{
    private const KEY = 'nf_run';
    private const GRACE_SECONDS = 2;

    public static function startPractice(int $userId, int $levelId): array
    {
        $l = Learning::lesson($userId, $levelId); // checks the world is open
        $w = Learning::world($userId, $l['world']['id']);
        return self::begin($userId, [
            'mode' => 'practice', 'worldId' => $w['id'], 'worldNumber' => $w['number'], 'levelId' => $levelId,
            'title' => $l['title'], 'total' => $l['questions'], 'passPct' => $l['passPct'], 'deadline' => null,
        ]);
    }

    public static function startExam(int $userId, int $worldId): array
    {
        $w = Learning::requireOpen($userId, $worldId);
        if (!$w['exam']['ready']) {
            throw new GameError('Finish every level in this world to unlock its exam.');
        }
        return self::begin($userId, [
            'mode' => 'exam', 'worldId' => $w['id'], 'worldNumber' => $w['number'], 'levelId' => null,
            'title' => "World {$w['number']} exam", 'total' => $w['exam']['questions'], 'passPct' => $w['exam']['passPct'],
            'deadline' => time() + $w['exam']['minutes'] * 60,
        ]);
    }

    /** Daily Security Audit: up to 10 questions whose review day has come (FR-3.4). */
    public static function startAudit(int $userId): array
    {
        $due = array_map('intval', array_column(Db::all(
            'SELECT a.question_id FROM audit_queue a JOIN questions q ON q.id = a.question_id
              WHERE a.user_id = ? AND a.due_on <= CURDATE() AND q.archived = 0
              ORDER BY a.due_on, a.stage, a.question_id LIMIT ' . self::AUDIT_MAX,
            [$userId]
        ), 'question_id'));
        if ($due === []) {
            throw new GameError('Nothing is due for review today.');
        }
        return self::begin($userId, [
            'mode' => 'audit', 'worldId' => null, 'worldNumber' => null, 'levelId' => null,
            'title' => 'Daily Security Audit', 'total' => count($due), 'passPct' => 0, 'deadline' => null,
            'queue' => $due, 'cleared' => 0, 'advanced' => 0,
        ]);
    }

    private const AUDIT_MAX = 10;
    /** Days until the next review after a correct answer at each step; past step 3 the question is cleared. */
    private const AUDIT_NEXT = [1 => [2, 3], 2 => [3, 7]];

    private static function begin(int $userId, array $run): array
    {
        $run += ['index' => 0, 'correct' => 0, 'asked' => [], 'topics' => [], 'credits' => 0, 'xp' => 0, 'served' => null, 'queue' => []];
        $run['rating'] = $run['worldId'] === null ? null
            : (int) Db::transaction(fn () => Learning::lockProgress($userId, $run['worldId']))['rating'];
        $run['ratingStart'] = $run['rating'];
        $_SESSION[self::KEY] = $run;
        $question = self::serveNext($run, $userId);
        return ['run' => self::publicRun($run), 'question' => $question];
    }

    public static function quit(): void
    {
        unset($_SESSION[self::KEY]);
    }

    /** Marks one answer, then returns feedback plus the next question or the run summary. */
    public static function answer(int $userId, mixed $given): array
    {
        $run = $_SESSION[self::KEY] ?? null;
        if (!$run || !$run['served']) {
            throw new GameError('There’s no challenge in progress. Start one from Learn.');
        }
        if ($run['deadline'] !== null && time() > $run['deadline'] + self::GRACE_SECONDS) {
            return ['result' => null, 'summary' => self::finish($userId, $run, true)];
        }

        $served = $run['served'];
        $q = Db::one('SELECT id, world_id, type, rating, explanation, topic FROM questions WHERE id = ?', [$served['qid']]);
        $worldId = (int) $q['world_id']; // in an audit, each question can come from a different world
        $graded = Grader::grade($q['type'], $served['view'], $served['key'], $given);
        $elapsed = time() - $served['at'];
        $late = $served['seconds'] !== null && $elapsed > $served['seconds'] + self::GRACE_SECONDS;
        $correct = $graded['correct'] && !$late;

        $outcome = Db::transaction(function () use ($userId, $run, $q, $correct, $elapsed, $worldId) {
            $p = Learning::lockProgress($userId, $worldId);
            $before = (int) $p['rating'];
            $qRating = (int) Db::one('SELECT rating FROM questions WHERE id = ? FOR UPDATE', [$q['id']])['rating'];
            $u = Elo::update($before, $qRating, $correct);
            $reward = Elo::rewards($before, $u['student']);

            Db::exec('UPDATE questions SET rating = ?, answered = answered + 1 WHERE id = ?', [$u['question'], $q['id']]);
            Db::exec('UPDATE world_progress SET rating = ?, answered = answered + 1 WHERE user_id = ? AND world_id = ?', [$u['student'], $userId, $worldId]);
            Db::exec(
                'INSERT INTO attempts (user_id, question_id, mode, correct, rating_before, rating_after, credits, xp, seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $q['id'], $run['mode'], (int) $correct, $before, $u['student'], $reward['credits'], $reward['xp'], min($elapsed, 65535)]
            );
            if ($reward['credits']) {
                Db::exec('UPDATE users SET credits = credits + ? WHERE id = ?', [$reward['credits'], $userId]);
            }
            Rules::addXp($userId, $reward['xp']); // XP raises the level that unlocks shop items
            $audit = null;
            if (!$correct) {
                // Daily Security Audit: back tomorrow, then after 3 and 7 days (FR-3.4)
                Db::exec(
                    'INSERT INTO audit_queue (user_id, question_id, stage, due_on) VALUES (?, ?, 1, CURDATE() + INTERVAL 1 DAY)
                     ON DUPLICATE KEY UPDATE stage = 1, due_on = CURDATE() + INTERVAL 1 DAY',
                    [$userId, $q['id']]
                );
                $audit = $run['mode'] === 'audit' ? 'reset' : 'queued';
            } elseif ($run['mode'] === 'audit') {
                $row = Db::one('SELECT stage FROM audit_queue WHERE user_id = ? AND question_id = ? FOR UPDATE', [$userId, $q['id']]);
                $stage = (int) ($row['stage'] ?? 3);
                if (isset(self::AUDIT_NEXT[$stage])) {
                    [$next, $days] = self::AUDIT_NEXT[$stage];
                    Db::exec('UPDATE audit_queue SET stage = ?, due_on = CURDATE() + INTERVAL ? DAY WHERE user_id = ? AND question_id = ?', [$next, $days, $userId, $q['id']]);
                    $audit = "next:$days";
                } else {
                    Db::exec('DELETE FROM audit_queue WHERE user_id = ? AND question_id = ?', [$userId, $q['id']]);
                    $audit = 'cleared';
                }
            }
            return ['before' => $before, 'after' => $u['student'], 'audit' => $audit] + $reward;
        });
        if ($correct) {
            \NetForge\Social\Social::recordCorrect($userId, $worldId); // guild raid tally (FR-6.2)
        }
        if ($run['mode'] === 'audit') {
            $run['cleared'] += (int) ($outcome['audit'] === 'cleared');
            $run['advanced'] += (int) str_starts_with((string) $outcome['audit'], 'next:');
        }

        $run['index']++;
        $run['correct'] += (int) $correct;
        $run['credits'] += $outcome['credits'];
        $run['xp'] += $outcome['xp'];
        $run['rating'] = $outcome['after'];
        $t = $q['topic'];
        $run['topics'][$t] = [($run['topics'][$t][0] ?? 0) + (int) $correct, ($run['topics'][$t][1] ?? 0) + 1];
        $run['served'] = null;

        $result = [
            'correct'      => $correct,
            'late'         => $late,
            'answerText'   => $graded['answerText'],
            'explanation'  => $q['explanation'],
            'ratingBefore' => $outcome['before'],
            'ratingAfter'  => $outcome['after'],
            'credits'      => $outcome['credits'],
            'xp'           => $outcome['xp'],
            'audit'        => $outcome['audit'], // queued | reset | next:<days> | cleared | null
        ];

        if ($run['index'] >= $run['total']) {
            return ['result' => $result, 'summary' => self::finish($userId, $run, false)];
        }
        $_SESSION[self::KEY] = $run;
        $next = self::serveNext($run, $userId);
        return ['result' => $result, 'run' => self::publicRun($run), 'question' => $next];
    }

    /** Next audit question in due order, with the student's rating in that question's world. */
    private static function serveAudit(array &$run, int $userId): array
    {
        $qid = $run['queue'][$run['index']] ?? null;
        $q = $qid === null ? null : Db::one('SELECT * FROM questions WHERE id = ?', [$qid]);
        if ($q === null) {
            throw new GameError('That review question is no longer available.');
        }
        $run['rating'] = (int) Db::transaction(fn () => Learning::lockProgress($userId, (int) $q['world_id']))['rating'];
        $run['ratingStart'] ??= $run['rating'];
        return $q;
    }

    /** Picks the unasked question rated closest to the student (top 3, random), and remembers its key. */
    private static function serveNext(array &$run, int $userId): array
    {
        if ($run['mode'] === 'audit') {
            return self::serveQuestion($run, self::serveAudit($run, $userId));
        }
        $where = $run['mode'] === 'practice' ? 'level_id = ?' : 'world_id = ?';
        $pool = Db::all("SELECT * FROM questions WHERE $where AND archived = 0", [$run['mode'] === 'practice' ? $run['levelId'] : $run['worldId']]);
        $fresh = array_values(array_filter($pool, fn ($q) => !in_array((int) $q['id'], $run['asked'], true)));
        if ($fresh === []) {
            // generators make a new question every time, so they may repeat
            $fresh = array_values(array_filter($pool, fn ($q) => in_array($q['type'], ['blitz', 'subnet'], true)));
        }
        if ($fresh === []) {
            $fresh = $pool; // tiny pool: allow repeats rather than stall
        }
        if ($fresh === []) {
            throw new GameError('This level has no questions yet.');
        }
        usort($fresh, fn ($a, $b) => abs($a['rating'] - $run['rating']) <=> abs($b['rating'] - $run['rating']));
        return self::serveQuestion($run, $fresh[random_int(0, min(2, count($fresh) - 1))]);
    }

    private static function serveQuestion(array &$run, array $q): array
    {
        $s = Grader::serve($q);
        $run['asked'][] = (int) $q['id'];
        $run['served'] = ['qid' => (int) $q['id'], 'view' => $s['view'], 'key' => $s['key'], 'seconds' => $s['seconds'], 'at' => time()];
        $_SESSION[self::KEY] = $run;
        return $s['view'] + ['seconds' => $s['seconds'], 'tier' => Elo::tier((int) $q['rating'])];
    }

    private static function publicRun(array $run): array
    {
        return [
            'mode' => $run['mode'], 'title' => $run['title'], 'index' => $run['index'], 'total' => $run['total'],
            'correct' => $run['correct'], 'rating' => $run['rating'], 'tier' => Elo::tier($run['rating']),
            'secondsLeft' => $run['deadline'] === null ? null : max(0, $run['deadline'] - time()),
        ];
    }

    private static function finish(int $userId, array $run, bool $timedOut): array
    {
        unset($_SESSION[self::KEY]);
        $pct = (int) round($run['correct'] / max(1, $run['total']) * 100);
        $passed = $pct >= $run['passPct'];
        $unlocked = [];
        $newlyMastered = false;

        Db::transaction(function () use ($userId, $run, $pct, $passed, &$unlocked, &$newlyMastered): void {
            if ($run['mode'] === 'audit') {
                $parts = array_filter([
                    $run['cleared'] ? "{$run['cleared']} cleared for good" : null,
                    $run['advanced'] ? "{$run['advanced']} moved to a later review" : null,
                    $run['total'] - $run['correct'] ? ($run['total'] - $run['correct']) . ' back tomorrow' : null,
                ]);
                Rules::log($userId, 'ok', sprintf('Daily Security Audit: %d of %d right. %s.', $run['correct'], $run['total'], ucfirst(implode(', ', $parts))));
                return;
            }
            if ($run['mode'] === 'practice') {
                Db::exec(
                    'INSERT INTO level_progress (user_id, level_id, best_pct, completed_at) VALUES (?, ?, ?, IF(?, NOW(), NULL))
                     ON DUPLICATE KEY UPDATE best_pct = GREATEST(best_pct, VALUES(best_pct)),
                       completed_at = COALESCE(completed_at, VALUES(completed_at))',
                    [$userId, $run['levelId'], $pct, (int) $passed]
                );
                Rules::log($userId, $passed ? 'ok' : 'warn', sprintf('%s: %d%%%s', $run['title'], $pct, $passed ? '. Level complete.' : sprintf('. Score %d%% to complete it.', $run['passPct'])));
                return;
            }
            $p = Learning::lockProgress($userId, $run['worldId']);
            $newlyMastered = $passed && $p['mastered_at'] === null;
            Db::exec(
                'UPDATE world_progress SET exam_best = GREATEST(COALESCE(exam_best, 0), ?), mastered_at = IF(? AND mastered_at IS NULL, NOW(), mastered_at)
                  WHERE user_id = ? AND world_id = ?',
                [$pct, (int) $passed, $userId, $run['worldId']]
            );
            if ($newlyMastered) {
                $unlocked = array_column(Db::all(
                    'SELECT t.name FROM shop_items s JOIN item_types t ON t.code = s.item_code WHERE s.unlock_world = ? ORDER BY s.sort_order',
                    [$run['worldNumber']]
                ), 'name');
                Rules::log($userId, 'ok', sprintf('World %d mastered.%s', $run['worldNumber'], $unlocked ? ' New in the shop: ' . implode(', ', $unlocked) . '.' : ''));
            } else {
                Rules::log($userId, $passed ? 'ok' : 'warn', sprintf('%s: %d%%. %s', $run['title'], $pct, $passed ? 'Passed.' : sprintf('You need %d%% to pass.', $run['passPct'])));
            }
        });
        Rules::checkObjective($userId);

        $topics = [];
        foreach ($run['topics'] as $name => [$right, $asked]) {
            $topics[] = ['topic' => $name, 'correct' => $right, 'total' => $asked];
        }
        return [
            'mode' => $run['mode'], 'title' => $run['title'], 'pct' => $pct, 'passed' => $passed, 'passPct' => $run['passPct'],
            'correct' => $run['correct'], 'total' => $run['total'], 'timedOut' => $timedOut,
            'ratingBefore' => $run['ratingStart'], 'ratingAfter' => $run['rating'], 'tier' => Elo::tier($run['rating']),
            'credits' => $run['credits'], 'xp' => $run['xp'], 'topics' => $topics,
            'mastered' => $newlyMastered, 'unlocked' => $unlocked,
            'cleared' => $run['cleared'] ?? 0, 'advanced' => $run['advanced'] ?? 0,
        ];
    }
}
