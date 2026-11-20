<?php
declare(strict_types=1);

namespace NetForge;

/** How credits are written in messages. Mirrors fmt() in public/assets/js/money.js. */
final class Money
{
    /** 950, 4k, 12.5k, 3.14M, 1.4B: k for thousands, M for millions, B for billions. */
    public static function short(int|float $n): string
    {
        $v = (float) $n;
        $sign = $v < 0 ? '-' : '';
        $v = abs($v);
        foreach ([[1e12, 'T'], [1e9, 'B'], [1e6, 'M'], [1e3, 'k']] as [$unit, $suffix]) {
            if ($v >= $unit) {
                $x = $v / $unit;
                $digits = $x >= 100 ? 0 : ($x >= 10 ? 1 : 2); // three significant figures
                $text = number_format(floor($x * 10 ** $digits) / 10 ** $digits, $digits, '.', '');
                if (str_contains($text, '.')) {
                    $text = rtrim(rtrim($text, '0'), '.'); // 4.00 -> 4, 12.50 -> 12.5, but 120 stays 120
                }
                return $sign . $text . $suffix;
            }
        }
        return $sign . (string) (int) floor($v);
    }
}
