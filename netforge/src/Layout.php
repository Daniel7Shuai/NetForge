<?php
declare(strict_types=1);

namespace NetForge;

/** Server-side view of public/assets/data/layout.json (floor plans per location). */
final class Layout
{
    /** Where each slot kind is, for hints. Mirrors SPOTS in store.js. */
    public const SPOTS = [
        'bench' => 'on the workbench',
        'shelf' => 'on a shelf',
        'desk'  => 'on the spot beside a desk',
        'rack'  => 'in a rack slot (the office server room, the datacentre rows, or a server rack you buy)',
        'floor' => 'on any open bit of floor',
    ];

    /** "It goes on a shelf or in a rack slot (...)": every place an item with these slot kinds fits. */
    public static function hint(array $kinds): string
    {
        $where = array_values(array_filter(array_map(fn ($k) => self::SPOTS[$k] ?? null, $kinds)));
        return $where ? 'It goes ' . implode(' or ', $where) . '. Pick one of the glowing spots.' : 'That spot doesn’t fit this item.';
    }

    private static ?array $data = null;

    public static function data(): array
    {
        if (self::$data === null) {
            $json = file_get_contents(NF_ROOT . '/public/assets/data/layout.json');
            self::$data = json_decode((string) $json, true, 16, JSON_THROW_ON_ERROR);
        }
        return self::$data;
    }

    public static function location(string $code): array
    {
        $loc = self::data()['locations'][$code] ?? null;
        if ($loc === null) {
            throw new \RuntimeException("Unknown location: $code");
        }
        return $loc;
    }

    /** A place's size in tiles: [cols, rows]. Bigger places are bigger. Mirrors setLocation() in layout.js. */
    public static function size(string $location): array
    {
        $loc = self::location($location);
        return [(int) ($loc['cols'] ?? self::data()['cols']), (int) ($loc['rows'] ?? self::data()['rows'])];
    }

