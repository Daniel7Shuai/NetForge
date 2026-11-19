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

    /** Spots in a location that accept any of the kinds. Mirrors slotsFor() in layout.js. */
    public static function slotsFor(string $location, array $kinds): array
    {
        return array_values(array_filter(
            self::location($location)['slots'],
            fn ($s) => in_array($s['kind'], $kinds, true)
        ));
    }

    /** The slot whose anchor tile is exactly (x, y), or null. */
    public static function slotAnchoredAt(string $location, array $kinds, int $x, int $y): ?array
    {
        foreach (self::slotsFor($location, $kinds) as $s) {
            if ($s['x'] === $x && $s['y'] === $y) {
                return $s;
            }
        }
        return null;
    }
}
