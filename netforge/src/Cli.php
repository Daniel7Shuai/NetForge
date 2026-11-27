<?php
declare(strict_types=1);

namespace NetForge;

/**
 * A small Cisco IOS command line for routers and switches, reached from a Work Laptop over a console
 * cable. Enough of IOS for the CCNA basic device setup: modes (user, privileged, global config,
 * interface config), abbreviations (conf t, int g0/0, sh ip int br, wr), hostname, enable secret,
 * interface ip address / shutdown / description, show running-config / startup-config /
 * ip interface brief, and saving with copy running-config startup-config or write memory.
 *
 * Each device keeps {running, startup} in devices.config. A device whose saved (startup) config has a
 * hostname, an enable secret and an interface with an IP that's up is "configured": it boosts 25% more.
 */
final class Cli
{
    public const BONUS = 25; // % more boost for a configured device
    public const MODES = ['user', 'priv', 'config', 'if', 'pass'];
    private const LONG = ['fa' => 'FastEthernet', 'gi' => 'GigabitEthernet', 'eth' => 'Ethernet', 'se' => 'Serial'];

    /* ---------- which devices, and the console link ---------- */

    /** Does this device (a row with sprite and ports) run IOS on its console? Routers and switches. */
    public static function supports(array $d): bool
    {
        return in_array($d['sprite'] ?? '', ['router1u', 'switch1u'], true) && self::hasConsole($d);
    }

    private static function hasConsole(array $d): bool
    {
        foreach (self::portsOf($d) as $p) {
            if (($p['kind'] ?? '') === 'console') {
                return true;
            }
        }
        return false;
    }

    private static function portsOf(array $d): array
    {
        return is_array($d['ports'] ?? null) ? $d['ports'] : (json_decode((string) ($d['ports'] ?? '[]'), true) ?: []);
    }

    private static function isSwitch(array $d): bool
    {
        return ($d['sprite'] ?? '') === 'switch1u';
    }

