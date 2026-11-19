<?php
declare(strict_types=1);

namespace NetForge\Learning;

use NetForge\Db;
use NetForge\GameError;

/**
 * Mock exams (FR-3.5): the student picks any open worlds and a length; the questions are
 * drawn up front, spread evenly across those worlds, and the whole paper is timed.
 * Like a real exam there's no feedback until the end, then the score by topic and by
 * world plus a review of every miss. The run itself is played by Challenge (mode "mock").
 */
final class Mock
{
    public const LENGTHS = [10, 20, 30];
    public const SECONDS_PER_QUESTION = 60;
    /** Score that counts as exam-ready. Only a guide: a mock exam never unlocks anything. */
    public const READY_PCT = 70;

    /** Worlds a mock exam can draw from, past results and the settings, for the Learn screen. */
    public static function overview(int $userId, array $roadmap): array
    {
        $counts = [];
        $generated = [];
        foreach (Db::all('SELECT world_id, COUNT(*) n, SUM(type IN ("blitz", "subnet")) g FROM questions WHERE archived = 0 GROUP BY world_id') as $r) {
            $counts[(int) $r['world_id']] = (int) $r['n'];
            $generated[(int) $r['world_id']] = (int) $r['g'] > 0; // generated questions never run out
        }
        $worlds = [];
        foreach ($roadmap as $w) {
            if (in_array($w['status'], ['open', 'mastered'], true) && ($counts[$w['id']] ?? 0) > 0) {
                $worlds[] = ['id' => $w['id'], 'number' => $w['number'], 'title' => $w['title'], 'questions' => $counts[$w['id']], 'generated' => $generated[$w['id']], 'status' => $w['status']];
            }
        }
        return [
            'worlds'  => $worlds,
            'lengths' => self::LENGTHS,
            'secondsPerQuestion' => self::SECONDS_PER_QUESTION,
            'readyPct' => self::READY_PCT,
            'history' => self::history($userId),
        ];
    }

    public static function history(int $userId, int $limit = 6): array
    {
        return array_map(fn ($r) => [
            'at' => $r['created_at'], 'worlds' => array_map('intval', explode(',', $r['worlds'])),
            'pct' => (int) $r['pct'], 'correct' => (int) $r['correct'], 'total' => (int) $r['total'],
            'seconds' => (int) $r['seconds'], 'timedOut' => (bool) $r['timed_out'],
        ], Db::all('SELECT * FROM mock_exams WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)), [$userId]));
    }

    /**
     * Builds the paper: checks the worlds, then deals questions round-robin from each
     * world's shuffled pool so every world gets an even share.
     * @param list<int> $worldIds
     * @return array{queue:list<int>, worlds:array<int,int>, total:int, minutes:int}
     */
    public static function build(int $userId, array $worldIds, int $length): array
    {
        if (!in_array($length, self::LENGTHS, true)) {
            throw new GameError('Pick 10, 20 or 30 questions.');
        }
        $worldIds = array_values(array_unique(array_map('intval', $worldIds)));
        if ($worldIds === []) {
            throw new GameError('Pick at least one world for the mock exam.');
        }
        $numbers = [];
        $pools = [];
        foreach ($worldIds as $id) {
            $w = Learning::requireOpen($userId, $id);
            $numbers[$id] = $w['number'];
            $rows = Db::all('SELECT id, type FROM questions WHERE world_id = ? AND archived = 0', [$id]);
            shuffle($rows);
            if ($rows === []) {
                throw new GameError("World {$w['number']} has no questions yet.");
            }
            $pools[$id] = $rows;
        }

        $queue = [];
        $used = array_fill_keys($worldIds, 0);
        while (count($queue) < $length) {
            $added = false;
            foreach ($worldIds as $id) {
                if (count($queue) >= $length) {
                    break;
                }
                $pool = $pools[$id];
                $i = $used[$id];
                if ($i < count($pool)) {
                    $queue[] = (int) $pool[$i]['id'];
                } else {
                    // out of fixed questions: generated ones (Binary Blitz, subnetting) are new every time
                    $gen = array_values(array_filter($pool, fn ($q) => in_array($q['type'], ['blitz', 'subnet'], true)));
                    if ($gen === []) {
                        continue;
                    }
                    $queue[] = (int) $gen[$i % count($gen)]['id'];
                }
                $used[$id]++;
                $added = true;
            }
            if (!$added) {
                break; // every chosen world has run out of questions
            }
        }
        if (count($queue) < min($length, 5)) {
            throw new GameError('Those worlds don’t have enough questions for a mock exam yet. Add another world.');
        }
        shuffle($queue); // mix the worlds instead of strict rotation
        $total = count($queue);
        return ['queue' => $queue, 'worlds' => $numbers, 'total' => $total, 'minutes' => (int) ceil($total * self::SECONDS_PER_QUESTION / 60)];
    }

    /** Saves a finished mock exam. */
    public static function record(int $userId, array $run, int $pct, bool $timedOut, array $topics): void
    {
        Db::exec(
            'INSERT INTO mock_exams (user_id, worlds, total, correct, pct, seconds, timed_out, topics) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, implode(',', array_values($run['worldNumbers'])), $run['total'], $run['correct'], $pct,
             min(65535, max(0, time() - $run['startedAt'])), (int) $timedOut, json_encode($topics)]
        );
    }
}