    public static function kinds(?string $slotKinds): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $slotKinds))));
    }

    /** The spot a piece of bought furniture adds, relative to where it stands. Mirrors furnitureSlot() in layout.js. */
    public static function furnitureSlot(string $type, int $x, int $y): ?array
    {
        return match ($type) {
            'desk' => ['kind' => 'desk', 'x' => $x + 3, 'y' => $y, 'h' => 1], // the PC stands at the desk's right end
            'rack' => ['kind' => 'rack', 'x' => $x, 'y' => $y, 'h' => 2],     // the device goes in the rack itself
            default => null,
        };
    }

    /** Furniture a student has bought and placed in a location: [{id, type, item, x, y}]. */
    public static function userFurniture(int $userId, string $location): array
    {
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'type' => $r['furniture_type'], 'item' => $r['item_code'], 'x' => (int) $r['tile_x'], 'y' => (int) $r['tile_y']],
            Db::all('SELECT f.id, f.item_code, f.tile_x, f.tile_y, t.furniture_type FROM user_furniture f JOIN item_types t ON t.code = f.item_code
                      WHERE f.user_id = ? AND f.location = ? ORDER BY f.id', [$userId, $location]));
    }

    /**
     * Spots in a location that accept any of the kinds: the built-in ones, plus those added by
     * the student's own furniture. Mirrors slotsFor() in layout.js.
     */
    public static function slotsFor(string $location, array $kinds, ?int $userId = null): array
    {
        $slots = self::location($location)['slots'];
        if ($userId !== null) {
            foreach (self::userFurniture($userId, $location) as $f) {
                if ($s = self::furnitureSlot($f['type'], $f['x'], $f['y'])) {
                    $slots[] = $s;
                }
            }
        }
        return array_values(array_filter($slots, fn ($s) => in_array($s['kind'], $kinds, true)));
    }

    /** Tiles under a place's built-in furniture, as "x,y" => true. */
    public static function furnitureBlocked(string $location): array
    {
        $b = [];
        foreach (self::location($location)['furniture'] as $f) {
            foreach (self::furnitureTiles($f['type'], $f['x'], $f['y'], false) as [$tx, $ty]) {
                $b["$tx,$ty"] = true;
            }
        }
        return $b;
    }

    /** Interior walls between rooms ("walls" in layout.json), less their doorways, as "x,y" => true. */
    public static function wallTiles(string $location): array
    {
        $b = [];
        foreach (self::location($location)['walls'] ?? [] as $w) {
            $doors = array_map(fn ($d) => $d[0] . ',' . $d[1], $w['doors'] ?? []);
            for ($i = 0; $i < $w['w']; $i++) {
                for ($j = 0; $j < $w['h']; $j++) {
                    $k = ($w['x'] + $i) . ',' . ($w['y'] + $j);
                    if (!in_array($k, $doors, true)) {
                        $b[$k] = true;
                    }
                }
            }
        }
        return $b;
    }

    /** Tiles that aren't open floor: walls, the partition, built-in furniture and fixtures. */
    public static function staticBlocked(string $location): array
    {
        $loc = self::location($location);
        $data = self::data();
        [$cols, $rows] = self::size($location);
        $b = [];
        for ($x = 0; $x < $cols; $x++) {
            for ($y = 0; $y < $loc['wallRows']; $y++) {
                $b["$x,$y"] = true;
            }
            if (!empty($loc['bottomWall'])) {
                $b["$x," . ($rows - 1)] = true;
            }
        }
        if (!empty($loc['partition'])) {
            for ($y = $loc['wallRows']; $y < $rows; $y++) {
                if (!in_array($y, $loc['partition']['doorRows'], true)) {
                    $b[$loc['partition']['col'] . ",$y"] = true;
                }
            }
        }
        $b += self::wallTiles($location);
        $b += self::furnitureBlocked($location);
        foreach ($loc['slots'] as $s) { // built-in spots stay clear for gear
            for ($j = 0; $j < $s['h']; $j++) {
                $b[$s['x'] . ',' . ($s['y'] + $j)] = true;
            }
        }
        return $b;
    }

    /** The slot whose anchor tile is exactly (x, y), or null. */
    public static function slotAnchoredAt(string $location, array $kinds, int $x, int $y, ?int $userId = null): ?array
    {
        foreach (self::slotsFor($location, $kinds, $userId) as $s) {
            if ($s['x'] === $x && $s['y'] === $y) {
                return $s;
            }
        }
        if (in_array('floor', $kinds, true) && self::openFloor($location, $x, $y, $userId)) {
            return ['kind' => 'floor', 'x' => $x, 'y' => $y, 'h' => 1]; // small devices stand anywhere free
        }
        return null;
    }

    /** Every tile a piece of furniture needs: its footprint, plus the spot it adds. Mirrors furnitureTiles() in layout.js. */
    public static function furnitureTiles(string $type, int $x, int $y, bool $withSpot = true): array
    {
        [$w, $h] = self::data()['footprints'][$type] ?? [1, 1];
        $tiles = [];
        for ($i = 0; $i < $w; $i++) {
            for ($j = 0; $j < $h; $j++) {
                $tiles[] = [$x + $i, $y + $j];
            }
        }
        $slot = $withSpot ? self::furnitureSlot($type, $x, $y) : null;
        if ($slot && !in_array([$slot['x'], $slot['y']], $tiles, true)) {
            for ($j = 0; $j < $slot['h']; $j++) {
                $tiles[] = [$slot['x'], $slot['y'] + $j];
            }
        }
        return $tiles;
    }

    /**
     * Is (x, y) open floor: not a wall, furniture, a built-in spot or bought furniture with its spot?
     * (Devices standing there are checked by the caller.) Mirrors openFloor() in layout.js.
     */
    public static function openFloor(string $location, int $x, int $y, ?int $userId = null): bool
    {
        [$cols, $rows] = self::size($location);
        if ($x < 0 || $y < 0 || $x >= $cols || $y >= $rows || isset(self::staticBlocked($location)["$x,$y"])) {
            return false;
        }
        foreach ($userId === null ? [] : self::userFurniture($userId, $location) as $f) {
            if (in_array([$x, $y], self::furnitureTiles($f['type'], $f['x'], $f['y']), true)) {
                return false;
            }
        }
        return true;
    }
}
