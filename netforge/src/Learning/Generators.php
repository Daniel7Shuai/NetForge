<?php
declare(strict_types=1);

namespace NetForge\Learning;

/**
 * Makes fresh Binary Blitz and Subnet Calculator questions on demand (FR-3.1).
 * Each returns what the student sees plus the answer key, which stays on the server.
 */
final class Generators
{
    /** @return array{prompt:string, key:string, seconds:?int, inputHint:string} */
    public static function blitz(array $p): array
    {
        $bits = (int) ($p['bits'] ?? 8);
        $n = random_int(1, (1 << $bits) - 1);
        $bin = str_pad(decbin($n), $bits, '0', STR_PAD_LEFT);
        if (($p['mode'] ?? 'bin2dec') === 'dec2bin') {
            return ['prompt' => "Convert decimal $n to $bits-bit binary.", 'key' => $bin, 'seconds' => (int) ($p['seconds'] ?? 20), 'inputHint' => str_repeat('0', $bits)];
        }
        return ['prompt' => "Convert binary $bin to decimal.", 'key' => (string) $n, 'seconds' => (int) ($p['seconds'] ?? 20), 'inputHint' => 'Number'];
    }

    /** @return array{prompt:string, key:string, seconds:?int, inputHint:string} */
    public static function subnet(array $p): array
    {
        $mode = $p['mode'] ?? 'hosts';
        $prefix = random_int((int) ($p['minPrefix'] ?? 24), (int) ($p['maxPrefix'] ?? 30));
        $ip = self::randomPrivateIp();
        $key = self::subnetAnswer($mode, $ip, $prefix);
        $prompt = match ($mode) {
            'hosts'     => "How many usable hosts does a /$prefix network have?",
            'mask'      => "Write the subnet mask for /$prefix in dotted decimal.",
            'network'   => "What is the network address of $ip/$prefix?",
            'broadcast' => "What is the broadcast address of $ip/$prefix?",
            default     => throw new \InvalidArgumentException("Unknown subnet mode $mode"),
        };
        $hint = in_array($mode, ['hosts'], true) ? 'Number' : 'a.b.c.d';
        return ['prompt' => $prompt, 'key' => $key, 'seconds' => null, 'inputHint' => $hint];
    }

    public static function subnetAnswer(string $mode, string $ip, int $prefix): string
    {
        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $addr = ip2long($ip);
        return match ($mode) {
            'hosts'     => (string) max(0, (2 ** (32 - $prefix)) - 2),
            'mask'      => long2ip($mask),
            'network'   => long2ip($addr & $mask),
            'broadcast' => long2ip(($addr & $mask) | (~$mask & 0xFFFFFFFF)),
            default     => throw new \InvalidArgumentException("Unknown subnet mode $mode"),
        };
    }

    private static function randomPrivateIp(): string
    {
        return match (random_int(0, 2)) {
            0 => sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254)),
            1 => sprintf('172.%d.%d.%d', random_int(16, 31), random_int(0, 255), random_int(1, 254)),
            default => sprintf('192.168.%d.%d', random_int(0, 255), random_int(1, 254)),
        };
    }
}
