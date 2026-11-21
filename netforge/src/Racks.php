<?php
declare(strict_types=1);

namespace NetForge;

/**
 * Racks: every rack position (the built-in ones in the office server room and the datacentre,
 * plus server racks a student buys) is a 12U rack. Rack gear mounts at a U position; a rack
 * only has power once a PDU is mounted in it. A rack is identified by its place and tile.
 */
final class Racks
{
    public const SIZE = 12;   // U per rack
    public const PDU = 'pdu';

    /** The racks in a place: [{x, y, bought}] (bought: from a server rack the student placed). */
    public static function inPlace(int $userId, string $location): array
    {
        $built = array_map(fn ($s) => ['x' => $s['x'], 'y' => $s['y'], 'bought' => false], Layout::slotsFor($location, ['rack']));
        $mine = [];
        foreach (Layout::userFurniture($userId, $location) as $f) {
            if ($f['type'] === 'rack') {
                $mine[] = ['x' => $f['x'], 'y' => $f['y'], 'bought' => true];
            }
        }
        return array_merge($built, $mine);
    }

    public static function isRack(int $userId, string $location, int $x, int $y): bool
    {
        foreach (self::inPlace($userId, $location) as $r) {
            if ($r['x'] === $x && $r['y'] === $y) {
                return true;
            }
        }
        return false;
    }

    /** What's mounted in a rack: [{id, item_code, label, rack_u, size}], bottom to top. */
    public static function contents(int $userId, string $location, int $x, int $y): array
    {
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'item_code' => $r['item_code'], 'label' => $r['label'], 'rack_u' => (int) $r['rack_u'], 'size' => (int) $r['size']],
            Db::all('SELECT d.id, d.item_code, d.label, d.rack_u, t.rack_u size FROM devices d JOIN item_types t ON t.code = d.item_code
                      WHERE d.user_id = ? AND d.location = ? AND d.tile_x = ? AND d.tile_y = ? AND d.rack_u IS NOT NULL ORDER BY d.rack_u', [$userId, $location, $x, $y]));
    }

    /** The lowest U where something `size` U tall fits, or null if the rack is too full. */
    public static function freeU(array $contents, int $size, ?int $ignoreId = null): ?int
    {
        for ($u = 1; $u + $size - 1 <= self::SIZE; $u++) {
            if (self::fits($contents, $u, $size, $ignoreId)) {
                return $u;
            }
        }
        return null;
    }

