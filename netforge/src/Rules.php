<?php
declare(strict_types=1);

namespace NetForge;

/**
 * The game rules in one place. Change how the game plays here (and in the
 * data tables), not in the endpoints or the map code.
 */
final class Rules
{
    /** Which port kinds each cable fits. Mirrors public/assets/js/rules.js. */
    public const CABLE_FITS = [
        'copper'    => ['copper'],
        'crossover' => ['copper'],
        'fiber'     => ['fiber'],
        'console'   => ['console'],
    ];

    public static function cableFits(string $cableKind, string $portKind): bool
    {
        return in_array($portKind, self::CABLE_FITS[$cableKind] ?? [], true);
    }

    /**
     * Whether a plugged-in cable gives a working link (as in Packet Tracer).
     * Straight-through copper joins unlike ports (mdi to mdix); crossover joins like ports.
     * Returns null when the link is up, or the reason it's down.
     */
    public static function linkProblem(string $cableKind, array $portA, array $portB): ?string
    {
        if ($cableKind !== 'copper' && $cableKind !== 'crossover') {
            return null;
        }
        $same = ($portA['role'] ?? 'mdi') === ($portB['role'] ?? 'mdi');
        if ($cableKind === 'copper' && $same) {
            return 'these two ports are the same type, so they need a crossover cable';
        }
        if ($cableKind === 'crossover' && !$same) {
            return 'a host-style port and a switch-style port need a straight-through (copper) cable';
        }
        return null;
    }

    /* ---------- cabling ---------- */

    /**
     * Runs a cable between two device ports. Rejects cables that physically can't plug in;
     * accepts the wrong copper type but leaves the link down. Returns [status, message].
     */
    public static function connect(int $userId, string $cableCode, int $aId, string $aPort, int $bId, string $bPort): array
    {
        return Db::transaction(function () use ($userId, $cableCode, $aId, $aPort, $bId, $bPort): array {
            $cable = Db::one('SELECT code, name, category, cable_kind FROM item_types WHERE code = ?', [$cableCode]);
            if ($cable === null || $cable['category'] !== 'cable') {
                throw new GameError('Pick a cable type first.');
            }
            $inv = Db::one('SELECT quantity FROM inventory WHERE user_id = ? AND item_code = ? FOR UPDATE', [$userId, $cableCode]);
            if ($inv === null || (int) $inv['quantity'] < 1) {
                throw new GameError(sprintf('You have no %s left. Buy more in the shop.', strtolower($cable['name'])));
            }
            if ($aId === $bId) {
                throw new GameError('A cable needs two different devices.');
            }

            $ends = [];
            foreach ([[$aId, $aPort], [$bId, $bPort]] as [$id, $portName]) {
                $dev = Db::one(
                    'SELECT d.id, d.label, d.location, t.ports FROM devices d JOIN item_types t ON t.code = d.item_code
                      WHERE d.id = ? AND d.user_id = ? FOR UPDATE',
                    [$id, $userId]
                );
                if ($dev === null) {
                    throw new GameError('That device isn’t on your map.');
                }
                $port = null;
                foreach (json_decode((string) $dev['ports'], true) ?: [] as $p) {
                    if ($p['name'] === $portName) {
                        $port = $p;
                    }
                }
                if ($port === null) {
                    throw new GameError(sprintf('%s has no port called %s.', $dev['label'], $portName));
                }
                if (!self::cableFits($cable['cable_kind'], $port['kind'])) {
                    throw new GameError(sprintf('A %s doesn’t fit %s %s, which is a %s port.', strtolower($cable['name']), $dev['label'], $portName, $port['kind']));
                }
                $busy = Db::one(
                    'SELECT id FROM cables WHERE (a_device_id = ? AND a_port = ?) OR (b_device_id = ? AND b_port = ?) FOR UPDATE',
                    [$id, $portName, $id, $portName]
                );
                if ($busy !== null) {
                    throw new GameError(sprintf('%s %s already has a cable in it.', $dev['label'], $portName));
                }
                $ends[] = ['dev' => $dev, 'port' => $port];
            }
            if ($ends[0]['dev']['location'] !== $ends[1]['dev']['location']) {
                throw new GameError('Those devices are in different places.');
            }

            $problem = self::linkProblem($cable['cable_kind'], $ends[0]['port'], $ends[1]['port']);
            $status = $problem === null ? 'up' : 'down';
            Db::exec(
                'INSERT INTO cables (user_id, cable_code, a_device_id, a_port, b_device_id, b_port, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, $cableCode, $aId, $aPort, $bId, $bPort, $status]
            );
            Db::exec('UPDATE inventory SET quantity = quantity - 1 WHERE user_id = ? AND item_code = ?', [$userId, $cableCode]);

            $what = sprintf('%s %s → %s %s, %s', $ends[0]['dev']['label'], $aPort, $ends[1]['dev']['label'], $bPort, strtolower($cable['name']));
            $message = $problem === null ? "$what. Link up." : "$what. Link down: $problem.";
            self::log($userId, $problem === null ? 'ok' : 'error', $message);
            return [$status, $message];
        });
    }

