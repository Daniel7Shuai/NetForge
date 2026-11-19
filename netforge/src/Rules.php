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
                'SELECT s.item_code, s.quantity, s.price, s.unlock_level, s.unlock_world, t.name
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
                throw new GameError(sprintf('Master World %d to unlock the %s.', $row['unlock_world'], $row['name']));
            }
            $credits = (int) $me['credits'];
            $price = (int) $row['price'];
            if ($credits < $price) {
                throw new GameError(sprintf('You need %d more credits. Your online devices earn them over time.', $price - $credits));
            }
            Db::exec('UPDATE users SET credits = credits - ? WHERE id = ?', [$price, $userId]);
            Db::exec(
                'INSERT INTO inventory (user_id, item_code, quantity) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)',
                [$userId, $row['item_code'], (int) $row['quantity']]
            );
            $name = $row['name'] . ((int) $row['quantity'] > 1 ? ' ×' . $row['quantity'] : '');
            self::log($userId, 'ok', sprintf('Bought %s for %d credits.', $name, $price));
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
            if (Layout::slotsFor($location, $kinds) === []) {
                throw new GameError(sprintf('There’s no spot for a %s in the %s. You’ll need a bigger place.', $item['name'], strtolower($locName)));
            }

            $slot = Layout::slotAnchoredAt($location, $kinds, $x, $y);
            if ($slot === null) {
                throw new GameError(Layout::KIND_HINTS[$kinds[0]] ?? 'That spot doesn’t fit this item.');
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
    private static function nextLabel(int $userId, string $prefix): string
    {
        $rows = Db::all('SELECT label FROM devices WHERE user_id = ? AND label LIKE ?', [$userId, $prefix . '-%']);
        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, (int) substr($r['label'], strlen($prefix) + 1));
        }
        return $prefix . '-' . ($max + 1);
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
     * Checks the current objective against the world and pays out if met.
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
                $o['reward']['credits'] ? sprintf('+%d credits', $o['reward']['credits']) : null,
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
            case 'level': // {"world": number, "level": position in that world}
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
