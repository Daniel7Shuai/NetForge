<?php
declare(strict_types=1);

namespace NetForge;

use NetForge\Defence\Defence;
use NetForge\Progress\Income;

/**
 * Random timed events, once a student has moved past the garage (leased the office):
 *  - the grey-market broker follows them around; hear him out and he usually gives a
 *    temporary GPU discount, but sometimes he's a pickpocket;
 *  - investors text them, paying a dividend of their company value when it's growing.
 * Also the company value (the "stock") and its history for the chart on the phone.
 */
final class Events
{
    public const BROKER_SECONDS = 150;     // how long the broker hangs around
    public const DISCOUNT_MINUTES = 10;
    public const SNAPSHOT_MINUTES = 10;    // how often the stock chart gets a point

    public static function eligible(int $userId): bool
    {
        return Db::one('SELECT 1 FROM user_locations WHERE user_id = ? AND location = "office"', [$userId]) !== null;
    }

    /** The company's value: an hour of income, lifted by level and a good security rating, plus some cash. */
    public static function companyValue(int $userId): int
    {
        $u = Db::one('SELECT credits, level FROM users WHERE id = ?', [$userId]);
        $perSec = Income::summary($userId)['perSec'];
        $sec = Defence::rating($userId);
        $security = $sec ? $sec['pct'] / 100 : 0.5;
        return (int) round($perSec * 3600 * (1 + 0.1 * (int) $u['level']) * (0.6 + 0.4 * $security) + (int) $u['credits'] * 0.05);
    }

    private static function snapshot(int $userId): void
    {
        $last = Db::one('SELECT at FROM company_history WHERE user_id = ? ORDER BY at DESC LIMIT 1', [$userId]);
        if ($last === null || strtotime($last['at']) < time() - self::SNAPSHOT_MINUTES * 60) {
            Db::exec('INSERT IGNORE INTO company_history (user_id, at, value) VALUES (?, NOW(), ?)', [$userId, self::companyValue($userId)]);
        }
    }

    private static function schedule(int $userId): void
    {
        $min = max(1, Db::configInt('event_min_minutes', 4));
        $max = max($min, Db::configInt('event_max_minutes', 10));
        Db::exec('UPDATE users SET next_event_at = NOW() + INTERVAL ? MINUTE WHERE id = ?', [random_int($min, $max), $userId]);
    }

    public static function active(int $userId): ?array
    {
        $e = Db::one('SELECT id, kind, expires_at, TIMESTAMPDIFF(SECOND, NOW(), expires_at) secs FROM game_events WHERE user_id = ? AND status = "active" ORDER BY id DESC LIMIT 1', [$userId]);
        return $e === null ? null : ['id' => (int) $e['id'], 'kind' => $e['kind'], 'secondsLeft' => max(0, (int) $e['secs'])];
    }

    /**
     * Called by the game every few seconds. Starts an event when one is due (only when
     * $mayStart: from the game itself, and not during a lesson). Returns what the game shows.
     */
    public static function poll(int $userId, bool $mayStart): array
    {
        Db::exec('UPDATE game_events SET status = "left" WHERE user_id = ? AND status = "active" AND expires_at < NOW()', [$userId]);
        $eligible = self::eligible($userId) && (Db::one('SELECT role FROM users WHERE id = ?', [$userId])['role'] ?? '') === 'student';
        if ($eligible) {
            self::snapshot($userId);
            $u = Db::one('SELECT next_event_at FROM users WHERE id = ?', [$userId]);
            if ($u['next_event_at'] === null) {
                self::schedule($userId); // first time past the garage: start the clock
            } elseif ($mayStart && self::active($userId) === null && strtotime($u['next_event_at']) <= time()) {
                self::start($userId, random_int(0, 1) ? 'broker' : 'investor');
            }
        }
        return [
            'event' => self::active($userId),
            'unread' => (int) Db::one('SELECT COUNT(*) n FROM messages WHERE user_id = ? AND read_at IS NULL', [$userId])['n'],
            'buffs' => self::buffs($userId),
        ];
    }

    /** Starts an event now (also used by staff to demo one). */
    public static function start(int $userId, string $kind): void
    {
        self::schedule($userId);
        if ($kind === 'investor') {
            self::investorText($userId);
            return;
        }
        Db::exec('INSERT INTO game_events (user_id, kind, expires_at) VALUES (?, "broker", NOW() + INTERVAL ? SECOND)', [$userId, self::BROKER_SECONDS]);
    }

