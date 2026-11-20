<?php
declare(strict_types=1);

// Run: php tests/EventsTest.php   (needs the netforge database with schema_events.sql)
// GPU sellers, furniture, GPU cards, the broker and investors. Makes its own student and removes it after.

require __DIR__ . '/../src/bootstrap.php';

use NetForge\Db;
use NetForge\Events;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Gear;
use NetForge\Layout;
use NetForge\Market;
use NetForge\Progress\Income;
use NetForge\Rules;

$passed = 0;
$failed = 0;
function check(string $name, mixed $got, mixed $want): void
{
    global $passed, $failed;
    if ($got === $want) {
        $passed++;
        echo "  ok    $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
    }
}
function err(callable $fn): string
{
    try {
        $fn();
        return 'no error';
    } catch (GameError $e) {
        return $e->getMessage();
    }
}

$tag = 'zt' . random_int(1000, 9999);
Db::exec('INSERT INTO users (username, email, password_hash, role, credits, level, location) VALUES (?, ?, ?, "student", 100000000, 9, "office")',
    ["{$tag}_ev", "{$tag}_ev@netforge.test", password_hash('x-password-1', PASSWORD_DEFAULT)]);
$uid = (int) Db::pdo()->lastInsertId();
$give = fn (string $code, int $n = 1) => Db::exec('INSERT INTO inventory (user_id, item_code, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)', [$uid, $code, $n]);
$qty = fn (string $code) => (int) (Db::one('SELECT quantity FROM inventory WHERE user_id = ? AND item_code = ?', [$uid, $code])['quantity'] ?? 0);

