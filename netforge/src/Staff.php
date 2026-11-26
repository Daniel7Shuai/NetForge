<?php
declare(strict_types=1);

namespace NetForge;

/**
 * Staff you hire in the office or datacentre. Resumes arrive three at a time; hire or reject each.
 *
 * Roles, by level 1 (junior) to 5 (principal):
 *  - earner: works a desktop in their place. Desktops earn only when someone works them (in the garage
 *    you work the first one yourself). Their desktop earns x1, x1.25 ... x2. Salary: salary_pct of
 *    what that desktop earns (about half).
 *  - admin: everything in their place earns 3% more per level. Salary: salary_pct of what they add.
 *  - technician: the gear in their place runs 6% cooler per level. Salary: salary_pct of the place's income.
 *  - security: blocks 5% per level of what an attack takes. Salary: salary_pct of all your income.
 */
final class Staff
{
    public const PLACES = ['office' => 8, 'datacentre' => 16]; // where you can hire, and how many fit
    public const POOL = 3;
    public const ROLES = [
        'earner' => 'Desk worker',
        'admin' => 'Network admin',
        'technician' => 'Datacentre technician',
        'security' => 'Security analyst',
    ];
    public const LEVELS = [1 => 'Junior', 2 => 'Associate', 3 => 'Mid-level', 4 => 'Senior', 5 => 'Principal'];

    /* ---------- what each one does ---------- */

    public static function earnerMult(int $level): float { return 1 + 0.25 * ($level - 1); }
    public static function adminBoost(int $level): int { return 3 * $level; }
    public static function techCut(int $level): int { return 6 * $level; }
    public static function shield(int $level): int { return 5 * $level; }

    /** One line on what they do and what they cost, for the resume and the team list. */
    public static function effect(string $role, int $level, int $pct): array
    {
        return match ($role) {
            'earner' => ['Works one desktop: it earns x' . rtrim(rtrim(number_format(self::earnerMult($level), 2), '0'), '.'), "Takes $pct% of what their desktop earns"],
            'admin' => ['Everything in their place earns ' . self::adminBoost($level) . '% more', "Takes $pct% of what they add"],
            'technician' => ['The gear in their place runs ' . self::techCut($level) . '% cooler', "Takes $pct% of their place’s income"],
            'security' => ['Blocks ' . self::shield($level) . '% of what an attack takes', "Takes $pct% of all your income"],
        };
    }

    /* ---------- resumes ---------- */

    private const FIRST = ['Aisha', 'Ben', 'Chen', 'Dara', 'Eli', 'Farah', 'Gus', 'Hana', 'Ivan', 'Jade', 'Kai', 'Lena', 'Maya', 'Nico', 'Omar', 'Priya', 'Quinn', 'Rosa', 'Sam', 'Tariq', 'Uma', 'Vik', 'Wren', 'Yusuf', 'Zoe'];
    private const LAST = ['Abdullah', 'Brooks', 'Chua', 'Davies', 'Eng', 'Fernandes', 'Goh', 'Hassan', 'Ito', 'Jansen', 'Kaur', 'Lim', 'Morales', 'Nair', 'Okafor', 'Petrov', 'Rahman', 'Silva', 'Tan', 'Wong'];
    private const FIRMS = ['Telstar Networks', 'Bluewave ISP', 'Cobalt Hosting', 'Meridian Bank', 'Northgate University', 'Pixel Forge Studios', 'Harbour Health', 'Skyline Retail', 'the city council', 'a startup that ran out of money'];
    private const CERTS = [
        'earner' => ['CompTIA A+', 'Google IT Support', 'ITIL Foundation'],
        'admin' => ['CCNA', 'CCNP Enterprise', 'CompTIA Network+'],
        'technician' => ['CompTIA Server+', 'CDCP (Data Centre Professional)', 'BICSI Installer'],
        'security' => ['CompTIA Security+', 'CCNA CyberOps', 'CEH'],
    ];
    private const SKILLS = [
        'earner' => ['Handles support tickets fast', 'Keeps clients happy', 'Spreadsheets and reports', 'Never misses a deadline'],
        'admin' => ['Routing and switching', 'VLANs and subnetting', 'Cisco IOS', 'Network monitoring'],
        'technician' => ['Rack and stack', 'Cable management', 'Cooling and airflow', 'Power and UPS maintenance'],
        'security' => ['Firewall rules', 'Phishing awareness training', 'Log analysis', 'Incident response'],
    ];

