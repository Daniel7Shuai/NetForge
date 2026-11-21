<?php
declare(strict_types=1);

namespace NetForge;

/**
 * Power, cooling and the electricity bill, per place.
 *
 * Every running device draws its item type's power_w (rack gear only runs in a rack with a PDU).
 * A place's supply is its grid connection plus any generators there (gen_w). If the load is more
 * than the supply, the place browns out and earns supply/load of what it would.
 * The load becomes heat. Cooling is the place's own plus any cooling units there (cool_w).
 * Temperature = ambient + 12°C x heat / cooling; above HOT, income there slows by 3% a degree,
 * down to half. The electricity bill takes billPct of what the place earns; upgrades cut it.
 */
final class Facility
{
    /** A place's grid supply (W), built-in cooling (W), ambient temperature (°C) and bill (% of income): its "power" in layout.json. */
    public static function place(string $location): array
    {
        return Layout::location($location)['power'];
    }
    public const HOT = 30;      // °C: income starts slowing above this
    public const MIN_BILL = 2;  // % of income: upgrades can't take the bill below this
    public const MIN_HEAT = 0.5;  // the slowest a hot place gets
    public const MIN_POWER = 0.25; // the slowest a browned-out place gets

    /**
     * Power and temperature for every place.
     * @return array<string, array{load:int, supply:int, cooling:int, heat:int, temp:float, ambient:int,
     *   heatFactor:float, powerFactor:float, factor:float, billPct:int, upgrades:list<string>}>
     */
    public static function all(int $userId): array
    {
        return Db::gearMemo("facility:$userId", fn () => self::build($userId));
    }

    private static function build(int $userId): array
    {
        $powered = Racks::powered($userId);
        $load = $gen = $cool = [];
        foreach (Db::all('SELECT d.location, d.tile_x, d.tile_y, d.rack_u, t.power_w, t.gen_w, t.cool_w
                            FROM devices d JOIN item_types t ON t.code = d.item_code WHERE d.user_id = ?', [$userId]) as $d) {
            $loc = $d['location'];
            if (!Racks::runs($d, $powered)) {
                continue; // no PDU: it's off, so it draws nothing
            }
            $load[$loc] = ($load[$loc] ?? 0) + (int) $d['power_w'];
            $gen[$loc] = ($gen[$loc] ?? 0) + (int) $d['gen_w'];
            $cool[$loc] = ($cool[$loc] ?? 0) + (int) $d['cool_w'];
        }
        $ups = [];
        foreach (Db::all('SELECT f.location, f.item_code, t.bill_cut, t.heat_cut, t.cool_boost
                            FROM user_facility f JOIN item_types t ON t.code = f.item_code WHERE f.user_id = ?', [$userId]) as $u) {
            $ups[$u['location']][] = $u;
        }
        $out = [];
        foreach (array_keys(Layout::data()['locations']) as $loc) {
            $out[$loc] = self::compute(self::place($loc), $load[$loc] ?? 0, $gen[$loc] ?? 0, $cool[$loc] ?? 0, $ups[$loc] ?? []);
        }
        return $out;
    }

    /** One place's numbers, from its load, generators, cooling units and upgrades. */
    public static function compute(array $place, int $load, int $gen, int $coolUnits, array $upgrades): array
    {
        $billCut = array_sum(array_column($upgrades, 'bill_cut'));
        $heatCut = min(50, array_sum(array_column($upgrades, 'heat_cut')));
        $coolBoost = array_sum(array_column($upgrades, 'cool_boost'));
        $supply = $place['grid'] + $gen;
        $heat = (int) round($load * (1 - $heatCut / 100));
        $cooling = (int) round(($place['cool'] + $coolUnits) * (1 + $coolBoost / 100));
        $temp = min(60.0, round($place['ambient'] + 12 * $heat / max(1, $cooling), 1));
        $heatFactor = $temp <= self::HOT ? 1.0 : max(self::MIN_HEAT, 1 - 0.03 * ($temp - self::HOT));
        $powerFactor = $load <= $supply ? 1.0 : max(self::MIN_POWER, $supply / $load);
        return [
            'load' => $load, 'supply' => $supply, 'grid' => $place['grid'], 'generators' => $gen,
            'heat' => $heat, 'cooling' => $cooling, 'temp' => $temp, 'ambient' => $place['ambient'], 'hot' => self::HOT,
            'heatFactor' => round($heatFactor, 3), 'powerFactor' => round($powerFactor, 3), 'factor' => round($heatFactor * $powerFactor, 3),
            'billPct' => max(self::MIN_BILL, $place['bill'] - $billCut), 'baseBillPct' => $place['bill'],
            'heatCut' => $heatCut, 'coolBoost' => $coolBoost,
            'upgrades' => array_column($upgrades, 'item_code'),
        ];
    }

    /** Places an upgrade can go in (all of them when facility_for is empty). */
    public static function placesFor(?string $facilityFor): array
    {
        return $facilityFor ? array_map('trim', explode(',', $facilityFor)) : array_keys(Layout::data()['locations']);
    }

    /**
     * Buys an efficiency upgrade for the place the student is in. Runs inside Rules::buy's transaction,
     * after the credit and level checks, before any money is taken.
     */
    public static function checkUpgrade(int $userId, string $code, ?string $facilityFor, string $name): string
    {
        $location = Db::one('SELECT location FROM users WHERE id = ?', [$userId])['location'];
        $placeName = strtolower(Layout::location($location)['name']);
        if (!in_array($location, self::placesFor($facilityFor), true)) {
            $where = array_map(fn ($l) => strtolower(Layout::location($l)['name']), self::placesFor($facilityFor));
            throw new GameError(sprintf('The %s is for the %s, not the %s. Go there first, then buy it.', $name, implode(' or the ', $where), $placeName));
        }
        if (Db::one('SELECT 1 FROM user_facility WHERE user_id = ? AND location = ? AND item_code = ?', [$userId, $location, $code])) {
            throw new GameError(sprintf('The %s already has %s.', $placeName, $name));
        }
        return $location;
    }

    public static function install(int $userId, string $location, string $code): void
    {
        Db::exec('INSERT INTO user_facility (user_id, location, item_code) VALUES (?, ?, ?)', [$userId, $location, $code]);
    }
}