try {
    echo "GPU market\n";
    $row = Db::one('SELECT id, item_code, seller, price FROM shop_items WHERE item_code = "gpu_card_std"');
    $t = 1_800_000_000;
    $q = Market::quote($row, $uid, $t);
    check('price within 80-120% of list', $q['price'] >= 0.8 * $row['price'] && $q['price'] <= 1.2 * $row['price'], true);
    check('same window, same price', Market::quote($row, $uid, $t + 5)['price'], $q['price']);
    check('stock is 0 to 4', $q['stock'] >= 0 && $q['stock'] <= 4, true);
    check('restock countdown in the window', $q['restockIn'] > 0 && $q['restockIn'] <= Market::WINDOW_SECONDS, true);
    $prices = [];
    for ($w = 0; $w < 12; $w++) {
        $prices[] = Market::quote($row, $uid, $t + $w * Market::WINDOW_SECONDS)['price'];
    }
    check('prices change between windows', count(array_unique($prices)) > 1, true);

    // find a window-independent check of buying: buy until sold out
    $now = Market::quote($row, $uid);
    $credits = (int) Db::one('SELECT credits FROM users WHERE id = ?', [$uid])['credits'];
    if ($now['stock'] > 0) {
        Rules::buy($uid, (int) $row['id']);
        check('buying charges the quoted price', (int) Db::one('SELECT credits FROM users WHERE id = ?', [$uid])['credits'], $credits - $now['price']);
        check('stock goes down', Market::quote($row, $uid)['stock'], $now['stock'] - 1);
        for ($i = 1; $i < $now['stock']; $i++) {
            Rules::buy($uid, (int) $row['id']);
        }
    }
    check('sold out refuses', str_contains(err(fn () => Rules::buy($uid, (int) $row['id'])), 'sold out'), true);

    Db::exec('INSERT INTO user_buffs (user_id, kind, pct, expires_at) VALUES (?, "gpu_discount", 30, NOW() + INTERVAL 5 MINUTE)', [$uid]);
    $d = Market::quote($row, $uid, $t);
    check('broker discount comes off', $d['price'], (int) round($row['price'] * (($q['price'] / $row['price'])) * 0.7) === $d['price'] ? $d['price'] : -1);
    check('discount reported', $d['discount'], 30);
    Db::exec('DELETE FROM user_buffs WHERE user_id = ?', [$uid]);

    echo "Furniture (in the garage)\n";
    Db::exec('UPDATE users SET location = "garage" WHERE id = ?', [$uid]);
    $give('desk_kit', 2);
    check('desk on the car refused', str_contains(err(fn () => Gear::placeFurniture($uid, 'desk_kit', 12, 5)), 'open floor'), true);
    check('desk through the wall refused', str_contains(err(fn () => Gear::placeFurniture($uid, 'desk_kit', 3, 0)), 'open floor'), true);
    check('desk blocking the workbench spot refused', str_contains(err(fn () => Gear::placeFurniture($uid, 'desk_kit', 1, 2)), 'open floor'), true);
    Gear::placeFurniture($uid, 'desk_kit', 3, 5);
    check('desk used from inventory', $qty('desk_kit'), 1);
    $slots = Layout::slotsFor('garage', ['desk'], $uid);
    check('desk adds a PC spot', in_array(['kind' => 'desk', 'x' => 6, 'y' => 5, 'h' => 1], $slots, true), true);
    check('desk spot not shared with others', Layout::slotAnchoredAt('garage', ['desk'], 6, 5), null);
    check('second desk overlapping refused', str_contains(err(fn () => Gear::placeFurniture($uid, 'desk_kit', 4, 6)), 'open floor'), true);
    check('desk on another desk’s PC spot refused', str_contains(err(fn () => Gear::placeFurniture($uid, 'desk_kit', 6, 5)), 'open floor'), true);
    $give('pc');
    Rules::place($uid, 'pc', 6, 5);
    $fid = (int) Db::one('SELECT id FROM user_furniture WHERE user_id = ?', [$uid])['id'];
    check('desk with a PC on it stays', str_contains(err(fn () => Gear::pickUpFurniture($uid, $fid)), 'first'), true);
    $pc = (int) Db::one('SELECT id FROM devices WHERE user_id = ? AND item_code = "pc"', [$uid])['id'];
    Rules::pickUp($uid, $pc);
    Gear::pickUpFurniture($uid, $fid);
    check('desk back in inventory', $qty('desk_kit'), 2);
    Db::exec('UPDATE users SET location = "office" WHERE id = ?', [$uid]);
    $give('server_rack');
    Gear::placeFurniture($uid, 'server_rack', 13, 9);
    check('bought rack adds a rack slot', Layout::slotAnchoredAt('office', ['rack'], 13, 9, $uid) !== null, true);

    echo "GPU cards\n";
    $give('server');
    Rules::place($uid, 'server', 14, 3);
    $srv = (int) Db::one('SELECT id FROM devices WHERE user_id = ? AND item_code = "server"', [$uid])['id'];
    $give('gpu_card_budget');
    $give('gpu_card_pro');
    check('card needs a server', err(fn () => Gear::installCard($uid, 999999999, 'gpu_card_budget')), 'GPU cards go in servers.');
    Gear::installCard($uid, $srv, 'gpu_card_budget');
    check('card used from inventory', $qty('gpu_card_budget'), 0);
    Gear::installCard($uid, $srv, 'gpu_card_pro');
    check('swapped card goes back', $qty('gpu_card_budget'), 1);
    check('server has the pro card', Db::one('SELECT gpu_card FROM devices WHERE id = ?', [$srv])['gpu_card'], 'gpu_card_pro');
    $dev = array_values(array_filter(GameState::load($uid)['devices'], fn ($x) => $x['id'] === $srv))[0];
    check('server rate +70%', $dev['rate'], (int) round((int) Db::one('SELECT income_per_sec FROM item_types WHERE code = "server"')['income_per_sec'] * 1.7));

    echo "Events\n";
    check('not eligible before the office', Events::eligible($uid), false);
    Db::exec('INSERT INTO user_locations (user_id, location) VALUES (?, "office")', [$uid]);
    check('eligible after leasing the office', Events::eligible($uid), true);
    $p = Events::poll($uid, true);
    check('first poll only starts the clock', $p['event'], null);
    check('clock set', Db::one('SELECT next_event_at FROM users WHERE id = ?', [$uid])['next_event_at'] !== null, true);
    check('snapshot taken', (int) Db::one('SELECT COUNT(*) n FROM company_history WHERE user_id = ?', [$uid])['n'], 1);

    Events::start($uid, 'broker');
    $ev = Events::active($uid);
    check('broker appears', $ev['kind'] ?? null, 'broker');
    check('refusing ends it', Events::answerBroker($uid, $ev['id'], 'refuse')['outcome'], 'refused');
    check('gone after refusing', str_contains(err(fn () => Events::answerBroker($uid, $ev['id'], 'accept')), 'gone'), true);
    $outcomes = [];
    for ($i = 0; $i < 25; $i++) {
        Events::start($uid, 'broker');
        $before = (int) Db::one('SELECT credits FROM users WHERE id = ?', [$uid])['credits'];
        $r = Events::answerBroker($uid, Events::active($uid)['id'], 'accept');
        $outcomes[$r['outcome']] = true;
        if ($r['outcome'] === 'scam') {
            $lost = $before - (int) Db::one('SELECT credits FROM users WHERE id = ?', [$uid])['credits'];
            check('scam takes 3-6%', $lost === $r['amount'] && $lost >= $before * 0.03 - 1 && $lost <= $before * 0.06 + 1, true);
        } else {
            check('deal gives 20-40% off', in_array($r['pct'], [20, 30, 40], true) && Market::discount($uid) >= $r['pct'], true);
        }
    }
    check('both outcomes happen', isset($outcomes['deal'], $outcomes['scam']), true);

    Db::exec('UPDATE company_history SET at = NOW() - INTERVAL 40 MINUTE, value = 1 WHERE user_id = ?', [$uid]);
    $before = (int) Db::one('SELECT credits FROM users WHERE id = ?', [$uid])['credits'];
    Events::start($uid, 'investor');
    $msg = Db::one('SELECT body, amount FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
    check('investor texts', $msg !== null, true);
    check('growing company gets a dividend', (int) $msg['amount'] > 0 && (int) Db::one('SELECT credits FROM users WHERE id = ?', [$uid])['credits'] === $before + (int) $msg['amount'], true);
    check('unread text counted', Events::poll($uid, false)['unread'], 1);
    Events::markRead($uid);
    check('read clears it', Events::poll($uid, false)['unread'], 0);
    $ph = Events::phone($uid);
    check('phone has history for the chart', count($ph['history']) >= 2, true);

    echo "Tips seen\n";
    \NetForge\Story::setTutorial($uid, 'seen', 0, 'cable:fiber');
    check('seen flag saved', \NetForge\Story::tutorial($uid)['seen'], ['cable:fiber']);
    check('bad flag refused', err(fn () => \NetForge\Story::setTutorial($uid, 'seen', 0, '<x>')), 'Unknown tip.');
} finally {
    Db::exec('DELETE FROM users WHERE id = ?', [$uid]);
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