    /** A random candidate: role, level (juniors are common, principals rare) and what they'll ask for. */
    public static function generate(?string $role = null): array
    {
        $role ??= array_rand(self::ROLES);
        $roll = random_int(1, 100);
        $level = $roll <= 35 ? 1 : ($roll <= 65 ? 2 : ($roll <= 85 ? 3 : ($roll <= 95 ? 4 : 5)));
        $pct = in_array($role, ['earner', 'admin'], true)
            ? max(40, min(62, 50 + ($level - 3) * 3 + random_int(-6, 6)))   // about half of what they bring in
            : max(1, min(6, $level + random_int(0, 1)));                    // a share of income
        $pick = fn (array $a, int $n) => array_slice((function () use ($a) { shuffle($a); return $a; })(), 0, $n);
        $resume = [
            'years' => $level * 2 + random_int(0, 2),
            'previous' => self::FIRMS[array_rand(self::FIRMS)],
            'certs' => $pick(self::CERTS[$role], min(3, max(1, intdiv($level + 1, 2)))),
            'skills' => $pick(self::SKILLS[$role], 3),
        ];
        return ['name' => self::FIRST[array_rand(self::FIRST)] . ' ' . self::LAST[array_rand(self::LAST)],
            'role' => $role, 'level' => $level, 'salary_pct' => $pct, 'resume' => $resume];
    }

    /** The resumes waiting, topped up to three. There's always at least one earner: desktops need them most. */
    public static function candidates(int $userId): array
    {
        $roles = array_column(Db::all('SELECT role FROM staff_candidates WHERE user_id = ?', [$userId]), 'role');
        for ($i = count($roles); $i < self::POOL; $i++) {
            $role = !in_array('earner', $roles, true) && $i === self::POOL - 1 ? 'earner' : null;
            $roles[] = self::add($userId, $role);
        }
        return array_map([self::class, 'row'], Db::all('SELECT * FROM staff_candidates WHERE user_id = ? ORDER BY id', [$userId]));
    }

    private static function add(int $userId, ?string $role = null): string
    {
        $c = self::generate($role);
        Db::exec('INSERT INTO staff_candidates (user_id, name, role, level, salary_pct, resume) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $c['name'], $c['role'], $c['level'], $c['salary_pct'], json_encode($c['resume'])]);
        return $c['role'];
    }

    /** A candidate or staff row, ready for the client. */
    public static function row(array $r): array
    {
        [$does, $costs] = self::effect($r['role'], (int) $r['level'], (int) $r['salary_pct']);
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'role' => $r['role'], 'roleName' => self::ROLES[$r['role']],
            'level' => (int) $r['level'], 'levelName' => self::LEVELS[(int) $r['level']], 'salaryPct' => (int) $r['salary_pct'],
            'does' => $does, 'costs' => $costs, 'resume' => json_decode((string) $r['resume'], true) ?: [],
            'location' => $r['location'] ?? null];
    }

    /* ---------- hiring ---------- */

