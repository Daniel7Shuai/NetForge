<?php
declare(strict_types=1);

namespace NetForge;

use NetForge\Progress\Income;

/** Furniture students buy and place (more spots for gear), and GPU cards that go in servers. */
final class Gear
{
    /** Puts a piece of furniture from the inventory on open floor at (x, y). */
    public static function placeFurniture(int $userId, string $itemCode, int $x, int $y): string
    {
        return Db::transaction(function () use ($userId, $itemCode, $x, $y): string {
            $item = Db::one('SELECT name, furniture_type FROM item_types WHERE code = ? AND category = "furniture"', [$itemCode]);
            if ($item === null) {
                throw new GameError('That isn’t furniture.');
            }
            $inv = Db::one('SELECT quantity FROM inventory WHERE user_id = ? AND item_code = ? FOR UPDATE', [$userId, $itemCode]);
            if ((int) ($inv['quantity'] ?? 0) < 1) {
                throw new GameError(sprintf('You have no %s left. Buy one in the shop.', strtolower($item['name'])));
            }
            $location = Db::one('SELECT location FROM users WHERE id = ? FOR UPDATE', [$userId])['location'];
            [$cols, $rows] = Layout::size($location);
            $blocked = Layout::staticBlocked($location);
            // other furniture (with its spot) and placed devices are in the way too
            foreach (Layout::userFurniture($userId, $location) as $f) {
                foreach (Layout::furnitureTiles($f['type'], $f['x'], $f['y']) as [$tx, $ty]) {
                    $blocked["$tx,$ty"] = true;
                }
            }
            foreach (Db::all('SELECT d.tile_x, d.tile_y, t.height FROM devices d JOIN item_types t ON t.code = d.item_code WHERE d.user_id = ? AND d.location = ?', [$userId, $location]) as $d) {
                for ($j = 0; $j < (int) $d['height']; $j++) {
                    $blocked[$d['tile_x'] . ',' . ((int) $d['tile_y'] + $j)] = true;
                }
            }
            foreach (Layout::furnitureTiles($item['furniture_type'], $x, $y) as [$tx, $ty]) {
                if ($tx < 0 || $ty < 0 || $tx >= $cols || $ty >= $rows || isset($blocked["$tx,$ty"])) {
                    throw new GameError(sprintf('There isn’t enough open floor there for the %s. Pick an empty area.', strtolower($item['name'])));
                }
            }
            Db::exec('INSERT INTO user_furniture (user_id, item_code, location, tile_x, tile_y) VALUES (?, ?, ?, ?, ?)', [$userId, $itemCode, $location, $x, $y]);
            Db::exec('UPDATE inventory SET quantity = quantity - 1 WHERE user_id = ? AND item_code = ?', [$userId, $itemCode]);
            $msg = sprintf('Placed a %s. It adds a spot for %s.', strtolower($item['name']), $item['furniture_type'] === 'desk' ? 'a PC' : 'a rack device');
            Rules::log($userId, 'ok', $msg);
            return $msg;
        });
    }

    /** Puts furniture back in the inventory, as long as nothing stands in its spot. */
    public static function pickUpFurniture(int $userId, int $id): void
    {
        Db::transaction(function () use ($userId, $id): void {
            $f = Db::one('SELECT f.id, f.item_code, f.location, f.tile_x, f.tile_y, t.name, t.furniture_type FROM user_furniture f JOIN item_types t ON t.code = f.item_code
                           WHERE f.id = ? AND f.user_id = ? FOR UPDATE', [$id, $userId]);
            if ($f === null) {
                throw new GameError('That furniture isn’t yours.');
            }
            $slot = Layout::furnitureSlot($f['furniture_type'], (int) $f['tile_x'], (int) $f['tile_y']);
            if ($slot && Db::one('SELECT 1 FROM devices d JOIN item_types t ON t.code = d.item_code
                                   WHERE d.user_id = ? AND d.location = ? AND d.tile_x = ? AND d.tile_y < ? AND d.tile_y + t.height > ?',
                    [$userId, $f['location'], $slot['x'], $slot['y'] + $slot['h'], $slot['y']])) {
                throw new GameError(sprintf('Pick up the device on the %s first.', strtolower($f['name'])));
            }
            Db::exec('DELETE FROM user_furniture WHERE id = ?', [$id]);
            Rules::giveItem($userId, $f['item_code']);
            Rules::log($userId, 'info', sprintf('Picked up the %s.', strtolower($f['name'])));
        });
    }

    /** Installs a GPU card from the inventory into a server. A card already in it goes back to the inventory. */
    public static function installCard(int $userId, int $deviceId, string $cardCode): string
    {
        Income::accrue($userId); // pay out at the old rate first
        return Db::transaction(function () use ($userId, $deviceId, $cardCode): string {
            $d = Db::one('SELECT id, label, item_code, gpu_card FROM devices WHERE id = ? AND user_id = ? FOR UPDATE', [$deviceId, $userId]);
            if ($d === null || $d['item_code'] !== 'server') {
                throw new GameError('GPU cards go in servers.');
            }
            $card = Db::one('SELECT name, boost_pct FROM item_types WHERE code = ? AND category = "part"', [$cardCode]);
            if ($card === null) {
                throw new GameError('That isn’t a GPU card.');
            }
            $inv = Db::one('SELECT quantity FROM inventory WHERE user_id = ? AND item_code = ? FOR UPDATE', [$userId, $cardCode]);
            if ((int) ($inv['quantity'] ?? 0) < 1) {
                throw new GameError(sprintf('You have no %s. Buy one from a GPU seller in the shop.', strtolower($card['name'])));
            }
            if ($d['gpu_card'] === $cardCode) {
                throw new GameError(sprintf('%s already has that card.', $d['label']));
            }
            if ($d['gpu_card'] !== null) {
                Rules::giveItem($userId, $d['gpu_card']);
            }
            Db::exec('UPDATE inventory SET quantity = quantity - 1 WHERE user_id = ? AND item_code = ?', [$userId, $cardCode]);
            Db::exec('UPDATE devices SET gpu_card = ? WHERE id = ?', [$cardCode, $deviceId]);
            $msg = sprintf('Installed a %s in %s: +%d%% income.', strtolower($card['name']), $d['label'], $card['boost_pct']);
            Rules::log($userId, 'ok', $msg);
            return $msg;
        });
    }
}
