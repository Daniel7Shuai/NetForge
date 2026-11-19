<?php
declare(strict_types=1);

namespace NetForge;

/** What a brand-new player starts with. Edit here to change the opening. */
final class Starter
{
    public const LOCATION = 'garage';

    /** Just the busted laptop: everything else is earned by learning. */
    public const INVENTORY = [
        'laptop' => 1,
    ];

    public static function give(int $userId): void
    {
        $loc = Layout::location(self::LOCATION);
        Db::exec('UPDATE users SET location = ?, pos_x = ?, pos_y = ? WHERE id = ?', [self::LOCATION, $loc['spawn']['x'], $loc['spawn']['y'], $userId]);

        foreach (self::INVENTORY as $code => $qty) {
            Db::exec(
                'INSERT INTO inventory (user_id, item_code, quantity) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)',
                [$userId, $code, $qty]
            );
        }
        self::placeFixtures($userId, self::LOCATION);
        Rules::log($userId, 'info', 'You moved into the garage with a busted laptop. Check your objective.');
    }

    /** Puts a location's built-in devices (like the ISP box) on the map. */
    public static function placeFixtures(int $userId, string $location): void
    {
        foreach (Layout::location($location)['fixtures'] ?? [] as $f) {
            if (Db::one('SELECT 1 FROM devices WHERE user_id = ? AND location = ? AND tile_x = ? AND tile_y = ? AND fixed = 1', [$userId, $location, $f['x'], $f['y']])) {
                continue; // already there
            }
            $prefix = Db::one('SELECT label_prefix FROM item_types WHERE code = ?', [$f['item']])['label_prefix'] ?? 'Fixed';
            Db::exec(
                'INSERT INTO devices (user_id, item_code, label, location, tile_x, tile_y, fixed) VALUES (?, ?, ?, ?, ?, ?, 1)',
                [$userId, $f['item'], Rules::nextLabel($userId, $prefix), $location, $f['x'], $f['y']]
            );
        }
    }
}