    public static function hire(int $userId, int $candidateId): string
    {
        return Db::transaction(function () use ($userId, $candidateId): string {
            $loc = Db::one('SELECT location FROM users WHERE id = ? FOR UPDATE', [$userId])['location'];
            if (!isset(self::PLACES[$loc])) {
                throw new GameError('You can only hire in the office or the datacentre. Lease one first, then go there.');
            }
            $c = Db::one('SELECT * FROM staff_candidates WHERE id = ? AND user_id = ?', [$candidateId, $userId]);
            if ($c === null) {
                throw new GameError('That resume isn’t on your desk any more.');
            }
            $count = (int) Db::one('SELECT COUNT(*) n FROM staff WHERE user_id = ? AND location = ?', [$userId, $loc])['n'];
            if ($count >= self::PLACES[$loc]) {
                throw new GameError(sprintf('The %s is full: %d staff at most. Let someone go first, or hire in the other place.', strtolower(Layout::location($loc)['name']), self::PLACES[$loc]));
            }
            Db::exec('INSERT INTO staff (user_id, name, role, level, salary_pct, location, resume) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, $c['name'], $c['role'], $c['level'], $c['salary_pct'], $loc, $c['resume']]);
            Db::exec('DELETE FROM staff_candidates WHERE id = ?', [$candidateId]);
            self::candidates($userId); // a new resume arrives
            $msg = sprintf('Hired %s, %s %s, in the %s.', $c['name'], strtolower(self::LEVELS[(int) $c['level']]), strtolower(self::ROLES[$c['role']]), strtolower(Layout::location($loc)['name']));
            Rules::log($userId, 'ok', $msg);
            return $msg;
        });
    }

    public static function reject(int $userId, int $candidateId): void
    {
        Db::transaction(function () use ($userId, $candidateId): void {
            if (Db::exec('DELETE FROM staff_candidates WHERE id = ? AND user_id = ?', [$candidateId, $userId]) === 0) {
                throw new GameError('That resume isn’t on your desk any more.');
            }
            self::candidates($userId); // a new resume arrives
        });
    }

    public static function fire(int $userId, int $staffId): void
    {
        $s = Db::one('SELECT name FROM staff WHERE id = ? AND user_id = ?', [$staffId, $userId]);
        if ($s === null) {
            throw new GameError('They don’t work for you.');
        }
        Db::exec('DELETE FROM staff WHERE id = ?', [$staffId]);
        Rules::log($userId, 'info', sprintf('%s has left.', $s['name']));
    }

    /** Everyone working for you, all places. */
    public static function team(int $userId): array
    {
        return Db::gearMemo("team:$userId", fn () => Db::all('SELECT * FROM staff WHERE user_id = ? ORDER BY location, role, level DESC, id', [$userId]));
    }

    /**
     * Who works each desktop: desktop id => [staff id or null for you, multiplier, salary %, name].
     * In each place, earners take the desktops in order, most senior first. In the garage you work the
     * first desktop yourself. A desktop missing from the map earns nothing.
     */
    public static function desks(int $userId): array
    {
        return Db::gearMemo("desks:$userId", function () use ($userId): array {
            $earners = [];
            foreach (self::team($userId) as $s) {
                if ($s['role'] === 'earner') {
                    $earners[$s['location']][] = $s;
                }
            }
            foreach ($earners as &$list) {
                usort($list, fn ($a, $b) => (int) $b['level'] <=> (int) $a['level'] ?: (int) $a['id'] <=> (int) $b['id']);
            }
            unset($list);
            $out = [];
            foreach (Db::all('SELECT id, location FROM devices WHERE user_id = ? AND item_code = "pc" ORDER BY id', [$userId]) as $pc) {
                $loc = $pc['location'];
                if ($loc === 'garage' && !isset($out['garage-self'])) {
                    $out['garage-self'] = true;
                    $out[(int) $pc['id']] = ['staff' => null, 'mult' => 1.0, 'pct' => 0, 'name' => 'you'];
                    continue;
                }
                $e = isset($earners[$loc]) ? array_shift($earners[$loc]) : null;
                if ($e !== null) {
                    $out[(int) $pc['id']] = ['staff' => (int) $e['id'], 'mult' => self::earnerMult((int) $e['level']), 'pct' => (int) $e['salary_pct'], 'name' => $e['name']];
                }
            }
            unset($out['garage-self']);
            return $out;
        });
    }

    /** Admin boosts per place: place => list of [boost %, salary %]. */
    public static function admins(int $userId): array
    {
        $out = [];
        foreach (self::team($userId) as $s) {
            if ($s['role'] === 'admin') {
                $out[$s['location']][] = ['id' => (int) $s['id'], 'boost' => self::adminBoost((int) $s['level']), 'pct' => (int) $s['salary_pct']];
            }
        }
        return $out;
    }

    /** How much cooler technicians make each place, in %. */
    public static function heatCut(int $userId): array
    {
        $out = [];
        foreach (self::team($userId) as $s) {
            if ($s['role'] === 'technician') {
                $out[$s['location']] = ($out[$s['location']] ?? 0) + self::techCut((int) $s['level']);
            }
        }
        return $out;
    }

    /** Percent of an attack's damage blocked by security analysts. */
    public static function shieldPct(int $userId): int
    {
        $n = 0;
        foreach (self::team($userId) as $s) {
            $n += $s['role'] === 'security' ? self::shield((int) $s['level']) : 0;
        }
        return $n;
    }
}