    /** Pulls a cable out and returns it to the inventory. */
    public static function unplug(int $userId, int $cableId): void
    {
        Db::transaction(function () use ($userId, $cableId): void {
            $c = Db::one(
                'SELECT c.id, c.cable_code, c.a_port, c.b_port, a.label al, b.label bl FROM cables c
                   JOIN devices a ON a.id = c.a_device_id JOIN devices b ON b.id = c.b_device_id
                  WHERE c.id = ? AND c.user_id = ? FOR UPDATE',
                [$cableId, $userId]
            );
            if ($c === null) {
                throw new GameError('That cable isn’t there any more.');
            }
            Db::exec('DELETE FROM cables WHERE id = ?', [$cableId]);
            Db::exec(
                'INSERT INTO inventory (user_id, item_code, quantity) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE quantity = quantity + 1',
                [$userId, $c['cable_code']]
            );
            self::log($userId, 'info', sprintf('Unplugged %s %s → %s %s.', $c['al'], $c['a_port'], $c['bl'], $c['b_port']));
        });
    }

    /** Puts a placed device back in the inventory. It must have no cables attached. */
    public static function pickUp(int $userId, int $deviceId): void
    {
        Db::transaction(function () use ($userId, $deviceId): void {
            $d = Db::one('SELECT id, item_code, label, fixed FROM devices WHERE id = ? AND user_id = ? FOR UPDATE', [$deviceId, $userId]);
            if ($d === null) {
                throw new GameError('That device isn’t on your map.');
            }
            if ($d['fixed']) {
                throw new GameError($d['label'] . ' is part of the building. It stays where it is.');
            }
            if (Db::one('SELECT id FROM cables WHERE a_device_id = ? OR b_device_id = ?', [$deviceId, $deviceId]) !== null) {
                throw new GameError('Unplug ' . $d['label'] . '’s cables first.');
            }
            Db::exec('DELETE FROM devices WHERE id = ?', [$deviceId]);
            Db::exec(
                'INSERT INTO inventory (user_id, item_code, quantity) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE quantity = quantity + 1',
                [$userId, $d['item_code']]
            );
            self::log($userId, 'info', 'Picked up ' . $d['label'] . '.');
        });
    }

    public static function levelFor(int $xp): int
    {
        return intdiv($xp, max(1, Db::configInt('xp_per_level', 1000))) + 1;
    }

    /**
     * Adds XP, recalculates the level, and announces a level-up with the gear it unlocks.
     * Use this for every XP gain (questions, raids) so unlock messages are never missed.
     */
    public static function addXp(int $userId, int $xp): void
    {
        if ($xp <= 0) {
            return;
        }
        $old = (int) Db::one('SELECT level FROM users WHERE id = ?', [$userId])['level'];
        Db::exec('UPDATE users SET xp = xp + ? WHERE id = ?', [$xp, $userId]);
        $new = self::levelFor((int) Db::one('SELECT xp FROM users WHERE id = ?', [$userId])['xp']);
        if ($new === $old) {
            return;
        }
        Db::exec('UPDATE users SET level = ? WHERE id = ?', [$new, $userId]);
        $items = array_column(Db::all(
            'SELECT t.name FROM shop_items s JOIN item_types t ON t.code = s.item_code
              WHERE s.unlock_level > ? AND s.unlock_level <= ? ORDER BY s.sort_order',
            [$old, $new]
        ), 'name');
        self::log($userId, 'ok', sprintf('Level %d!%s', $new, $items ? ' You can now buy: ' . implode(', ', $items) . '.' : ''));
    }

    /* ---------- shop ---------- */

