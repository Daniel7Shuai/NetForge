<?php
declare(strict_types=1);

namespace NetForge;

/** Server-side view of public/assets/data/layout.json (floor plans per location). */
final class Layout
{
    /** Player-facing hint for each slot kind. Mirrors KIND_HINTS in store.js. */
    public const KIND_HINTS = [
        'bench' => 'That goes on the workbench.',
        'shelf' => 'That goes on the shelf.',
        'desk'  => 'PCs go on the spot beside a desk.',
        'rack'  => 'Racks go in the marked slots in the server room.',
    ];

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

    /** Tiles that aren't open floor: walls, the partition, built-in furniture and fixtures. */
    public static function staticBlocked(string $location): array
    {
        $loc = self::location($location);
        $data = self::data();
        $b = [];
        for ($x = 0; $x < $data['cols']; $x++) {
            for ($y = 0; $y < $loc['wallRows']; $y++) {
                $b["$x,$y"] = true;
            }
            if (!empty($loc['bottomWall'])) {
                $b["$x," . ($data['rows'] - 1)] = true;
            }
        }
        if (!empty($loc['partition'])) {
            for ($y = $loc['wallRows']; $y < $data['rows']; $y++) {
                if (!in_array($y, $loc['partition']['doorRows'], true)) {
                    $b[$loc['partition']['col'] . ",$y"] = true;
                }
            }
        }
        foreach ($loc['furniture'] as $f) {
            [$w, $h] = $data['footprints'][$f['type']] ?? [1, 1];
            for ($i = 0; $i < $w; $i++) {
                for ($j = 0; $j < $h; $j++) {
                    $b[($f['x'] + $i) . ',' . ($f['y'] + $j)] = true;
                }
            }
        }
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
        return null;
    }
}
