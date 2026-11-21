<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\Db;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Racks;

// POST {action: "mount", item, x, y, u} -> mounts rack gear in the rack at (x, y), at U u
// POST {action: "shift", device, u}     -> slides a mounted device to another U in its rack
// POST {action: "module", device, item} -> fits an interface module;  {action: "unmodule", device, bay}
// POST {action: "wish", item, on}       -> adds or removes a shop item from the wishlist
// All reply {state}.
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $in = Api::body();
    $int = fn (string $k) => is_int($in[$k] ?? null) ? $in[$k] : null;
    $str = fn (string $k) => is_string($in[$k] ?? null) ? $in[$k] : '';
    switch ($in['action'] ?? '') {
        case 'mount':
            if ($int('x') === null || $int('y') === null) {
                throw new GameError('Pick a rack.');
            }
            \NetForge\Progress\Income::accrue($uid);
            Db::transaction(fn () => Racks::mount($uid, $str('item'), $int('x'), $int('y'), $int('u')));
            break;
        case 'shift':
            Racks::shift($uid, (int) $int('device'), (int) $int('u'));
            break;
        case 'module':
            Racks::installModule($uid, (int) $int('device'), $str('item'));
            break;
        case 'unmodule':
            Racks::removeModule($uid, (int) $int('device'), (int) $int('bay'));
            break;
        case 'wish':
            if (!Db::one('SELECT 1 FROM shop_items WHERE item_code = ?', [$str('item')])) {
                throw new GameError('That isn’t in the shop.');
            }
            ($in['on'] ?? false)
                ? Db::exec('INSERT IGNORE INTO user_wishlist (user_id, item_code) VALUES (?, ?)', [$uid, $str('item')])
                : Db::exec('DELETE FROM user_wishlist WHERE user_id = ? AND item_code = ?', [$uid, $str('item')]);
            break;
        default:
            throw new GameError('Unknown rack action.');
    }
    Api::ok(['state' => GameState::load($uid)]);
});
