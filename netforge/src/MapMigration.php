<?php
declare(strict_types=1);

namespace NetForge;

/**
 * Moves a student's gear onto the redesigned office and datacentre (phase 4: rooms and interior walls).
 * Runs once per student, the first time their game loads after the change:
 *  - the datacentre's two desk spots moved from the bottom of the data hall into the operations centre,
 *    and whatever stood on them moves with them;
 *  - gear or bought furniture now standing where a new wall or built-in furniture is goes back to the
 *    inventory (with its cables, modules and GPU card), and the student is told;
 *  - a student standing inside a new wall is moved to the entrance.
 */
final class MapMigration
{
    public const VERSION = 4; // users.map_version once this student's gear is on the current plans
    /** Old desk spot => new desk spot, in the datacentre. */
    public const DESK_MOVES = [[4, 16, 25, 10], [11, 16, 31, 10]];

    /** $version: the student's users.map_version, when the caller already has it. */
    public static function run(int $userId, ?int $version = null): void
    {
        $version ??= (int) (Db::one('SELECT map_version FROM users WHERE id = ?', [$userId])['map_version'] ?? self::VERSION);
        if ($version >= self::VERSION) {
            return;
        }
        Racks::adopt($userId); // rack gear from before racks had U-slots: mount it, with a PDU
        Db::transaction(function () use ($userId): void {
            foreach (self::DESK_MOVES as [$ox, $oy, $nx, $ny]) {
                Db::exec('UPDATE devices SET tile_x = ?, tile_y = ? WHERE user_id = ? AND location = "datacentre" AND tile_x = ? AND tile_y = ? AND rack_u IS NULL',
                    [$nx, $ny, $userId, $ox, $oy]);
            }
            $returned = [];
            foreach (['office', 'datacentre'] as $loc) {
                $solid = self::solid($loc);
                $anchors = [];
                foreach (Layout::location($loc)['slots'] as $s) {
                    $anchors[$s['x'] . ',' . $s['y']] = true;
                }
                // bought furniture in the way of walls, built-in furniture or built-in spots (and anything mounted in a bought rack there)
                $blocked = Layout::staticBlocked($loc);
                foreach (Db::all('SELECT f.id, f.item_code, f.tile_x, f.tile_y, t.name, t.furniture_type FROM user_furniture f JOIN item_types t ON t.code = f.item_code
                                   WHERE f.user_id = ? AND f.location = ?', [$userId, $loc]) as $f) {
                    if (!self::hits($blocked, Layout::furnitureTiles($f['furniture_type'], (int) $f['tile_x'], (int) $f['tile_y'], false))) {
                        continue;
                    }
                    foreach (Db::all('SELECT id FROM devices WHERE user_id = ? AND location = ? AND tile_x = ? AND tile_y = ? AND rack_u IS NOT NULL',
                                     [$userId, $loc, $f['tile_x'], $f['tile_y']]) as $d) {
                        $returned[] = self::evictDevice($userId, (int) $d['id']);
                    }
                    Db::exec('DELETE FROM user_furniture WHERE id = ?', [$f['id']]);
                    Rules::giveItem($userId, $f['item_code']);
                    $returned[] = $f['name'];
                }
                // devices standing on a new wall or piece of furniture (gear on a built-in spot stays)
                foreach (Db::all('SELECT d.id, d.tile_x, d.tile_y, t.height FROM devices d JOIN item_types t ON t.code = d.item_code
                                   WHERE d.user_id = ? AND d.location = ? AND d.rack_u IS NULL AND d.fixed = 0', [$userId, $loc]) as $d) {
                    if (isset($anchors[$d['tile_x'] . ',' . $d['tile_y']])) {
                        continue;
                    }
                    if (self::hits($solid, array_map(fn ($j) => [(int) $d['tile_x'], (int) $d['tile_y'] + $j], range(0, max(1, (int) $d['height']) - 1)))) {
                        $returned[] = self::evictDevice($userId, (int) $d['id']);
                    }
                }
            }
            // a student standing inside a new wall starts at the entrance
            $u = Db::one('SELECT location, pos_x, pos_y FROM users WHERE id = ?', [$userId]);
            if (in_array($u['location'], ['office', 'datacentre'], true)
                && isset(Layout::staticBlocked($u['location'])[$u['pos_x'] . ',' . $u['pos_y']])) {
                $spawn = Layout::location($u['location'])['spawn'];
                Db::exec('UPDATE users SET pos_x = ?, pos_y = ? WHERE id = ?', [$spawn['x'], $spawn['y'], $userId]);
            }
            if ($returned) {
                Rules::log($userId, 'info', sprintf('The office and datacentre have new rooms. %s %s in the way, so %s back in your inventory to place again.',
                    implode(', ', array_unique($returned)), count($returned) === 1 ? 'was' : 'were', count($returned) === 1 ? 'it’s' : 'they’re'));
            }
            Db::exec('UPDATE users SET map_version = ? WHERE id = ?', [self::VERSION, $userId]);
        });
    }

    /** Walls and built-in furniture in a place: where nothing can stand. */
    public static function solid(string $location): array
    {
        return Layout::wallTiles($location) + Layout::furnitureBlocked($location);
    }

    /** Does any of the [x, y] tiles fall in the "x,y" => true set? */
    private static function hits(array $set, array $tiles): bool
    {
        foreach ($tiles as [$x, $y]) {
            if (isset($set["$x,$y"])) {
                return true;
            }
        }
        return false;
    }

    /** Takes a device off the map: its cables, modules, GPU card and the device itself go to the inventory. Returns its label. */
    private static function evictDevice(int $userId, int $deviceId): string
    {
        $d = Db::one('SELECT id, item_code, label, gpu_card, modules FROM devices WHERE id = ? AND user_id = ?', [$deviceId, $userId]);
        if ($d === null) {
            return '';
        }
        foreach (Db::all('SELECT id, cable_code FROM cables WHERE a_device_id = ? OR b_device_id = ?', [$deviceId, $deviceId]) as $c) {
            Db::exec('DELETE FROM cables WHERE id = ?', [$c['id']]);
            Rules::giveItem($userId, $c['cable_code']);
        }
        foreach (array_filter([$d['gpu_card'], ...Racks::moduleList($d['modules'])]) as $part) {
            Rules::giveItem($userId, $part);
        }
        Db::exec('DELETE FROM devices WHERE id = ?', [$deviceId]);
        Rules::giveItem($userId, $d['item_code']);
        return $d['label'];
    }
}