    /**
     * The device a Work Laptop is consoled into, or the Work Laptop consoled into a device:
     * [laptop row, device row] when a console cable joins a Work Laptop to a supported device.
     */
    public static function link(int $userId, int $deviceId): ?array
    {
        foreach (Db::all('SELECT c.a_device_id a, c.b_device_id b FROM cables c JOIN item_types t ON t.code = c.cable_code
                           WHERE c.user_id = ? AND t.cable_kind = "console" AND (c.a_device_id = ? OR c.b_device_id = ?)', [$userId, $deviceId, $deviceId]) as $c) {
            $ends = array_map(fn ($id) => Db::one('SELECT d.id, d.label, d.item_code, d.config, d.modules, t.name, t.sprite, t.ports FROM devices d JOIN item_types t ON t.code = d.item_code WHERE d.id = ?', [(int) $id]), [$c['a'], $c['b']]);
            foreach ([[0, 1], [1, 0]] as [$i, $j]) {
                if ($ends[$i] && $ends[$j] && $ends[$i]['item_code'] === 'work_laptop' && self::supports($ends[$j])) {
                    $ends[$j]['ports'] = Racks::ports((string) $ends[$j]['ports'], $ends[$j]['modules']);
                    return [$ends[$i], $ends[$j]];
                }
            }
        }
        return null;
    }

    /* ---------- the config ---------- */

    /** A device's config: {running, startup}, with defaults for a device never configured. */
    public static function load(?string $json, array $device): array
    {
        $c = json_decode((string) $json, true);
        $c = is_array($c) ? $c : [];
        $c['running'] ??= self::factory($device);
        $c['startup'] ??= null;
        return $c;
    }

    /** The out-of-the-box config: router interfaces shut down, switch ports up, Vlan1 shut down. */
    public static function factory(array $d): array
    {
        $sw = self::isSwitch($d);
        $ifaces = [];
        foreach (self::portsOf($d) as $p) {
            if (($p['kind'] ?? '') !== 'console') {
                $ifaces[$p['name']] = ['ip' => null, 'mask' => null, 'shutdown' => !$sw, 'description' => null];
            }
        }
        if ($sw) {
            $ifaces['Vlan1'] = ['ip' => null, 'mask' => null, 'shutdown' => true, 'description' => null];
        }
        return ['hostname' => $sw ? 'Switch' : 'Router', 'secret' => null, 'ifaces' => $ifaces];
    }

    /** The CCNA basic setup, step by step, on a config. */
    public static function checks(array $cfg, array $d): array
    {
        $sw = self::isSwitch($d);
        $ipUp = false;
        foreach ($cfg['ifaces'] ?? [] as $name => $i) {
            if ($i['ip'] && !$i['shutdown'] && (!$sw || $name === 'Vlan1')) {
                $ipUp = true;
            }
        }
        return [
            ['hostname', 'Give it a hostname (hostname NAME)', ($cfg['hostname'] ?? '') !== ($sw ? 'Switch' : 'Router')],
            ['secret', 'Protect privileged mode (enable secret PASSWORD)', !empty($cfg['secret'])],
            ['ip', $sw ? 'Give interface Vlan1 an IP address and bring it up (no shutdown)' : 'Give an interface an IP address and bring it up (no shutdown)', $ipUp],
        ];
    }

    /** The checklist the terminal shows: each step on the running config, plus whether it's saved. */
    public static function checklist(?string $json, array $d): array
    {
        $c = self::load($json, $d);
        $steps = array_map(fn ($s) => ['key' => $s[0], 'label' => $s[1], 'done' => $s[2]], self::checks($c['running'], $d));
        $steps[] = ['key' => 'saved', 'label' => 'Save it (copy running-config startup-config)', 'done' => $c['startup'] !== null && $c['startup'] == $c['running']];
        return $steps;
    }

    /** Configured: the saved startup-config passes every step. */
    public static function configured(?string $json, array $d): bool
    {
        if ($json === null || $json === '') {
            return false;
        }
        $c = self::load($json, $d);
        if ($c['startup'] === null) {
            return false;
        }
        foreach (self::checks($c['startup'], $d) as [, , $ok]) {
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /* ---------- running a command ---------- */

    /**
     * Runs one line typed at the console. $mode and $iface say where the student is (the client keeps
     * them between lines). Returns [output, mode, iface, prompt, saved]. Config changes are stored.
     */
    public static function run(int $userId, int $deviceId, string $line, string $mode, ?string $iface): array
    {
        $d = Db::one('SELECT d.id, d.config, d.modules, t.sprite, t.ports FROM devices d JOIN item_types t ON t.code = d.item_code WHERE d.id = ? AND d.user_id = ?', [$deviceId, $userId]);
        if ($d === null) {
            throw new GameError('That device isn’t on your map.');
        }
        $d['ports'] = Racks::ports((string) $d['ports'], $d['modules']);
        $mode = in_array($mode, self::MODES, true) ? $mode : 'user';
        $cfg = self::load($d['config'], $d);
        $run = new CliRun($userId, $d, $cfg, $mode, $iface);
        $out = $run->exec(trim($line));
        if ($run->cfg != $cfg) {
            Db::exec('UPDATE devices SET config = ? WHERE id = ?', [json_encode($run->cfg), $deviceId]);
        }
        return [$out, $run->mode, $run->iface, self::prompt($run->cfg, $run->mode, $run->iface), $run->saved];
    }

    public static function prompt(array $cfg, string $mode, ?string $iface): string
    {
        $h = $cfg['running']['hostname'];
        return match ($mode) {
            'pass' => 'Password:',
            'priv' => "$h#",
            'config' => "$h(config)#",
            'if' => "$h(config-if)#",
            default => "$h>",
        };
    }

    /* ---------- helpers shared with CliRun ---------- */

    /** The long IOS name of a port: Gi0/0 → GigabitEthernet0/0. */
    public static function longName(string $port): string
    {
        if (preg_match('/^([A-Za-z]+)([\d\/]+)$/', $port, $m) && isset(self::LONG[strtolower($m[1])])) {
            return self::LONG[strtolower($m[1])] . $m[2];
        }
        return $port;
    }

    /** The interface a student typed (g0/0, gig 0/0, fastethernet0/1, vlan 1), as the device's port name. */
    public static function findIface(array $cfg, string $typed): ?string
    {
        if (!preg_match('/^([a-z]+)\s*([\d\/]+)$/i', trim($typed), $m)) {
            return null;
        }
        [$alpha, $nums] = [strtolower($m[1]), $m[2]];
        foreach (array_keys($cfg['running']['ifaces']) as $name) {
            if (!preg_match('/^([A-Za-z]+)([\d\/]+)$/', $name, $p) || $p[2] !== $nums) {
                continue;
            }
            $long = strtolower(self::longName($name));
            $short = strtolower($p[1]);
            if (str_starts_with(preg_replace('/[\d\/]+$/', '', $long), $alpha) || str_starts_with($short, $alpha)) {
                return $name;
            }
        }
        return null;
    }

    public static function validIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    }

    public static function validMask(string $mask): bool
    {
        if (!self::validIp($mask)) {
            return false;
        }
        $bits = sprintf('%032b', ip2long($mask));
        return $mask !== '0.0.0.0' && !str_contains(ltrim($bits, '1'), '1');
    }
}

/** One command line being run against a device's config (kept apart from Cli's static helpers). */
final class CliRun
{
    public bool $saved = false;

    public function __construct(private int $userId, private array $d, public array $cfg, public string $mode, public ?string $iface)
    {
    }

    private const HELP = [
        'user' => ['enable' => 'Turn on privileged commands', 'show' => 'Show running system information', 'exit' => 'Exit from the EXEC'],
        'priv' => ['configure' => 'Enter configuration mode', 'copy' => 'Copy from one file to another', 'disable' => 'Turn off privileged commands',
            'show' => 'Show running system information', 'write' => 'Write running configuration to memory', 'exit' => 'Exit from the EXEC'],
        'config' => ['enable' => 'Modify enable password parameters', 'hostname' => 'Set system\'s network name', 'interface' => 'Select an interface to configure',
            'do' => 'To run exec commands in config mode', 'no' => 'Negate a command or set its defaults', 'end' => 'Exit to privileged EXEC mode', 'exit' => 'Exit from configure mode'],
        'if' => ['ip' => 'Interface Internet Protocol config commands', 'shutdown' => 'Shutdown the selected interface', 'no' => 'Negate a command or set its defaults',
            'description' => 'Interface specific description', 'interface' => 'Select an interface to configure', 'do' => 'To run exec commands in config mode',
            'end' => 'Exit to privileged EXEC mode', 'exit' => 'Exit from interface configuration mode'],
    ];

    public function exec(string $line): string
    {
        if ($this->mode === 'pass') { // the line is the enable secret
            $ok = hash('sha256', $line) === ($this->cfg['running']['secret'] ?? '');
            $this->mode = $ok ? 'priv' : 'user';
            return $ok ? '' : '% Access denied';
        }
        if ($line === '') {
            return '';
        }
        if (str_ends_with($line, '?')) {
            return $this->help(rtrim(substr($line, 0, -1)));
        }
        $t = preg_split('/\s+/', $line);
        return match ($this->mode) {
            'user' => $this->user($t, $line),
            'priv' => $this->priv($t, $line),
            'config' => $this->config($t, $line),
            'if' => $this->iface($t, $line),
            default => '',
        };
    }

    /** Matches an abbreviation against the commands possible here: the full word, '' if ambiguous, null if none. */
    private function word(string $typed, array $opts): ?string
    {
        $typed = strtolower($typed);
        $hits = array_values(array_filter($opts, fn ($o) => str_starts_with($o, $typed)));
        if (in_array($typed, $hits, true)) {
            return $typed;
        }
        return count($hits) === 1 ? $hits[0] : (count($hits) > 1 ? '' : null);
    }

    private function invalid(string $line, int $at): string
    {
        $pos = 0;
        foreach (array_slice(preg_split('/\s+/', $line), 0, $at) as $w) {
            $pos += strlen($w) + 1;
        }
        return str_repeat(' ', $pos + strlen(Cli::prompt($this->cfg, $this->mode, $this->iface))) . "^\n% Invalid input detected at '^' marker.";
    }

    private function bad(?string $w, string $line, int $at): string
    {
        return $w === '' ? "% Ambiguous command:  \"$line\"" : $this->invalid($line, $at);
    }

    private function user(array $t, string $line): string
    {
        $w = $this->word($t[0], ['enable', 'show', 'exit', 'logout']);
        if ($w === 'enable') {
            if (!empty($this->cfg['running']['secret'])) {
                $this->mode = 'pass';
                return '';
            }
            $this->mode = 'priv';
            return '';
        }
        if ($w === 'show') {
            return $this->show(array_slice($t, 1), $line, 1, false);
        }
        if ($w === 'exit' || $w === 'logout') {
            return "\n{$this->cfg['running']['hostname']} con0 is now available\n\nPress RETURN to get started.";
        }
        return $this->bad($w, $line, 0);
    }

    private function priv(array $t, string $line): string
    {
        $w = $this->word($t[0], ['configure', 'copy', 'disable', 'show', 'write', 'exit', 'logout', 'enable']);
        switch ($w) {
            case 'configure':
                if (isset($t[1]) && $this->word($t[1], ['terminal']) !== 'terminal') {
                    return $this->invalid($line, 1);
                }
                $this->mode = 'config';
                return 'Enter configuration commands, one per line.  End with CNTL/Z.';
            case 'copy':
                $a = $this->word($t[1] ?? '', ['running-config', 'startup-config']);
                $b = $this->word($t[2] ?? '', ['running-config', 'startup-config']);
                if ($a === 'running-config' && $b === 'startup-config') {
                    return "Destination filename [startup-config]? \n" . $this->save();
                }
                return isset($t[2]) ? $this->invalid($line, 2) : '% Incomplete command.';
            case 'write':
                if (isset($t[1]) && $this->word($t[1], ['memory']) !== 'memory') {
                    return $this->invalid($line, 1);
                }
                return $this->save();
            case 'disable':
                $this->mode = 'user';
                return '';
            case 'enable':
                return '';
            case 'show':
                return $this->show(array_slice($t, 1), $line, 1, true);
            case 'exit':
            case 'logout':
                $this->mode = 'user';
                return "\n{$this->cfg['running']['hostname']} con0 is now available\n\nPress RETURN to get started.";
        }
        return $this->bad($w, $line, 0);
    }

    private function config(array $t, string $line, int $off = 0): string
    {
        $w = $this->word($t[$off] ?? '', ['hostname', 'enable', 'interface', 'do', 'no', 'end', 'exit']);
        $r = &$this->cfg['running'];
        switch ($w) {
            case 'hostname':
                $name = $t[$off + 1] ?? '';
                if ($name === '') {
                    return '% Incomplete command.';
                }
                if (isset($t[$off + 2])) {
                    return $this->invalid($line, $off + 2); // IOS takes one word
                }
                if (!preg_match('/^[A-Za-z][A-Za-z0-9-]{0,62}$/', $name)) {
                    return '% Hostname contains one or more illegal characters.';
                }
                $r['hostname'] = $name;
                return '';
            case 'enable':
                if ($this->word($t[$off + 1] ?? '', ['secret']) !== 'secret') {
                    return isset($t[$off + 1]) ? $this->invalid($line, $off + 1) : '% Incomplete command.';
                }
                $pw = $t[$off + 2] ?? '';
                if ($pw === '') {
                    return '% Incomplete command.';
                }
                $r['secret'] = hash('sha256', $pw);
                return '';
            case 'interface':
                $name = Cli::findIface($this->cfg, implode(' ', array_slice($t, $off + 1)));
                if ($name === null) {
                    return isset($t[$off + 1]) ? $this->invalid($line, $off + 1) : '% Incomplete command.';
                }
                $this->mode = 'if';
                $this->iface = $name;
                return '';
            case 'do':
                return $this->show(array_slice($t, $off + 2), $line, $off + 2, true, $t[$off + 1] ?? '');
            case 'no':
                $what = $this->word($t[$off + 1] ?? '', ['hostname', 'enable']);
                if ($what === 'hostname') {
                    $r['hostname'] = Cli::factory($this->d)['hostname'];
                    return '';
                }
                if ($what === 'enable') {
                    $r['secret'] = null;
                    return '';
                }
                return $this->bad($what, $line, $off + 1);
            case 'end':
                $this->mode = 'priv';
                $this->iface = null;
                return '';
            case 'exit':
                $this->mode = $this->mode === 'if' ? 'config' : 'priv';
                $this->iface = null;
                return '';
        }
        return $this->bad($w, $line, $off);
    }

    private function iface(array $t, string $line): string
    {
        $w = $this->word($t[0], ['ip', 'shutdown', 'no', 'description', 'interface', 'do', 'end', 'exit', 'hostname', 'enable']);
        $name = $this->iface;
        if ($name === null || !isset($this->cfg['running']['ifaces'][$name])) {
            $this->mode = 'config';
            return $this->config($t, $line);
        }
        $i = &$this->cfg['running']['ifaces'][$name];
        switch ($w) {
            case 'ip':
                if ($this->word($t[1] ?? '', ['address']) !== 'address') {
                    return isset($t[1]) ? $this->invalid($line, 1) : '% Incomplete command.';
                }
                if (!isset($t[2], $t[3])) {
                    return '% Incomplete command.';
                }
                if (!Cli::validIp($t[2])) {
                    return $this->invalid($line, 2);
                }
                if (!Cli::validMask($t[3])) {
                    return "% Bad mask {$t[3]} for address {$t[2]}";
                }
                if ($this->isPort($name) && ($this->d['sprite'] ?? '') === 'switch1u') {
                    return $this->invalid($line, 0) . "\n(Switch ports work at Layer 2: give the switch its IP on interface Vlan1.)";
                }
                $i['ip'] = $t[2];
                $i['mask'] = $t[3];
                return '';
            case 'shutdown':
                $i['shutdown'] = true;
                return $this->changed($name, true);
            case 'no':
                $what = $this->word($t[1] ?? '', ['shutdown', 'ip', 'description']);
                if ($what === 'shutdown') {
                    $i['shutdown'] = false;
                    return $this->changed($name, false);
                }
                if ($what === 'ip') {
                    $i['ip'] = $i['mask'] = null;
                    return '';
                }
                if ($what === 'description') {
                    $i['description'] = null;
                    return '';
                }
                return $this->bad($what, $line, 1);
            case 'description':
                $text = trim(substr($line, strpos($line, ' ') ?: strlen($line)));
                if ($text === '') {
                    return '% Incomplete command.';
                }
                $i['description'] = mb_substr($text, 0, 80);
                return '';
        }
        return $this->config($t, $line); // interface, do, end, exit, hostname... work here too
    }

    private function isPort(string $name): bool
    {
        return $name !== 'Vlan1';
    }

    /** IOS's console messages when an interface goes up or down. */
    private function changed(string $name, bool $down): string
    {
        $long = $name === 'Vlan1' ? 'Vlan1' : Cli::longName($name);
        if ($down) {
            return "%LINK-5-CHANGED: Interface $long, changed state to administratively down";
        }
        $up = $this->linkUp($name);
        return "%LINK-5-CHANGED: Interface $long, changed state to " . ($up ? 'up' : 'down')
            . ($up ? "\n%LINEPROTO-5-UPDOWN: Line protocol on Interface $long, changed state to up" : '');
    }

    /** Is there a working cable in this port (for Vlan1: in any switch port)? */
    private function linkUp(string $name): bool
    {
        if ($name === 'Vlan1') {
            return (bool) Db::one('SELECT 1 x FROM cables WHERE user_id = ? AND status = "up" AND (a_device_id = ? OR b_device_id = ?) AND (a_port NOT LIKE "Con%" AND b_port NOT LIKE "Con%")',
                [$this->userId, $this->d['id'], $this->d['id']]);
        }
        return (bool) Db::one('SELECT 1 x FROM cables WHERE user_id = ? AND status = "up" AND ((a_device_id = ? AND a_port = ?) OR (b_device_id = ? AND b_port = ?))',
            [$this->userId, $this->d['id'], $name, $this->d['id'], $name]);
    }

    private function save(): string
    {
        $this->cfg['startup'] = $this->cfg['running'];
        $this->saved = true;
        return "Building configuration...\n[OK]";
    }

    /** show ... (and do show ... in config modes). */
    private function show(array $t, string $line, int $at, bool $priv, string $verb = 'show'): string
    {
        if ($verb !== 'show' && $this->word($verb, ['show']) !== 'show') {
            return $this->invalid($line, $at - 1);
        }
        $opts = $priv ? ['running-config', 'startup-config', 'ip', 'version'] : ['ip', 'version'];
        $w = $this->word($t[0] ?? '', $opts);
        if (!isset($t[0])) {
            return '% Incomplete command.';
        }
        switch ($w) {
            case 'running-config':
                return $this->render($this->cfg['running'], 'Current configuration');
            case 'startup-config':
                return $this->cfg['startup'] === null ? 'startup-config is not present' : $this->render($this->cfg['startup'], 'Using startup-config');
            case 'version':
                return 'Cisco IOS Software, NetForge emulation' . "\n{$this->cfg['running']['hostname']} uptime is a few minutes";
            case 'ip':
                $a = $this->word($t[1] ?? '', ['interface']);
                $b = $this->word($t[2] ?? '', ['brief']);
                if ($a === 'interface' && $b === 'brief') {
                    return $this->brief();
                }
                return isset($t[2]) ? $this->invalid($line, $at + 2) : '% Incomplete command.';
        }
        return $this->bad($w, $line, $at);
    }

    private function brief(): string
    {
        $rows = [sprintf('%-22s %-15s %-3s %-6s %-21s %s', 'Interface', 'IP-Address', 'OK?', 'Method', 'Status', 'Protocol')];
        foreach ($this->cfg['running']['ifaces'] as $name => $i) {
            $status = $i['shutdown'] ? 'administratively down' : ($this->linkUp($name) ? 'up' : 'down');
            $proto = $status === 'up' ? 'up' : 'down';
            $rows[] = sprintf('%-22s %-15s %-3s %-6s %-21s %s', $name === 'Vlan1' ? 'Vlan1' : Cli::longName($name),
                $i['ip'] ?? 'unassigned', 'YES', $i['ip'] ? 'manual' : 'unset', $status, $proto);
        }
        return implode("\n", $rows);
    }

    private function render(array $c, string $title): string
    {
        $out = ['hostname ' . $c['hostname'], '!'];
        if (!empty($c['secret'])) {
            $out[] = 'enable secret 5 $1$nf$' . substr($c['secret'], 0, 22);
            $out[] = '!';
        }
        foreach ($c['ifaces'] as $name => $i) {
            $out[] = 'interface ' . ($name === 'Vlan1' ? 'Vlan1' : Cli::longName($name));
            if ($i['description']) {
                $out[] = ' description ' . $i['description'];
            }
            $out[] = $i['ip'] ? " ip address {$i['ip']} {$i['mask']}" : ' no ip address';
            if ($i['shutdown']) {
                $out[] = ' shutdown';
            }
            $out[] = '!';
        }
        $out[] = 'end';
        $body = implode("\n", $out);
        return "Building configuration...\n\n$title : " . strlen($body) . " bytes\n!\n$body";
    }

    private function help(string $before): string
    {
        $list = self::HELP[$this->mode] ?? [];
        if ($before !== '') {
            $list = array_filter($list, fn ($k) => str_starts_with($k, strtolower($before)), ARRAY_FILTER_USE_KEY);
        }
        if (!$list) {
            return '% Unrecognized command';
        }
        return implode("\n", array_map(fn ($k, $v) => sprintf('  %-12s %s', $k, $v), array_keys($list), $list));
    }
}
