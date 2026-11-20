<?php
declare(strict_types=1);

namespace NetForge;

use NetForge\Defence\Defence;
use NetForge\Progress\Income;

/**
 * Random timed events, once a student has moved past the garage (leased the office):
 *  - the grey-market broker follows them around; hear him out and he usually gives a
 *    temporary GPU discount, but sometimes he's a pickpocket;
 *  - investors text them an offer to invest; take it, turn it down or ask for more (they may walk away);
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
            'offer' => self::offer($userId),
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

    public const OFFER_MINUTES = 10;   // how long an investor's offer stands
    public const WALK_CAP = 0.8;       // the most likely an investor is to walk away when asked for more

    /**
     * What an investor offers: about 20 minutes of the company's income or 8% of its savings,
     * whichever is more, give or take 20%. Growing companies get a little more.
     */
    public static function offerFor(int $userId): int
    {
        $perSec = Income::summary($userId)['perSec'];
        $credits = (int) Db::one('SELECT credits FROM users WHERE id = ?', [$userId])['credits'];
        $base = max($perSec * 1200, $credits * 0.08, 2000);
        $first = Db::one('SELECT value FROM company_history WHERE user_id = ? AND at <= NOW() - INTERVAL 30 MINUTE ORDER BY at DESC LIMIT 1', [$userId]);
        $growing = $first && self::companyValue($userId) > (int) $first['value'] * 1.02;
        return (int) round($base * (random_int(80, 120) / 100) * ($growing ? 1.2 : 1.0));
    }

    /**
     * How likely the investor is to walk away if asked for more: the richer the company, the
     * less they need it, up to WALK_CAP once income reaches investor_walk_income (credits/s).
     * Each earlier ask makes it 10 points likelier.
     */
    public static function walkChance(int $userId, int $rounds): float
    {
        $walk = max(1, Db::configInt('investor_walk_income', 50000));
        $perSec = Income::summary($userId)['perSec'];
        $credits = (int) Db::one('SELECT credits FROM users WHERE id = ?', [$userId])['credits'];
        $wealth = min(1.0, max($perSec / $walk, $credits / ($walk * 3600)));
        return min(self::WALK_CAP, 0.15 + 0.65 * $wealth + 0.10 * $rounds);
    }

    private static function text(int $userId, string $sender, string $body, int $amount = 0): void
    {
        Db::exec('INSERT INTO messages (user_id, sender, body, amount, read_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $sender, $body, $amount, $sender === 'you' ? date('Y-m-d H:i:s') : null]);
    }

    /** An investor texts an offer to invest in the company; it stands for OFFER_MINUTES. */
    private static function investorText(int $userId): void
    {
        self::snapshot($userId);
        $amount = self::offerFor($userId);
        Db::exec('INSERT INTO game_events (user_id, kind, amount, expires_at) VALUES (?, "investor", ?, NOW() + INTERVAL ? MINUTE)',
            [$userId, $amount, self::OFFER_MINUTES]);
        self::text($userId, 'investor', sprintf('Hi! Mira from Lumen Capital. We like what you’re building and want to invest %s credits in your company. Interested?', Money::short($amount)));
        Rules::log($userId, 'info', 'An investor texted you an offer. Check your phone.');
    }

    /** The investor's current offer, if one stands: {id, amount, rounds, secondsLeft, walkPct}. */
    public static function offer(int $userId): ?array
    {
        $e = Db::one('SELECT id, amount, rounds, created_at, TIMESTAMPDIFF(SECOND, NOW(), expires_at) secs FROM game_events
                       WHERE user_id = ? AND kind = "investor" AND status = "active" AND expires_at >= NOW() ORDER BY id DESC LIMIT 1', [$userId]);
        return $e === null ? null : ['id' => (int) $e['id'], 'amount' => (int) $e['amount'], 'rounds' => (int) $e['rounds'], 'since' => $e['created_at'],
            'secondsLeft' => max(0, (int) $e['secs']), 'walkPct' => (int) round(self::walkChance($userId, (int) $e['rounds']) * 100)];
    }

    /**
     * The student's answer to an investor: "accept" (take the money), "more" (ask for a better
     * deal: they may raise it or walk away) or "decline".
     * @return array{outcome:string, amount:int, reply:string}
     */
    public static function answerInvestor(int $userId, int $eventId, string $choice): array
    {
        return Db::transaction(function () use ($userId, $eventId, $choice): array {
            $e = Db::one('SELECT id, amount, rounds FROM game_events WHERE id = ? AND user_id = ? AND kind = "investor" AND status = "active" AND expires_at >= NOW() FOR UPDATE',
                [$eventId, $userId]);
            if ($e === null) {
                throw new GameError('That offer has expired.');
            }
            $amount = (int) $e['amount'];
            $rounds = (int) $e['rounds'];
            $done = fn (string $outcome) => Db::exec('UPDATE game_events SET status = "done", outcome = ? WHERE id = ?', [$outcome, $eventId]);
            if ($choice === 'accept') {
                self::text($userId, 'you', 'Deal.');
                Db::exec('UPDATE users SET credits = credits + ? WHERE id = ?', [$amount, $userId]);
                $done('invested');
                $reply = sprintf('Wonderful. %s credits are on their way. Keep growing!', Money::short($amount));
                self::text($userId, 'investor', $reply, $amount);
                Rules::log($userId, 'ok', sprintf('Lumen Capital invested %s credits in your company.', Money::short($amount)));
                return ['outcome' => 'invested', 'amount' => $amount, 'reply' => $reply];
            }
            if ($choice === 'decline') {
                self::text($userId, 'you', 'No thanks.');
                $done('declined');
                $reply = 'Understood. We’ll be in touch.';
                self::text($userId, 'investor', $reply);
                return ['outcome' => 'declined', 'amount' => 0, 'reply' => $reply];
            }
            // asking for more: the richer the company, the likelier they walk
            self::text($userId, 'you', 'Can you do better?');
            if (random_int(1, 1000) <= (int) round(self::walkChance($userId, $rounds) * 1000)) {
                $done('walked');
                $reply = 'That’s more than we can justify. We’ll pass this time. Good luck!';
                self::text($userId, 'investor', $reply);
                Rules::log($userId, 'warn', 'You asked for more and the investor walked away.');
                return ['outcome' => 'walked', 'amount' => 0, 'reply' => $reply];
            }
            $new = (int) round($amount * random_int(120, 140) / 100);
            Db::exec('UPDATE game_events SET amount = ?, rounds = rounds + 1, expires_at = GREATEST(expires_at, NOW() + INTERVAL 2 MINUTE) WHERE id = ?', [$new, $eventId]);
            $reply = sprintf('You drive a hard bargain. %s credits, and that’s a serious offer.', Money::short($new));
            self::text($userId, 'investor', $reply);
            return ['outcome' => 'raised', 'amount' => $new, 'reply' => $reply];
        });
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
            Rules::log($userId, 'error', sprintf('The broker was a pickpocket: you lost %s credits.', Money::short($amount)));
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
            Db::all('SELECT * FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT 40', [$userId]));
        $history = array_map(fn ($h) => ['at' => $h['at'], 'value' => (int) $h['value']],
            array_reverse(Db::all('SELECT at, value FROM company_history WHERE user_id = ? ORDER BY at DESC LIMIT 48', [$userId])));
        $value = self::companyValue($userId);
        if ($history) {
            $history[] = ['at' => date('Y-m-d H:i:s'), 'value' => $value]; // the line ends at today's value, like the headline
        }
        return ['messages' => $msgs, 'history' => $history, 'value' => $value, 'eligible' => self::eligible($userId), 'offer' => self::offer($userId)];
    }

    public static function markRead(int $userId): void
    {
        Db::exec('UPDATE messages SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }
}
