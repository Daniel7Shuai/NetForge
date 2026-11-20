<?php
declare(strict_types=1);

namespace NetForge;

/** Builds the full state the client needs to draw the game. */
final class GameState
{
    public static function load(int $userId): array
    {
        // pay out passive income and award badges before reading the balance (FR-4.4, FR-4.5)
        \NetForge\Progress\Income::accrue($userId);
        $newBadges = \NetForge\Progress\Badges::award($userId);

        $user = Db::one('SELECT id, username, display_name, avatar, role, credits, xp, level, location, pos_x, pos_y FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            throw new GameError('Your account could not be found. Sign in again.');
        }
        $user = array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $user);
        $user['name'] = Accounts::name($user);
        $user['xpPerLevel'] = Db::configInt('xp_per_level', 1000);
        $location = $user['location'];
        $user['locationName'] = Layout::location($location)['name'];
        $user['auditDue'] = \NetForge\Learning\Learning::auditDue($userId);

        $inventory = array_map(fn ($r) => ['quantity' => (int) $r['quantity'], 'slotKinds' => Layout::kinds($r['slot_kinds']),
                'group' => $r['shop_group'], 'furniture' => $r['furniture_type'], 'boost' => (int) $r['boost_pct']] + $r, Db::all(
            'SELECT t.code, t.name, t.category, t.sprite, t.slot_kinds, t.description, t.shop_group, t.furniture_type, t.boost_pct, i.quantity
               FROM inventory i JOIN item_types t ON t.code = i.item_code
              WHERE i.user_id = ? AND i.quantity > 0 ORDER BY t.category DESC, t.name',
            [$userId]
        ));

        $mastered = \NetForge\Learning\Learning::masteredNumbers($userId);
        $shop = array_map(fn ($r) => ($r['seller'] ? ['seller' => $r['seller'], 'quote' => Market::quote($r, $userId)] : ['seller' => null, 'quote' => null]) + [
            'id'          => (int) $r['id'],
            'group'       => $r['shop_group'],
            'sprite'      => $r['sprite'],
            'boost'       => (int) $r['boost_pct'],
            'furniture'   => $r['furniture_type'],
            'code'        => $r['code'],
            'name'        => $r['name'],
            'category'    => $r['category'],
            'description' => $r['description'],
            'quantity'    => (int) $r['quantity'],
            'price'       => (int) $r['price'],
            'earns'       => (int) $r['income_per_sec'], // credits per second when online
            'unlockLevel' => (int) $r['unlock_level'],
            'unlockWorld' => $r['unlock_world'] === null ? null : (int) $r['unlock_world'],
            // locked until the student's XP level is high enough (and any required world is mastered)
            'locked'      => (int) $r['unlock_level'] > $user['level']
                || ($r['unlock_world'] !== null && !in_array((int) $r['unlock_world'], $mastered, true)),
        ], Db::all(
            'SELECT s.id, s.item_code, s.seller, s.quantity, s.price, s.unlock_level, s.unlock_world, t.code, t.name, t.category, t.description, t.income_per_sec,
                    t.shop_group, t.boost_pct, t.furniture_type, t.sprite
               FROM shop_items s JOIN item_types t ON t.code = s.item_code ORDER BY s.sort_order, s.id'
        ));

        $income = \NetForge\Progress\Income::summary($userId);
        $devices = array_map(fn ($r) => [
            'id'        => (int) $r['id'],
            'item_code' => $r['item_code'],
            'label'     => $r['label'],
            'tile_x'    => (int) $r['tile_x'],
            'tile_y'    => (int) $r['tile_y'],
            'sprite'    => $r['sprite'],
            'height'    => (int) $r['height'],
            'fixed'     => (bool) $r['fixed'],
            'slotKinds' => Layout::kinds($r['slot_kinds']),
            'tier'      => (int) $r['tier'],
            'earning'   => $income['byDevice'][(int) $r['id']] ?? 0, // credits per second, now (0 if offline)
            'rate'      => (int) round((int) $r['income_per_sec'] * Rules::tierIncome((int) $r['tier']) * (1 + (int) $r['gpu_boost'] / 100)), // per second when online
            'netBoost'  => (int) round(\NetForge\Progress\Income::networkBoost($r)), // % boost to end devices behind it (network gear)
            'gpuCard'   => $r['gpu_card'],
            'gpuBoost'  => (int) $r['gpu_boost'],
            'maxTier'   => (int) $r['max_tier'],
            'shield'    => (int) $r['shield_pct'] * (int) $r['tier'],
            'upgrade'   => $r['fixed'] ? null : Rules::upgradeInfo($r['item_code'], (int) $r['tier'], (int) $r['max_tier']),
            'ports'     => json_decode((string) $r['ports'], true) ?: [],
        ], Db::all(
            'SELECT d.id, d.item_code, d.label, d.tile_x, d.tile_y, d.fixed, d.tier, d.gpu_card, COALESCE(g.boost_pct, 0) gpu_boost,
                    t.max_tier, t.shield_pct, t.income_per_sec, t.slot_kinds, t.sprite, t.height, t.ports, t.shop_group, t.boost_pct
               FROM devices d JOIN item_types t ON t.code = d.item_code LEFT JOIN item_types g ON g.code = d.gpu_card WHERE d.user_id = ? AND d.location = ?',
            [$userId, $location]
        ));

        $cables = array_map(fn ($r) => [
            'id'          => (int) $r['id'],
            'kind'        => $r['cable_kind'],
            'a_device_id' => (int) $r['a_device_id'],
            'a_port'      => $r['a_port'],
            'b_device_id' => (int) $r['b_device_id'],
            'b_port'      => $r['b_port'],
            'code'        => $r['cable_code'],
            'status'      => $r['status'],
        ], Db::all(
            'SELECT c.*, t.cable_kind FROM cables c JOIN item_types t ON t.code = c.cable_code
               JOIN devices a ON a.id = c.a_device_id WHERE c.user_id = ? AND a.location = ?',
            [$userId, $location]
        ));

        $log = array_reverse(array_map(fn ($r) => [
            'kind'    => $r['kind'],
            'message' => $r['message'],
            'time'    => date('H:i:s', strtotime($r['created_at'])),
        ], Db::all('SELECT kind, message, created_at FROM event_log WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$userId])));

        $objective = Rules::currentObjective($userId);
        if ($objective !== null) {
            unset($objective['goal']);
        }

        $progress = [
            'income' => ['perSec' => $income['perSec'], 'devices' => $income['devices']],
            'shield' => \NetForge\Progress\Income::shield($userId),
            'streak' => \NetForge\Progress\Badges::streak($userId),
            'badges' => (int) Db::one('SELECT COUNT(*) n FROM user_badges WHERE user_id = ?', [$userId])['n'],
            'newBadges' => $newBadges,
        ];

        $locations = Rules::locations($userId);
        $furniture = Layout::userFurniture($userId, $location);
        $events = Events::poll($userId, false);

        return compact('user', 'inventory', 'shop', 'devices', 'cables', 'log', 'objective', 'progress', 'locations', 'furniture', 'events');
    }
}