    /** Buys one shop row. Checks mastery and credits on the server (FR-4.2, NFR-4). Returns the item name. */
    public static function buy(int $userId, int $shopId): string
    {
        return Db::transaction(function () use ($userId, $shopId): string {
            $row = Db::one(
                'SELECT s.id, s.item_code, s.quantity, s.price, s.unlock_level, s.unlock_world, s.seller, t.name
                   FROM shop_items s JOIN item_types t ON t.code = s.item_code WHERE s.id = ?',
                [$shopId]
            );
            if ($row === null) {
                throw new GameError('That item isn’t in the shop.');
            }
            $me = Db::one('SELECT credits, level FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ((int) $me['level'] < (int) $row['unlock_level']) {
                throw new GameError(sprintf('Reach level %d to buy the %s. Earn XP by answering questions in Learn.', $row['unlock_level'], $row['name']));
            }
            if ($row['unlock_world'] !== null && !in_array((int) $row['unlock_world'], \NetForge\Learning\Learning::masteredNumbers($userId), true)) {
                throw new GameError(sprintf('Master Topic %d to unlock the %s.', $row['unlock_world'], $row['name']));
            }
            $isEnding = $row['item_code'] === Ending::ITEM;
            if ($isEnding) {
                Ending::checkCanBuy($userId);
            }
            $credits = (int) $me['credits'];
            $price = (int) $row['price'];
            if ($row['seller'] !== null) {
                // sold by a GPU seller: today's price, and only while they have stock
                $q = Market::quote($row, $userId);
                if ($q['stock'] < 1) {
                    throw new GameError(sprintf('%s is sold out of the %s. They restock in %d minutes, or try another seller.', $row['seller'], $row['name'], (int) ceil($q['restockIn'] / 60)));
                }
                $price = $q['price'];
            }
            if ($credits < $price) {
                throw new GameError(sprintf('You need %s more credits. Your online devices earn them every second.', Money::short($price - $credits)));
            }
            Db::exec('UPDATE users SET credits = credits - ? WHERE id = ?', [$price, $userId]);
            if ($isEnding) { // a company, not something to carry around
                self::log($userId, 'ok', sprintf('Bought %s for %s credits.', $row['name'], Money::short($price)));
                Ending::finish($userId);
                return $row['name'];
            }
            Db::exec(
                'INSERT INTO inventory (user_id, item_code, quantity) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)',
                [$userId, $row['item_code'], (int) $row['quantity']]
            );
            if ($row['seller'] !== null) {
                Market::recordPurchase($userId, (int) $row['id']);
            }
            $name = $row['name'] . ((int) $row['quantity'] > 1 ? ' ×' . $row['quantity'] : '') . ($row['seller'] !== null ? ' from ' . $row['seller'] : '');
            self::log($userId, 'ok', sprintf('Bought %s for %s credits.', $name, Money::short($price)));
            return $name;
        });
    }

    /* ---------- placing devices ---------- */