    private static function fits(array $contents, int $u, int $size, ?int $ignoreId = null): bool
    {
        if ($u < 1 || $u + $size - 1 > self::SIZE) {
            return false;
        }
        foreach ($contents as $c) {
            if ($c['id'] !== $ignoreId && $u <= $c['rack_u'] + $c['size'] - 1 && $c['rack_u'] <= $u + $size - 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * Mounts rack gear from the inventory in the rack at (x, y), at U `u` (or the lowest free U if null).
     * Returns the new device's label. Call inside a transaction (Rules::place does).
     */
    public static function mount(int $userId, string $itemCode, int $x, int $y, ?int $u = null): string
    {
        $item = Db::one('SELECT code, name, category, label_prefix, slot_kinds, rack_u FROM item_types WHERE code = ?', [$itemCode]);
        if ($item === null || $item['category'] !== 'device' || !in_array('rack', Layout::kinds($item['slot_kinds']), true)) {
            throw new GameError('That doesn’t go in a rack.');
        }
        $location = Db::one('SELECT location FROM users WHERE id = ?', [$userId])['location'];
        if (!self::isRack($userId, $location, $x, $y)) {
            throw new GameError('There’s no rack there.');
        }
        $inv = Db::one('SELECT quantity FROM inventory WHERE user_id = ? AND item_code = ? FOR UPDATE', [$userId, $itemCode]);
        if ((int) ($inv['quantity'] ?? 0) < 1) {
            throw new GameError(sprintf('You have no %s left. Buy one in the shop.', $item['name']));
        }
        $size = (int) $item['rack_u'];
        $contents = self::contents($userId, $location, $x, $y);
        if ($u === null) {
            $u = self::freeU($contents, $size);
            if ($u === null) {
                throw new GameError(sprintf('That rack has no %dU gap left for the %s.', $size, $item['name']));
            }
        } elseif (!self::fits($contents, $u, $size)) {
            throw new GameError(sprintf('The %s is %dU tall: it needs U%d to U%d free in this rack.', $item['name'], $size, $u, $u + $size - 1));
        }
        $label = Rules::nextLabel($userId, (string) ($item['label_prefix'] ?: $item['name']));
        Db::exec('INSERT INTO devices (user_id, item_code, label, location, tile_x, tile_y, rack_u) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $itemCode, $label, $location, $x, $y, $u]);
        Db::exec('UPDATE inventory SET quantity = quantity - 1 WHERE user_id = ? AND item_code = ?', [$userId, $itemCode]);
        Rules::log($userId, 'ok', sprintf('Mounted %s in the rack at U%d%s.', $label, $u, $size > 1 ? '-' . ($u + $size - 1) : ''));
        return $label;
    }

    /** Slides a mounted device to another U in the same rack (its cables stay plugged in). */
    public static function shift(int $userId, int $deviceId, int $u): void
    {
        Db::transaction(function () use ($userId, $deviceId, $u): void {
            $d = Db::one('SELECT d.id, d.label, d.location, d.tile_x, d.tile_y, d.rack_u, t.rack_u size FROM devices d JOIN item_types t ON t.code = d.item_code
                           WHERE d.id = ? AND d.user_id = ? FOR UPDATE', [$deviceId, $userId]);
            if ($d === null || $d['rack_u'] === null) {
                throw new GameError('That isn’t mounted in a rack.');
            }
            $contents = self::contents($userId, $d['location'], (int) $d['tile_x'], (int) $d['tile_y']);
            if (!self::fits($contents, $u, (int) $d['size'], $deviceId)) {
                throw new GameError(sprintf('%s needs U%d to U%d free.', $d['label'], $u, $u + (int) $d['size'] - 1));
            }
            Db::exec('UPDATE devices SET rack_u = ? WHERE id = ?', [$u, $deviceId]);
        });
    }

    /** Racks in a place that have a PDU mounted, as "x,y" => true. */
    public static function powered(int $userId, ?string $location = null): array
    {
        $out = [];
        foreach (Db::all('SELECT location, tile_x, tile_y FROM devices WHERE user_id = ? AND item_code = ? AND rack_u IS NOT NULL' . ($location ? ' AND location = ?' : ''),
            $location ? [$userId, self::PDU, $location] : [$userId, self::PDU]) as $r) {
            $out[$r['location'] . ':' . $r['tile_x'] . ',' . $r['tile_y']] = true;
        }
        return $out;
    }

    /**
     * Before racks had U-slots, rack gear stood straight on a rack position. Mounts any such
     * device in its rack (from U1 up) and gives every rack in use a free PDU, so nothing goes
     * offline. Cheap when there's nothing to do.
     */
    public static function adopt(int $userId): void
    {
        $loose = Db::all('SELECT d.id, d.location, d.tile_x, d.tile_y, t.rack_u size FROM devices d JOIN item_types t ON t.code = d.item_code
                           WHERE d.user_id = ? AND d.rack_u IS NULL AND d.fixed = 0 AND FIND_IN_SET("rack", t.slot_kinds)', [$userId]);
        if (!$loose) {
            return;
        }
        Db::transaction(function () use ($userId, $loose): void {
            foreach ($loose as $d) {
                [$loc, $x, $y] = [$d['location'], (int) $d['tile_x'], (int) $d['tile_y']];
                if (!self::isRack($userId, $loc, $x, $y)) {
                    continue; // on a shelf or the floor: not in a rack
                }
                $u = self::freeU(self::contents($userId, $loc, $x, $y), (int) $d['size']) ?? 1;
                Db::exec('UPDATE devices SET rack_u = ? WHERE id = ?', [$u, $d['id']]);
                if (!isset(self::powered($userId, $loc)["$loc:$x,$y"])) {
                    $pu = self::freeU(self::contents($userId, $loc, $x, $y), 1) ?? self::SIZE;
                    Db::exec('INSERT INTO devices (user_id, item_code, label, location, tile_x, tile_y, rack_u) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, self::PDU, Rules::nextLabel($userId, 'PDU'), $loc, $x, $y, $pu]);
                }
            }
        });
    }

    /* ---------- interface modules ---------- */

    /** A device's ports: its own, plus those its modules add ({b} becomes the bay number). */
    public static function ports(string $portsJson, ?string $modulesJson): array
    {
        $ports = json_decode($portsJson, true) ?: [];
        foreach (self::moduleList($modulesJson) as $bay => $code) {
            $m = self::module($code);
            foreach ($m ? (json_decode((string) $m['module_ports'], true) ?: []) : [] as $p) {
                $p['name'] = str_replace('{b}', (string) ($bay + 1), $p['name']);
                $ports[] = $p;
            }
        }
        return $ports;
    }

    /** The radio a device's modules give it ('ap', 'client') or null. */
    public static function moduleRadio(?string $modulesJson): ?string
    {
        foreach (self::moduleList($modulesJson) as $code) {
            $r = self::module($code)['module_radio'] ?? 'none';
            if ($r !== 'none') {
                return $r;
            }
        }
        return null;
    }

    /** Fitted modules, bay by bay (null = empty bay). */
    public static function moduleList(?string $json): array
    {
        return json_decode((string) $json, true) ?: [];
    }

    private static array $modules = [];
    private static function module(?string $code): ?array
    {
        if ($code === null) {
            return null;
        }
        return self::$modules[$code] ??= Db::one('SELECT code, name, module_for, module_ports, module_radio FROM item_types WHERE code = ? AND shop_group = "modules"', [$code]);
    }

    /** Fits a module from the inventory into the device's first free bay. */
    public static function installModule(int $userId, int $deviceId, string $moduleCode): string
    {
        \NetForge\Progress\Income::accrue($userId);
        return Db::transaction(function () use ($userId, $deviceId, $moduleCode): string {
            $d = Db::one('SELECT d.id, d.label, d.item_code, d.modules, t.bays FROM devices d JOIN item_types t ON t.code = d.item_code WHERE d.id = ? AND d.user_id = ? FOR UPDATE',
                [$deviceId, $userId]);
            $m = self::module($moduleCode);
            if ($d === null || $m === null) {
                throw new GameError('Pick a device and a module.');
            }
            if (!in_array($d['item_code'], explode(',', (string) $m['module_for']), true)) {
                throw new GameError(sprintf('The %s doesn’t fit %s.', $m['name'], $d['label']));
            }
            $list = self::moduleList($d['modules']);
            $bay = null;
            for ($b = 0; $b < (int) $d['bays']; $b++) {
                if (($list[$b] ?? null) === null) {
                    $bay = $b;
                    break;
                }
            }
            if ($bay === null) {
                throw new GameError(sprintf('%s has no free module bay.', $d['label']));
            }
            $inv = Db::one('SELECT quantity FROM inventory WHERE user_id = ? AND item_code = ? FOR UPDATE', [$userId, $moduleCode]);
            if ((int) ($inv['quantity'] ?? 0) < 1) {
                throw new GameError(sprintf('You have no %s. Buy one in the shop, under Modules.', $m['name']));
            }
            $list[$bay] = $moduleCode;
            $list = array_replace(array_fill(0, (int) $d['bays'], null), $list);
            Db::exec('UPDATE devices SET modules = ? WHERE id = ?', [json_encode(array_values($list)), $deviceId]);
            Db::exec('UPDATE inventory SET quantity = quantity - 1 WHERE user_id = ? AND item_code = ?', [$userId, $moduleCode]);
            $msg = sprintf('Fitted the %s in %s, bay %d.', $m['name'], $d['label'], $bay + 1);
            Rules::log($userId, 'ok', $msg);
            return $msg;
        });
    }

    /** Takes a module out of a bay, back into the inventory. Its ports must be unplugged first. */
    public static function removeModule(int $userId, int $deviceId, int $bay): void
    {
        \NetForge\Progress\Income::accrue($userId);
        Db::transaction(function () use ($userId, $deviceId, $bay): void {
            $d = Db::one('SELECT id, label, modules FROM devices WHERE id = ? AND user_id = ? FOR UPDATE', [$deviceId, $userId]);
            $list = self::moduleList($d['modules'] ?? null);
            $code = $list[$bay] ?? null;
            if ($d === null || $code === null) {
                throw new GameError('There’s no module in that bay.');
            }
            foreach (json_decode((string) (self::module($code)['module_ports'] ?? '[]'), true) ?: [] as $p) {
                $name = str_replace('{b}', (string) ($bay + 1), $p['name']);
                if (Db::one('SELECT 1 FROM cables WHERE (a_device_id = ? AND a_port = ?) OR (b_device_id = ? AND b_port = ?)', [$deviceId, $name, $deviceId, $name])) {
                    throw new GameError(sprintf('Unplug %s %s first.', $d['label'], $name));
                }
            }
            $list[$bay] = null;
            Db::exec('UPDATE devices SET modules = ? WHERE id = ?', [json_encode(array_values($list)), $deviceId]);
            Db::exec('INSERT INTO inventory (user_id, item_code, quantity) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE quantity = quantity + 1', [$userId, $code]);
            Rules::log($userId, 'info', sprintf('Took the %s out of %s.', self::module($code)['name'], $d['label']));
        });
    }
}
