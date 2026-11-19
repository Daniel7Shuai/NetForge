<?php
declare(strict_types=1);

namespace NetForge\Defence;

use NetForge\Db;
use NetForge\GameError;
use NetForge\Learning\Learning;
use NetForge\Rules;

/**
 * Cyber attack and defence (module 5.0).
 * Attacks arrive at random times while the student is playing (never during a lesson
 * or exam run), only of types whose world they've mastered (FR-5.1). Each is a timed
 * scenario (FR-5.2); a good defence protects the network, a bad or late one costs
 * credits (FR-5.3). The security rating summarises recent results (FR-5.4) and every
 * attack is kept for review (FR-5.5).
 */
final class Defence
{
    /** How many recent attacks the security rating looks at. */
    public const RATING_WINDOW = 10;

    public static function settings(): array
    {
        return [
            'enabled' => Db::configInt('attacks_enabled', 1) === 1,
            'min' => max(1, Db::configInt('attack_min_minutes', 20)),
            'max' => max(1, Db::configInt('attack_max_minutes', 60)),
            'seconds' => max(10, Db::configInt('attack_seconds', 60)),
            'penalty' => max(0, Db::configInt('attack_penalty', 75)),
        ];
    }

    /** Attack types this student can face: enabled, and their world is mastered. */
    public static function eligibleTypes(int $userId): array
    {
        $mastered = Learning::masteredNumbers($userId);
        return array_values(array_filter(
            Db::all('SELECT code, name, unlock_world FROM attack_types WHERE enabled = 1'),
            fn ($t) => in_array((int) $t['unlock_world'], $mastered, true)
        ));
    }

    /**
     * Called every few seconds by the game. Settles expired attacks, then returns the
     * attack in progress, starting a new one if one is due.
     */
    public static function poll(int $userId, bool $runActive): ?array
    {
        self::resolveExpired($userId);
        $pending = self::pending($userId);
        if ($pending !== null || $runActive) {
            return $pending;
        }
        $s = self::settings();
        if (!$s['enabled']) {
            return null;
        }
        $u = Db::one('SELECT next_attack_at, role FROM users WHERE id = ?', [$userId]);
        if ($u['role'] !== 'student') {
            return null;
        }
        if ($u['next_attack_at'] === null) {
            self::scheduleNext($userId); // first visit: start the clock, no attack straight away
            return null;
        }
        if (strtotime($u['next_attack_at']) > time() || !self::eligibleTypes($userId)) {
            return null;
        }
        self::launch($userId);
        return self::pending($userId);
    }

    /** Starts an attack now. $type limits it to one attack type (used for staff test attacks). */
    public static function launch(int $userId, ?string $type = null): void
    {
        $types = array_column(self::eligibleTypes($userId), 'code');
        if ($type !== null) {
            if (!Db::one('SELECT code FROM attack_types WHERE code = ?', [$type])) {
                throw new GameError('Unknown attack type.');
            }
            $types = [$type];
        }
        if ($types === []) {
            throw new GameError('This student hasn’t mastered any world with an attack type yet.');
        }
        if (self::pending($userId) !== null) {
            throw new GameError('An attack is already in progress for this student.');
        }
        // avoid repeating any of the last three scenarios
        $recent = array_column(Db::all('SELECT scenario_id FROM attacks WHERE user_id = ? ORDER BY id DESC LIMIT 3', [$userId]), 'scenario_id');
        $in = implode(',', array_fill(0, count($types), '?'));
        $pool = Db::all("SELECT id, options, type_code FROM attack_scenarios WHERE type_code IN ($in)", $types);
        $fresh = array_values(array_filter($pool, fn ($p) => !in_array($p['id'], $recent)));
        $pick = ($fresh ?: $pool)[random_int(0, count($fresh ?: $pool) - 1)];

        $order = range(0, count(json_decode($pick['options'], true)) - 1);
        shuffle($order);
        $s = self::settings();
        Db::exec(
            'INSERT INTO attacks (user_id, scenario_id, option_order, created_at, expires_at) VALUES (?, ?, ?, NOW(), NOW() + INTERVAL ? SECOND)',
            [$userId, $pick['id'], json_encode($order), $s['seconds']]
        );
        self::scheduleNext($userId);
        $name = Db::one('SELECT name FROM attack_types WHERE code = ?', [$pick['type_code']])['name'];
        Rules::log($userId, 'error', "Incoming attack: $name. Respond before the timer runs out.");
    }

    /** The student's response. $choice is the index of the option as shown to them. */
    public static function defend(int $userId, int $attackId, int $choice): array
    {
        return Db::transaction(function () use ($userId, $attackId, $choice): array {
            $a = Db::one(
                'SELECT a.*, s.options, s.explanation, t.name type_name FROM attacks a
                   JOIN attack_scenarios s ON s.id = a.scenario_id JOIN attack_types t ON t.code = s.type_code
                  WHERE a.id = ? AND a.user_id = ? FOR UPDATE',
                [$attackId, $userId]
            );
            if ($a === null) {
                throw new GameError('That attack isn’t yours.');
            }
            if ($a['status'] !== 'incoming') {
                throw new GameError('That attack is already over.');
            }
            $order = json_decode($a['option_order'], true);
            $options = json_decode($a['options'], true);
            $late = strtotime($a['expires_at']) + 2 < time(); // 2 s grace for the network
            $original = $order[$choice] ?? null;
            if ($original === null && !$late) {
                throw new GameError('Pick one of the responses.');
            }
            $defended = !$late && $original === 0; // option 0 is the correct defence
            $penalty = $defended ? 0 : self::charge($userId);
            Db::exec(
                'UPDATE attacks SET status = ?, chosen = ?, penalty = ?, resolved_at = NOW() WHERE id = ?',
                [$defended ? 'defended' : 'breached', $late ? null : $original, $penalty, $attackId]
            );
            Rules::log($userId, $defended ? 'ok' : 'error', $defended
                ? "{$a['type_name']} attack defended. Your network is safe."
                : ($late ? "Too late: {$a['type_name']} attack got through." : "{$a['type_name']} attack got through.") . ($penalty ? " Lost $penalty credits." : ''));
            return [
                'status' => $defended ? 'defended' : 'breached',
                'late' => $late,
                'correct' => $options[0],
                'explanation' => $a['explanation'],
                'penalty' => $penalty,
                'security' => self::rating($userId),
            ];
        });
    }