    /**
     * Takes one device from inventory and puts it on the slot anchored at (x, y).
     * Returns the new device's label. Throws GameError with a player-facing reason.
     */
    public static function place(int $userId, string $itemCode, int $x, int $y): string
    {
        return Db::transaction(function () use ($userId, $itemCode, $x, $y): string {
            $item = Db::one('SELECT code, name, category, sprite, height, label_prefix, slot_kinds FROM item_types WHERE code = ?', [$itemCode]);
            $kinds = Layout::kinds($item['slot_kinds'] ?? null);
            if ($item === null || $item['category'] !== 'device' || $kinds === []) {
                throw new GameError('That item can’t be placed on the map.');
            }

            $inv = Db::one('SELECT quantity FROM inventory WHERE user_id = ? AND item_code = ? FOR UPDATE', [$userId, $itemCode]);
            if ($inv === null || (int) $inv['quantity'] < 1) {
                throw new GameError(sprintf('You have no %s left. Buy one in the shop.', $item['name']));
            }

            $user = Db::one('SELECT location FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $location = $user['location'];
            $locName = Layout::location($location)['name'];
            if (Layout::slotsFor($location, $kinds, $userId) === []) {
                throw new GameError(sprintf('There’s no spot for a %s in the %s. You’ll need a bigger place.', $item['name'], strtolower($locName)));
            }

            $slot = Layout::slotAnchoredAt($location, $kinds, $x, $y, $userId);
            if ($slot === null) {
                throw new GameError(Layout::hint($kinds));
            }

            // Any device in this location already covering one of the slot's tiles?
            $taken = Db::one(
                'SELECT d.label FROM devices d JOIN item_types t ON t.code = d.item_code
                  WHERE d.user_id = ? AND d.location = ? AND d.tile_x = ? AND d.tile_y < ? AND d.tile_y + t.height > ?
                  FOR UPDATE',
                [$userId, $location, $x, $y + $slot['h'], $y]
            );
            if ($taken !== null) {
                throw new GameError($taken['label'] . ' is already there. Pick a free spot.');
            }

            $label = self::nextLabel($userId, (string) ($item['label_prefix'] ?: $item['name']));
            Db::exec(
                'INSERT INTO devices (user_id, item_code, label, location, tile_x, tile_y) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $itemCode, $label, $location, $x, $y]
            );
            Db::exec('UPDATE inventory SET quantity = quantity - 1 WHERE user_id = ? AND item_code = ?', [$userId, $itemCode]);
            $where = ['bench' => 'on the workbench', 'shelf' => 'on the shelf', 'desk' => 'at a desk', 'rack' => 'in the server room'];
            self::log($userId, 'ok', sprintf('Placed %s %s.', $label, $where[$slot['kind']] ?? 'on the map'));
            return $label;
        });
    }

    /** "PC-3" when PC-1 and PC-2 exist; reuses no numbers so cables in logs stay unambiguous. */
    public static function nextLabel(int $userId, string $prefix): string
    {
        $rows = Db::all('SELECT label FROM devices WHERE user_id = ? AND label LIKE ?', [$userId, $prefix . '-%']);
        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, (int) substr($r['label'], strlen($prefix) + 1));
        }
        return $prefix . '-' . ($max + 1);
    }

    /* ---------- upgrades (FR-4.3) ---------- */

    /**
     * What upgrading a device to its next tier costs and needs. Based on the item's shop
     * price and unlock level: tier 2 costs 60% of the price and needs 2 more levels;
     * tier 3 costs 120% and needs 4 more. Returns null when it's already at max tier.
     * @return array{tier:int, cost:int, level:int}|null
     */
    public static function upgradeInfo(string $itemCode, int $tier, int $maxTier): ?array
    {
        if ($tier >= $maxTier) {
            return null;
        }
        $shop = Db::one('SELECT price, unlock_level FROM shop_items WHERE item_code = ? ORDER BY quantity LIMIT 1', [$itemCode]);
        $price = $shop ? (int) $shop['price'] : 1000;  // the laptop isn't sold; price its upgrades like a 1,000-credit device
        $level = $shop ? (int) $shop['unlock_level'] : 1;
        return ['tier' => $tier + 1, 'cost' => (int) round($price * 0.6 * $tier), 'level' => $level + 2 * $tier];
    }

    /** Income multiplier for a tier: x1, x1.5, x2. */
    public static function tierIncome(int $tier): float
    {
        return 1 + 0.5 * ($tier - 1);
    }

    public static function upgrade(int $userId, int $deviceId): string
    {
        return Db::transaction(function () use ($userId, $deviceId): string {
            $d = Db::one(
                'SELECT d.id, d.label, d.tier, d.fixed, d.item_code, t.max_tier FROM devices d JOIN item_types t ON t.code = d.item_code
                  WHERE d.id = ? AND d.user_id = ? FOR UPDATE',
                [$deviceId, $userId]
            );
            if ($d === null) {
                throw new GameError('That device isn’t on your map.');
            }
            $up = self::upgradeInfo($d['item_code'], (int) $d['tier'], (int) $d['max_tier']);
            if ($d['fixed'] || $up === null) {
                throw new GameError($d['fixed'] ? $d['label'] . ' is part of the building.' : $d['label'] . ' is already at its top tier.');
            }
            $me = Db::one('SELECT credits, level FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ((int) $me['level'] < $up['level']) {
                throw new GameError(sprintf('Reach level %d to upgrade %s to tier %d. Answer questions in Learn for XP.', $up['level'], $d['label'], $up['tier']));
            }
            if ((int) $me['credits'] < $up['cost']) {
                throw new GameError(sprintf('You need %s more credits to upgrade %s.', Money::short($up['cost'] - (int) $me['credits']), $d['label']));
            }
            Db::exec('UPDATE users SET credits = credits - ? WHERE id = ?', [$up['cost'], $userId]);
            Db::exec('UPDATE devices SET tier = ? WHERE id = ?', [$up['tier'], $deviceId]);
            $msg = sprintf('Upgraded %s to tier %d for %s credits.', $d['label'], $up['tier'], Money::short($up['cost']));
            self::log($userId, 'ok', $msg);
            return $msg;
        });
    }

    /** Moves a placed device to another free spot in the same location. Its cables stay plugged in. */
    public static function move(int $userId, int $deviceId, int $x, int $y): void
    {
        Db::transaction(function () use ($userId, $deviceId, $x, $y): void {
            $d = Db::one(
                'SELECT d.id, d.label, d.location, d.fixed, t.slot_kinds FROM devices d JOIN item_types t ON t.code = d.item_code
                  WHERE d.id = ? AND d.user_id = ? FOR UPDATE',
                [$deviceId, $userId]
            );
            if ($d === null) {
                throw new GameError('That device isn’t on your map.');
            }
            if ($d['fixed']) {
                throw new GameError($d['label'] . ' is part of the building. It stays where it is.');
            }
            $kinds = Layout::kinds($d['slot_kinds']);
            $slot = Layout::slotAnchoredAt($d['location'], $kinds, $x, $y, $userId);
            if ($slot === null) {
                throw new GameError(Layout::hint($kinds));
            }
            $taken = Db::one(
                'SELECT d.label FROM devices d JOIN item_types t ON t.code = d.item_code
                  WHERE d.user_id = ? AND d.location = ? AND d.id <> ? AND d.tile_x = ? AND d.tile_y < ? AND d.tile_y + t.height > ?',
                [$userId, $d['location'], $deviceId, $x, $y + $slot['h'], $y]
            );
            if ($taken !== null) {
                throw new GameError($taken['label'] . ' is already there. Pick a free spot.');
            }
            Db::exec('UPDATE devices SET tile_x = ?, tile_y = ? WHERE id = ?', [$x, $y, $deviceId]);
            self::log($userId, 'info', 'Moved ' . $d['label'] . '. Its cables came with it.');
        });
    }

    /* ---------- locations (FR-4.3: three datacentre levels) ---------- */

    /** Every location with whether this student has leased it, can lease it, and what it takes. */
    public static function locations(int $userId): array
    {
        $u = Db::one('SELECT location, level, credits FROM users WHERE id = ?', [$userId]);
        $leased = array_column(Db::all('SELECT location FROM user_locations WHERE user_id = ?', [$userId]), 'location');
        $out = [];
        foreach (Layout::data()['locations'] as $code => $l) {
            $own = ($l['leaseCost'] ?? 0) === 0 || in_array($code, $leased, true);
            $out[] = [
                'code' => $code, 'name' => $l['name'], 'tier' => (int) $l['tier'], 'pitch' => $l['pitch'] ?? '',
                'unlockLevel' => (int) $l['unlockLevel'], 'leaseCost' => (int) ($l['leaseCost'] ?? 0),
                'leased' => $own, 'current' => $code === $u['location'],
                'canLease' => !$own && (int) $u['level'] >= (int) $l['unlockLevel'] && (int) $u['credits'] >= (int) ($l['leaseCost'] ?? 0),
            ];
        }
        return $out;
    }

    /** Goes to a location, leasing it first if needed. Devices stay where they were placed. */
    public static function travel(int $userId, string $code): string
    {
        return Db::transaction(function () use ($userId, $code): string {
            $l = Layout::data()['locations'][$code] ?? null;
            if ($l === null) {
                throw new GameError('That place doesn’t exist.');
            }
            $u = Db::one('SELECT location, level, credits FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($u['location'] === $code) {
                throw new GameError('You’re already here.');
            }
            $cost = (int) ($l['leaseCost'] ?? 0);
            $own = $cost === 0 || Db::one('SELECT 1 FROM user_locations WHERE user_id = ? AND location = ?', [$userId, $code]) !== null;
            $msg = 'You went to the ' . strtolower($l['name']) . '.';
            if (!$own) {
                if ((int) $u['level'] < (int) $l['unlockLevel']) {
                    throw new GameError(sprintf('Reach level %d to lease the %s.', $l['unlockLevel'], strtolower($l['name'])));
                }
                if ((int) $u['credits'] < $cost) {
                    throw new GameError(sprintf('Leasing the %s costs %s credits. You need %s more.', strtolower($l['name']), Money::short($cost), Money::short($cost - (int) $u['credits'])));
                }
                Db::exec('UPDATE users SET credits = credits - ? WHERE id = ?', [$cost, $userId]);
                Db::exec('INSERT INTO user_locations (user_id, location) VALUES (?, ?)', [$userId, $code]);
                Starter::placeFixtures($userId, $code);
                $msg = sprintf('You leased the %s for %s credits. Wire its ISP connection to start earning here.', strtolower($l['name']), Money::short($cost));
            }
            Db::exec('UPDATE users SET location = ?, pos_x = ?, pos_y = ? WHERE id = ?', [$code, $l['spawn']['x'], $l['spawn']['y'], $userId]);
            self::log($userId, 'ok', $msg);
            return $msg;
        });
    }

    /* ---------- objectives ---------- */

    /** The first objective the player hasn't finished, with its position in the list. */
    public static function currentObjective(int $userId): ?array
    {
        $all = Db::all('SELECT id, title, goal, reward_credits, reward_xp FROM objectives ORDER BY sort_order, id');
        $done = array_column(Db::all('SELECT objective_id FROM progress WHERE user_id = ?', [$userId]), 'objective_id');
        foreach ($all as $i => $o) {
            if (!in_array($o['id'], $done)) {
                return [
                    'id'     => (int) $o['id'],
                    'title'  => $o['title'],
                    'goal'   => json_decode($o['goal'], true),
                    'reward' => ['credits' => (int) $o['reward_credits'], 'xp' => (int) $o['reward_xp']],
                    'index'  => $i,
                    'total'  => count($all),
                ];
            }
        }
        return null;
    }

    /**
     * Checks the current objective against the topic and pays out if met.
     * Call after anything that changes the map. Returns the finished objective, if any.
     * Add new goal types to goalMet().
     */
    public static function checkObjective(int $userId): ?array
    {
        $o = self::currentObjective($userId);
        if ($o === null || !self::goalMet($userId, $o['goal'])) {
            return null;
        }
        Db::transaction(function () use ($userId, $o): void {
            Db::exec('INSERT IGNORE INTO progress (user_id, objective_id) VALUES (?, ?)', [$userId, $o['id']]);
            Db::exec('UPDATE users SET credits = credits + ? WHERE id = ?', [$o['reward']['credits'], $userId]);
            self::addXp($userId, $o['reward']['xp']);
            $gains = array_filter([
                $o['reward']['credits'] ? sprintf('+%s credits', Money::short($o['reward']['credits'])) : null,
                $o['reward']['xp'] ? sprintf('+%d XP', $o['reward']['xp']) : null,
            ]);
            self::log($userId, 'ok', sprintf('Objective done: %s.%s', $o['title'], $gains ? ' ' . implode(', ', $gains) . '.' : ''));
        });
        return $o;
    }

    private static function goalMet(int $userId, array $goal): bool
    {
        switch ($goal['type'] ?? '') {
            case 'place':
                return Db::one('SELECT 1 FROM devices WHERE user_id = ? AND item_code = ?', [$userId, $goal['item']]) !== null;
            case 'level': // {"world": number, "level": position in that topic}
                return Db::one(
                    'SELECT 1 FROM level_progress lp JOIN levels l ON l.id = lp.level_id JOIN worlds w ON w.id = l.world_id
                      WHERE lp.user_id = ? AND w.number = ? AND l.sort_order = ? AND lp.completed_at IS NOT NULL',
                    [$userId, $goal['world'], $goal['level']]
                ) !== null;
            case 'world': // {"world": number} mastered
                return Db::one(
                    'SELECT 1 FROM world_progress p JOIN worlds w ON w.id = p.world_id
                      WHERE p.user_id = ? AND w.number = ? AND p.mastered_at IS NOT NULL',
                    [$userId, $goal['world']]
                ) !== null;
            case 'link':
                return Db::one(
                    'SELECT 1 FROM cables c
                       JOIN devices a ON a.id = c.a_device_id
                       JOIN devices b ON b.id = c.b_device_id
                       JOIN item_types t ON t.code = c.cable_code
                      WHERE c.user_id = ? AND c.status = "up" AND t.cable_kind = ?
                        AND ((a.item_code = ? AND b.item_code = ?) OR (a.item_code = ? AND b.item_code = ?))',
                    [$userId, $goal['cable'], $goal['a'], $goal['b'], $goal['b'], $goal['a']]
                ) !== null;
            default:
                return false;
        }
    }

    public static function log(int $userId, string $kind, string $message): void
    {
        Db::exec('INSERT INTO event_log (user_id, kind, message) VALUES (?, ?, ?)', [$userId, $kind, $message]);
    }
}
