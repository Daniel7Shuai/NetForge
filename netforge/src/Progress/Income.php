<?php
declare(strict_types=1);

namespace NetForge\Progress;

use NetForge\Db;
use NetForge\Rules;

/**
 * Passive income (FR-4.4). A device earns its item type's income_per_sec (credits per second) only while
 * it is online: joined to the ISP box by a chain of working (up) links. So income
 * rewards a network that actually works, not just equipment on shelves.
 * Income builds up for at most income_cap_hours while the student is away.
 */
final class Income
{
    /** The building's internet connections: income flows out from these. */
    public const ISP = ['isp_box', 'isp_fiber'];

    /** Devices reachable from an ISP box over up links (the ISP box itself excluded). */
    public static function online(int $userId): array
    {
        $devices = [];
        foreach (Db::all('SELECT d.id, d.label, d.item_code, d.tier, t.income_per_sec, t.shield_pct, COALESCE(g.boost_pct, 0) gpu_boost FROM devices d JOIN item_types t ON t.code = d.item_code LEFT JOIN item_types g ON g.code = d.gpu_card WHERE d.user_id = ?', [$userId]) as $d) {
            $devices[(int) $d['id']] = $d;
        }
        $adj = [];
        foreach (Db::all('SELECT a_device_id a, b_device_id b FROM cables WHERE user_id = ? AND status = "up"', [$userId]) as $c) {
            $adj[(int) $c['a']][] = (int) $c['b'];
            $adj[(int) $c['b']][] = (int) $c['a'];
        }
        $queue = array_keys(array_filter($devices, fn ($d) => in_array($d['item_code'], self::ISP, true)));
        $seen = array_fill_keys($queue, true);
        while ($queue) {
            $id = array_shift($queue);
            foreach ($adj[$id] ?? [] as $next) {
                if (!isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }
        return array_values(array_filter(
            array_intersect_key($devices, $seen),
            fn ($d) => !in_array($d['item_code'], self::ISP, true)
        ));
    }

    /** Enough credits for a pack of copper, so the laptop can be linked straight to the ISP box. */
    public const RESCUE_CREDITS = 300;

    /**
     * Safety net: money only comes from devices online, so a player with nothing online,
     * no cable to fix that and too few credits to buy one would be stuck for good.
     * Once a day they get just enough for a pack of copper cable. Runs inside accrue()'s transaction.
     */
    private static function rescue(int $userId): int
    {
        $credits = (int) Db::one('SELECT credits FROM users WHERE id = ?', [$userId])['credits'];
        $cables = (int) Db::one('SELECT COALESCE(SUM(quantity), 0) n FROM inventory WHERE user_id = ? AND item_code IN ("copper","crossover")', [$userId])['n'];
        $recent = Db::one('SELECT 1 FROM event_log WHERE user_id = ? AND message LIKE "Your ISP sent%" AND created_at > NOW() - INTERVAL 1 DAY', [$userId]);
        if ($credits >= self::RESCUE_CREDITS || $cables > 0 || $recent) {
            return 0;
        }
        $grant = self::RESCUE_CREDITS - $credits;
        Db::exec('UPDATE users SET credits = credits + ? WHERE id = ?', [$grant, $userId]);
        Rules::log($userId, 'info', "Your ISP sent $grant credits to get you started. Buy copper cable and link your laptop to the ISP box to start earning.");
        return $grant;
    }

    /** Percent of a breach blocked by firewalls that are online: shield_pct x tier each, at most 75. */
    public static function shield(int $userId): int
    {
        $pct = array_sum(array_map(fn ($d) => (int) $d['shield_pct'] * (int) $d['tier'], self::online($userId)));
        return min(75, $pct);
    }

    /** What one online device earns per second: its rate times its tier bonus. */
    public static function deviceRate(array $d): float
    {
        return (int) $d['income_per_sec'] * Rules::tierIncome((int) $d['tier']) * (1 + (int) ($d['gpu_boost'] ?? 0) / 100);
    }

    /**
     * Credits per second from everything online, with each earning device's share
     * (so the map can show coins popping out of the right ones).
     * @return array{perSec:float, devices:list<string>, byDevice:array<int,float>}
     */
    public static function summary(int $userId): array
    {
        $online = self::online($userId);
        $by = [];
        foreach ($online as $d) {
            if (self::deviceRate($d) > 0) {
                $by[(int) $d['id']] = self::deviceRate($d);
            }
        }
        return ['perSec' => array_sum($by), 'devices' => array_column($online, 'label'), 'byDevice' => $by];
    }

    /**
     * Pays out income earned since the last call, at the current rate. Call it before
     * anything that changes which devices are online, so the old rate is used up to then.
     * Returns the credits added.
     */
    public static function accrue(int $userId): int
    {
        return Db::transaction(function () use ($userId): int {
            $u = Db::one('SELECT role, income_at, TIMESTAMPDIFF(SECOND, income_at, NOW()) secs FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($u === null || $u['role'] !== 'student') {
                return 0;
            }
            if ($u['income_at'] === null) {
                Db::exec('UPDATE users SET income_at = NOW() WHERE id = ?', [$userId]);
                return 0;
            }
            $rate = self::summary($userId)['perSec'];
            $secs = max(0, (int) $u['secs']);
            $capSecs = max(1, Db::configInt('income_cap_hours', 8)) * 3600;
            if ($rate <= 0) {
                Db::exec('UPDATE users SET income_at = NOW() WHERE id = ?', [$userId]);
                return self::rescue($userId);
            }
            $counted = min($secs, $capSecs);
            $earned = (int) floor($rate * $counted);
            if ($earned === 0) {
                return 0; // keep income_at so part-seconds keep building
            }
            Db::exec('UPDATE users SET credits = credits + ? WHERE id = ?', [$earned, $userId]);
            if ($secs > $capSecs) {
                Db::exec('UPDATE users SET income_at = NOW() WHERE id = ?', [$userId]);
            } else {
                // move the clock forward only by the time actually paid for, so fractions aren't lost
                Db::exec('UPDATE users SET income_at = income_at + INTERVAL ? SECOND WHERE id = ?', [(int) floor($earned / $rate), $userId]);
            }
            if ($secs >= 900) { // only mention it after time away, not every few seconds of play
                Rules::log($userId, 'ok', sprintf('Your network earned %s credits while you were away (%s/s)%s.',
                    number_format($earned), rtrim(rtrim(number_format($rate, 1), '0'), '.'), $secs > $capSecs ? ', the most it can save up' : ''));
            }
            return $earned;
        });
    }
}