    /** Attacks nobody answered in time count as breaches. */
    public static function resolveExpired(int $userId): void
    {
        foreach (Db::all('SELECT a.id, t.name FROM attacks a JOIN attack_scenarios s ON s.id = a.scenario_id JOIN attack_types t ON t.code = s.type_code
                           WHERE a.user_id = ? AND a.status = "incoming" AND a.expires_at < NOW() - INTERVAL 2 SECOND', [$userId]) as $a) {
            Db::transaction(function () use ($userId, $a): void {
                $penalty = self::charge($userId);
                Db::exec('UPDATE attacks SET status = "breached", penalty = ?, resolved_at = NOW() WHERE id = ? AND status = "incoming"', [$penalty, $a['id']]);
                Rules::log($userId, 'error', "No response in time: {$a['name']} attack got through." . ($penalty ? " Lost $penalty credits." : ''));
            });
        }
    }

    /** The attack in progress, as the student sees it (correct answer not included). */
    public static function pending(int $userId): ?array
    {
        $a = Db::one(
            'SELECT a.id, a.option_order, a.expires_at, s.title, s.evidence, s.prompt, s.options, t.code, t.name, t.summary
               FROM attacks a JOIN attack_scenarios s ON s.id = a.scenario_id JOIN attack_types t ON t.code = s.type_code
              WHERE a.user_id = ? AND a.status = "incoming" ORDER BY a.id DESC LIMIT 1',
            [$userId]
        );
        if ($a === null) {
            return null;
        }
        $options = json_decode($a['options'], true);
        return [
            'id' => (int) $a['id'], 'type' => $a['code'], 'name' => $a['name'], 'summary' => $a['summary'],
            'title' => $a['title'], 'evidence' => $a['evidence'], 'prompt' => $a['prompt'],
            'options' => array_map(fn ($i) => $options[$i], json_decode($a['option_order'], true)),
            'secondsLeft' => max(0, strtotime($a['expires_at']) - time()),
        ];
    }

    /** Security rating (FR-5.4): recent defences, newer ones weighted more. */
    public static function rating(int $userId): ?array
    {
        $rows = Db::all(
            'SELECT status FROM attacks WHERE user_id = ? AND status <> "incoming" ORDER BY id DESC LIMIT ' . self::RATING_WINDOW,
            [$userId]
        );
        if (!$rows) {
            return null;
        }
        $num = $den = 0;
        foreach ($rows as $i => $r) {
            $w = self::RATING_WINDOW - $i;
            $den += $w;
            $num += $r['status'] === 'defended' ? $w : 0;
        }
        $pct = (int) round($num / $den * 100);
        $grade = match (true) { $pct >= 90 => 'A', $pct >= 75 => 'B', $pct >= 60 => 'C', $pct >= 40 => 'D', default => 'F' };
        return ['pct' => $pct, 'grade' => $grade, 'count' => count($rows)];
    }

    /** Past attacks with the review (FR-5.5). */
    public static function history(int $userId, int $limit = 20): array
    {
        return array_map(function ($r) {
            $opts = json_decode($r['options'], true);
            return [
                'id' => (int) $r['id'], 'name' => $r['name'], 'title' => $r['title'], 'status' => $r['status'],
                'at' => $r['created_at'], 'prompt' => $r['prompt'],
                'yours' => $r['chosen'] === null ? null : $opts[(int) $r['chosen']],
                'correct' => $opts[0], 'explanation' => $r['explanation'], 'penalty' => (int) $r['penalty'],
            ];
        }, Db::all(
            'SELECT a.id, a.status, a.chosen, a.penalty, a.created_at, s.title, s.prompt, s.options, s.explanation, t.name
               FROM attacks a JOIN attack_scenarios s ON s.id = a.scenario_id JOIN attack_types t ON t.code = s.type_code
              WHERE a.user_id = ? AND a.status <> "incoming" ORDER BY a.id DESC LIMIT ' . max(1, min(100, $limit)),
            [$userId]
        ));
    }

    private static function scheduleNext(int $userId): void
    {
        $s = self::settings();
        $minutes = random_int(min($s['min'], $s['max']), max($s['min'], $s['max']));
        Db::exec('UPDATE users SET next_attack_at = NOW() + INTERVAL ? MINUTE WHERE id = ?', [$minutes, $userId]);
    }

    /** Takes the breach penalty, never below zero credits. Returns what was taken. */
    private static function charge(int $userId): int
    {
        $credits = (int) Db::one('SELECT credits FROM users WHERE id = ? FOR UPDATE', [$userId])['credits'];
        $penalty = self::settings()['penalty'];
        $shield = \NetForge\Progress\Income::shield($userId);
        $take = min($credits, (int) round($penalty * (100 - $shield) / 100));
        if ($take > 0) {
            Db::exec('UPDATE users SET credits = credits - ? WHERE id = ?', [$take, $userId]);
        }
        if ($shield > 0) {
            \NetForge\Rules::log($userId, 'info', sprintf('Your firewall blocked %d%% of the breach.', $shield));
        }
        return $take;
    }
}