    /** An investor texts: a dividend when the company's value is growing. */
    private static function investorText(int $userId): void
    {
        self::snapshot($userId);
        $now = self::companyValue($userId);
        $then = Db::one('SELECT value FROM company_history WHERE user_id = ? AND at <= NOW() - INTERVAL 30 MINUTE ORDER BY at DESC LIMIT 1', [$userId])
            ?? Db::one('SELECT value FROM company_history WHERE user_id = ? ORDER BY at LIMIT 1', [$userId]);
        $was = max(1, (int) ($then['value'] ?? $now));
        $growth = ($now - $was) / $was;
        $pct = $growth > 0.02 ? min(0.08, 0.03 + $growth * 0.2) : ($growth >= 0 ? 0.01 : 0.0);
        $amount = (int) round($now * $pct);
        $change = (int) round($growth * 100);
        if ($amount > 0) {
            Db::exec('UPDATE users SET credits = credits + ? WHERE id = ?', [$amount, $userId]);
            $body = $growth > 0.02
                ? sprintf('Your company’s up %d%%. Nice work! Our fund is sending a dividend of %s credits. Keep it growing.', $change, number_format($amount))
                : sprintf('Steady numbers this round. Here’s a small dividend of %s credits. Grow your income to earn more.', number_format($amount));
        } else {
            $body = sprintf('Your company’s value dipped %d%%. No dividend this time. Keep your network online and your security rating up.', abs($change));
        }
        Db::exec('INSERT INTO messages (user_id, sender, body, amount) VALUES (?, "investor", ?, ?)', [$userId, $body, $amount]);
        Db::exec('INSERT INTO game_events (user_id, kind, status, outcome, amount) VALUES (?, "investor", "done", ?, ?)', [$userId, $amount > 0 ? 'dividend' : 'none', $amount]);
        if ($amount > 0) {
            Rules::log($userId, 'ok', sprintf('An investor sent you %s credits. Check your phone.', number_format($amount)));
        }
    }

    /**
     * The student's answer to the broker: "accept" (hear him out) or "refuse".
     * @return array{outcome:string, pct:int, minutes:int, amount:int}
     */
    public static function answerBroker(int $userId, int $eventId, string $choice): array
    {
        return Db::transaction(function () use ($userId, $eventId, $choice): array {
            $e = Db::one('SELECT id FROM game_events WHERE id = ? AND user_id = ? AND kind = "broker" AND status = "active" AND expires_at >= NOW() FOR UPDATE', [$eventId, $userId]);
            if ($e === null) {
                throw new GameError('The broker’s gone.');
            }
            if ($choice !== 'accept') {
                Db::exec('UPDATE game_events SET status = "done", outcome = "refused" WHERE id = ?', [$eventId]);
                return ['outcome' => 'refused', 'pct' => 0, 'minutes' => 0, 'amount' => 0];
            }
            if (random_int(1, 100) <= 70) { // usually a real deal
                $pct = [20, 30, 40][random_int(0, 2)];
                Db::exec('INSERT INTO user_buffs (user_id, kind, pct, expires_at) VALUES (?, "gpu_discount", ?, NOW() + INTERVAL ? MINUTE)
                          ON DUPLICATE KEY UPDATE pct = GREATEST(pct, VALUES(pct)), expires_at = VALUES(expires_at)', [$userId, $pct, self::DISCOUNT_MINUTES]);
                Db::exec('UPDATE game_events SET status = "done", outcome = "deal" WHERE id = ?', [$eventId]);
                Rules::log($userId, 'ok', sprintf('The broker cut GPU prices by %d%% for %d minutes.', $pct, self::DISCOUNT_MINUTES));
                return ['outcome' => 'deal', 'pct' => $pct, 'minutes' => self::DISCOUNT_MINUTES, 'amount' => 0];
            }
            $credits = (int) Db::one('SELECT credits FROM users WHERE id = ? FOR UPDATE', [$userId])['credits'];
            $amount = min($credits, max(500, (int) round($credits * random_int(3, 6) / 100)));
            Db::exec('UPDATE users SET credits = credits - ? WHERE id = ?', [$amount, $userId]);
            Db::exec('UPDATE game_events SET status = "done", outcome = "scam", amount = ? WHERE id = ?', [$amount, $eventId]);
            Rules::log($userId, 'error', sprintf('The broker was a pickpocket: you lost %s credits.', number_format($amount)));
            return ['outcome' => 'scam', 'pct' => 0, 'minutes' => 0, 'amount' => $amount];
        });
    }

    public static function buffs(int $userId): array
    {
        return array_map(fn ($b) => ['kind' => $b['kind'], 'pct' => (int) $b['pct'], 'secondsLeft' => max(0, (int) $b['secs'])],
            Db::all('SELECT kind, pct, TIMESTAMPDIFF(SECOND, NOW(), expires_at) secs FROM user_buffs WHERE user_id = ? AND expires_at > NOW()', [$userId]));
    }

    /** The phone: texts (newest first), and the company value history for the chart. */
    public static function phone(int $userId): array
    {
        $msgs = array_map(fn ($m) => ['id' => (int) $m['id'], 'sender' => $m['sender'], 'body' => $m['body'], 'amount' => (int) $m['amount'], 'at' => $m['created_at'], 'read' => $m['read_at'] !== null],
            Db::all('SELECT * FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$userId]));
        $history = array_map(fn ($h) => ['at' => $h['at'], 'value' => (int) $h['value']],
            array_reverse(Db::all('SELECT at, value FROM company_history WHERE user_id = ? ORDER BY at DESC LIMIT 48', [$userId])));
        $value = self::companyValue($userId);
        if ($history) {
            $history[] = ['at' => date('Y-m-d H:i:s'), 'value' => $value]; // the line ends at today's value, like the headline
        }
        return ['messages' => $msgs, 'history' => $history, 'value' => $value, 'eligible' => self::eligible($userId)];
    }

    public static function markRead(int $userId): void
    {
        Db::exec('UPDATE messages SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }
}
