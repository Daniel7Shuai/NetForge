<?php
declare(strict_types=1);

namespace NetForge;

/** Builds the full state the client needs to draw the game. */
final class GameState
{
    public static function load(int $userId): array
    {
        // pay out passive income and award badges before reading the balance (FR-4.4, FR-4.5)
        MapMigration::run($userId); // older gear onto racks with U-slots and the redesigned office and datacentre, once
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
        $user['gameEnded'] = Ending::ended($userId);

        $inventory = array_map(fn ($r) => ['quantity' => (int) $r['quantity'], 'slotKinds' => Layout::kinds($r['slot_kinds']),
                'group' => $r['shop_group'], 'furniture' => $r['furniture_type'], 'boost' => (int) $r['boost_pct'],
                'rackU' => (int) $r['rack_u'], 'moduleFor' => $r['module_for'] ? explode(',', $r['module_for']) : null] + $r, Db::all(
            'SELECT t.code, t.name, t.category, t.sprite, t.slot_kinds, t.description, t.shop_group, t.furniture_type, t.boost_pct, t.rack_u, t.module_for, i.quantity
               FROM inventory i JOIN item_types t ON t.code = i.item_code
              WHERE i.user_id = ? AND i.quantity > 0 ORDER BY t.category DESC, t.name',
            [$userId]
        ));

        $facility = Facility::all($userId)[$location]; // power, cooling and temperature where the student is
        $mastered = \NetForge\Learning\Learning::masteredNumbers($userId);
        $topicsLeft = Ending::topicsLeft($userId);
        $shop = array_map(fn ($r) => ($r['seller'] ? ['seller' => $r['seller'], 'quote' => Market::quote($r, $userId)] : ['seller' => null, 'quote' => null]) + [
            'id'          => (int) $r['id'],
            'group'       => $r['shop_group'],
            'radio'       => $r['radio'],
            'rackU'       => (int) $r['rack_u'],
            'powerW'      => (int) $r['power_w'],
            'genW'        => (int) $r['gen_w'],
            'coolW'       => (int) $r['cool_w'],
            'facilityFor' => $r['category'] === 'upgrade' ? Facility::placesFor($r['facility_for']) : null,
            'installed'   => $r['category'] === 'upgrade' && in_array($r['code'], $facility['upgrades'], true), // already in this place
            'booster'     => in_array($r['shop_group'], \NetForge\Progress\Income::BOOST_GROUPS, true), // boosts what's behind it rather than earning
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
                || ($r['unlock_world'] !== null && !in_array((int) $r['unlock_world'], $mastered, true))
                || ($r['code'] === Ending::ITEM && $topicsLeft),
            'topicsLeft'  => $r['code'] === Ending::ITEM ? $topicsLeft : null, // topics to master before DataTech can be bought
        ], Db::all(
            'SELECT s.id, s.item_code, s.seller, s.quantity, s.price, s.unlock_level, s.unlock_world, t.code, t.name, t.category, t.description, t.income_per_sec,
                    t.shop_group, t.boost_pct, t.furniture_type, t.sprite, t.radio, t.rack_u, t.power_w, t.gen_w, t.cool_w, t.facility_for
               FROM shop_items s JOIN item_types t ON t.code = s.item_code ORDER BY s.sort_order, s.id'
        ));

        $income = \NetForge\Progress\Income::summary($userId);
        $devices = array_map(fn ($r) => [
            'id'        => (int) $r['id'],
            'item_code' => $r['item_code'],
            'label'     => $r['label'],
            'name'      => $r['name'],
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
            'radio'     => $r['radio'],      // ap, lap, wlc, client (Wi-Fi only) or none
            'siteBoost' => (int) $r['site_boost'],
            'gpuCard'   => $r['gpu_card'],
            'gpuBoost'  => (int) $r['gpu_boost'],
            'maxTier'   => (int) $r['max_tier'],
            'shield'    => (int) $r['shield_pct'] * (int) $r['tier'],
            'upgrade'   => $r['fixed'] ? null : Rules::upgradeInfo($r['item_code'], (int) $r['tier'], (int) $r['max_tier']),
            'ports'     => Racks::ports((string) $r['ports'], $r['modules']),
            'rackU'     => $r['rack_u'] === null ? null : (int) $r['rack_u'], // lowest U it fills, if it's in a rack
            'size'      => (int) $r['size'],                                   // how many U it fills
            'bays'      => (int) $r['bays'],
            'modules'   => Racks::moduleList($r['modules']),
            'rackBoost' => (int) $r['rack_boost'],
            'powerW'    => (int) $r['power_w'],
            'genW'      => (int) $r['gen_w'],
            'coolW'     => (int) $r['cool_w'],
        ], Db::all(
            'SELECT d.id, d.item_code, d.label, d.tile_x, d.tile_y, d.fixed, d.tier, d.gpu_card, d.rack_u, d.modules, t.rack_u size, t.bays, t.rack_boost, COALESCE(g.boost_pct, 0) gpu_boost,
                    t.name, t.power_w, t.gen_w, t.cool_w, t.max_tier, t.shield_pct, t.income_per_sec, t.slot_kinds, t.sprite, t.height, t.ports, t.shop_group, t.boost_pct, t.radio, t.site_boost
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
            'income' => ['perSec' => $income['perSec'], 'gross' => $income['gross'], 'bill' => $income['bill'], 'devices' => $income['devices']],
            'shield' => \NetForge\Progress\Income::shield($userId),
            'streak' => \NetForge\Progress\Badges::streak($userId),
            'badges' => (int) Db::one('SELECT COUNT(*) n FROM user_badges WHERE user_id = ?', [$userId])['n'],
            'newBadges' => $newBadges,
        ];

        $locations = Rules::locations($userId);
        $furniture = Layout::userFurniture($userId, $location);
        $events = Events::poll($userId, false);

        $powered = Racks::powered($userId, $location);
        $racks = array_map(fn ($r) => $r + ['size' => Racks::SIZE, 'powered' => isset($powered["$location:{$r['x']},{$r['y']}"])], Racks::inPlace($userId, $location));
        $wishlist = array_column(Db::all('SELECT item_code FROM user_wishlist WHERE user_id = ? ORDER BY added_at', [$userId]), 'item_code');
        $progress['upsHours'] = \NetForge\Progress\Income::upsHours($userId);

        // this place's bill in credits per second, from what its devices earn
        $facility['billPerSec'] = array_sum(array_column($devices, 'earning')) * $facility['billPct'] / 100;

        return compact('user', 'inventory', 'shop', 'devices', 'cables', 'log', 'objective', 'progress', 'locations', 'furniture', 'events', 'racks', 'wishlist', 'facility');
    }
}
