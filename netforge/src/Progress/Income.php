<?php
declare(strict_types=1);

namespace NetForge\Progress;

use NetForge\Db;
use NetForge\Rules;

/**
 * Passive income (FR-4.4). End devices (laptops, PCs, servers, GPU rigs) earn their item type's
 * income_per_sec, but only while online: joined to the ISP box by a chain of working (up) links.
 * Network devices (home routers, switches, routers) earn nothing themselves. Instead each one on
 * the path between an end device and the ISP boosts what that device earns by its boost_pct
 * (more at higher tiers), so a well-built network pays and a cable straight to the ISP box doesn't.
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
        foreach (Db::all('SELECT d.id, d.label, d.item_code, d.tier, d.location, d.tile_x, d.tile_y, d.rack_u, d.modules, t.income_per_sec, t.shield_pct, t.shop_group, t.boost_pct, t.radio, t.site_boost, t.rack_boost,
                                 COALESCE(g.boost_pct, 0) gpu_boost
                            FROM devices d JOIN item_types t ON t.code = d.item_code LEFT JOIN item_types g ON g.code = d.gpu_card WHERE d.user_id = ?', [$userId]) as $d) {
            $devices[(int) $d['id']] = $d;
        }
        // rack gear only runs in a rack with a PDU; a module can give a device Wi-Fi (or make it an AP)
        $powered = \NetForge\Racks::powered($userId);
        foreach ($devices as $id => $d) {
            if ($d['rack_u'] !== null && !isset($powered[$d['location'] . ':' . $d['tile_x'] . ',' . $d['tile_y']])) {
                unset($devices[$id]);
                continue;
            }
            if ($r = \NetForge\Racks::moduleRadio($d['modules'])) {
                $devices[$id]['radio'] = $r;
            }
        }
        $adj = [];
        foreach (Db::all('SELECT a_device_id a, b_device_id b FROM cables WHERE user_id = ? AND status = "up"', [$userId]) as $c) {
            $adj[(int) $c['a']][] = (int) $c['b'];
            $adj[(int) $c['b']][] = (int) $c['a'];
        }
        // breadth-first from the ISP boxes; parent[] remembers each device's shortest way back
        $queue = array_keys(array_filter($devices, fn ($d) => in_array($d['item_code'], self::ISP, true)));
        $seen = array_fill_keys($queue, true);
        $parent = [];
        while ($queue) {
            $id = array_shift($queue);
            foreach ($adj[$id] ?? [] as $next) {
                if (!isset($seen[$next]) && isset($devices[$next])) {
                    $seen[$next] = true;
                    $parent[$next] = $id;
                    $queue[] = $next;
                }
            }
        }
        $out = [];
        foreach (array_intersect_key($devices, $seen) as $id => $d) {
            if (in_array($d['item_code'], self::ISP, true)) {
                continue;
            }
            // multiply the boosts of the network devices between this one and the ISP
            $boost = 1.0;
            for ($p = $parent[$id] ?? null; $p !== null && isset($devices[$p]); $p = $parent[$p] ?? null) {
                $boost *= 1 + self::networkBoost($devices[$p]) / 100;
            }
            $out[$id] = $d + ['path_boost' => $boost];
        }

        // Wi-Fi: a wireless-only device is online when an access point in the same place is online.
        // A lightweight AP (LAP) only works while a Wireless LAN Controller there is online too.
        $wlc = [];
        foreach ($out as $d) {
            if ($d['radio'] === 'wlc') {
                $wlc[$d['location']] = true;
            }
        }
        $wifi = []; // location -> the best path boost a client can get through an access point there
        foreach ($out as $d) {
            if ($d['radio'] === 'ap' || ($d['radio'] === 'lap' && isset($wlc[$d['location']]))) {
                $via = $d['path_boost'] * (1 + self::networkBoost($d) / 100);
                $wifi[$d['location']] = max($wifi[$d['location']] ?? 0, $via);
            }
        }
        foreach ($devices as $id => $d) {
            if ($d['radio'] === 'client' && !isset($out[$id]) && isset($wifi[$d['location']])) {
                $out[$id] = $d + ['path_boost' => $wifi[$d['location']], 'wireless' => true];
            }
        }

        // KVM switches, cable management and blanking panels lift everything in their (powered) rack
        $rackBoost = [];
        foreach ($devices as $d) {
            if ($d['rack_u'] !== null && (int) $d['rack_boost'] > 0) {
                $k = $d['location'] . ':' . $d['tile_x'] . ',' . $d['tile_y'];
                $rackBoost[$k] = ($rackBoost[$k] ?? 0) + (int) $d['rack_boost'];
            }
        }
        foreach ($out as $id => $d) {
            $k = $d['location'] . ':' . $d['tile_x'] . ',' . $d['tile_y'];
            if ($d['rack_u'] !== null && isset($rackBoost[$k])) {
                $out[$id]['path_boost'] *= 1 + $rackBoost[$k] / 100;
            }
        }

        // a Network Controller online lifts every device in its place (site_boost percent)
        $site = [];
        foreach ($out as $d) {
            $site[$d['location']] = ($site[$d['location']] ?? 0) + (int) $d['site_boost'];
        }
        foreach ($out as $id => $d) {
            $out[$id]['path_boost'] *= 1 + ($site[$d['location']] ?? 0) / 100;
        }
        return array_values($out);
    }

    /** How much a network device boosts the end devices behind it, in percent: boost_pct, x1.5 at tier 2, x2 at tier 3. */
    public static function networkBoost(array $d): float
    {
        if (!in_array($d['shop_group'] ?? '', ['network', 'wireless', 'security', 'misc'], true)) {
            return 0.0; // routers, switches, hubs, access points and modems boost; end devices and GPUs don't
        }
        return (int) $d['boost_pct'] * Rules::tierIncome((int) $d['tier']);
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

    /** Extra hours of earning while away: 2 per UPS in a powered rack, up to 8. */
    public static function upsHours(int $userId): int
    {
        $powered = \NetForge\Racks::powered($userId);
        $n = 0;
        foreach (Db::all('SELECT location, tile_x, tile_y FROM devices WHERE user_id = ? AND item_code = "ups" AND rack_u IS NOT NULL', [$userId]) as $u) {
            $n += isset($powered[$u['location'] . ':' . $u['tile_x'] . ',' . $u['tile_y']]) ? 1 : 0;
        }
        return min(8, 2 * $n);
    }

    /** Percent of a breach blocked by firewalls that are online: shield_pct x tier each, at most 75. */
    public static function shield(int $userId): int
    {
        $pct = array_sum(array_map(fn ($d) => (int) $d['shield_pct'] * (int) $d['tier'], self::online($userId)));
        return min(75, $pct);
    }

    /** What one device earns per second on its own: its rate, its tier bonus and any GPU card (before network boosts). */
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
            $r = self::deviceRate($d) * $d['path_boost'];
            if ($r > 0) {
                $by[(int) $d['id']] = $r;
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
            $capSecs = (max(1, Db::configInt('income_cap_hours', 8)) + self::upsHours($userId)) * 3600;
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
                    \NetForge\Money::short($earned), $rate >= 1000 ? \NetForge\Money::short($rate) : rtrim(rtrim(number_format($rate, 1), '0'), '.'), $secs > $capSecs ? ', the most it can save up' : ''));
            }
            return $earned;
        });
    }
}
