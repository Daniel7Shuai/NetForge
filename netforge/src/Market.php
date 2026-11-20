<?php
declare(strict_types=1);

namespace NetForge;

/**
 * The GPU market. Each seller's price drifts up and down and their stock refills every
 * 20 minutes, so it pays to shop around. Stock is counted per student. The broker's
 * temporary discount (a buff) comes off GPU prices too.
 */
final class Market
{
    public const WINDOW_SECONDS = 1200; // prices and stock change every 20 minutes

    public static function window(?int $now = null): int
    {
        return intdiv($now ?? time(), self::WINDOW_SECONDS);
    }

    /** A stable pseudo-random number in [0, 1) for this seller and window. */
    private static function roll(string $seed): float
    {
        return hexdec(substr(hash('sha256', $seed), 0, 8)) / 4294967296;
    }

    /** The active GPU discount (percent) from the broker, or 0. */
    public static function discount(int $userId): int
    {
        $b = Db::one('SELECT pct FROM user_buffs WHERE user_id = ? AND kind = "gpu_discount" AND expires_at > NOW()', [$userId]);
        return (int) ($b['pct'] ?? 0);
    }

    /**
     * What a seller's shop row costs right now, and how many are left.
     * @return array{price:int, base:int, change:int, stock:int, restockIn:int, discount:int}
     */
    public static function quote(array $row, int $userId, ?int $now = null): array
    {
        $now ??= time();
        $w = self::window($now);
        $seed = $row['seller'] . '|' . $row['item_code'] . '|' . $w;
        $factor = 0.8 + 0.4 * self::roll('price' . $seed);            // 80% to 120% of the list price
        $r = self::roll('stock' . $seed);
        $stockMax = $r < 0.15 ? 0 : 1 + (int) floor(self::roll('qty' . $seed) * 4); // sometimes sold out, else 1-4
        $bought = (int) (Db::one('SELECT qty FROM gpu_purchases WHERE user_id = ? AND shop_id = ? AND window = ?', [$userId, (int) $row['id'], $w])['qty'] ?? 0);
        $discount = self::discount($userId);
        $base = (int) $row['price'];
        $price = (int) round($base * $factor * (100 - $discount) / 100);
        return [
            'price' => $price, 'base' => $base, 'change' => (int) round(($factor - 1) * 100),
            'stock' => max(0, $stockMax - $bought), 'restockIn' => ($w + 1) * self::WINDOW_SECONDS - $now, 'discount' => $discount,
        ];
    }

    /** Records one purchase in this window. Call inside the buying transaction. */
    public static function recordPurchase(int $userId, int $shopId): void
    {
        Db::exec('INSERT INTO gpu_purchases (user_id, shop_id, window, qty) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE qty = qty + 1',
            [$userId, $shopId, self::window()]);
    }
}
