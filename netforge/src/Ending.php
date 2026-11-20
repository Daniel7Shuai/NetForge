<?php
declare(strict_types=1);

namespace NetForge;

use NetForge\Learning\Learning;

/**
 * The end of the story: buy out DataTech (every topic mastered, 999 billion credits) and get
 * your dad back. Buying it ends the game; afterwards the student can go back to just before
 * they bought it (the credits come back) and keep playing.
 */
final class Ending
{
    public const ITEM = 'datatech';

    /** Topic numbers still to master before DataTech can be bought (topics with lessons only). */
    public static function topicsLeft(int $userId): array
    {
        $all = array_map('intval', array_column(Db::all(
            'SELECT w.number FROM worlds w WHERE w.archived = 0 AND EXISTS (SELECT 1 FROM levels l WHERE l.world_id = w.id AND l.archived = 0) ORDER BY w.number'
        ), 'number'));
        return array_values(array_diff($all, Learning::masteredNumbers($userId)));
    }

    public static function ended(int $userId): bool
    {
        return Db::one('SELECT game_ended_at FROM users WHERE id = ?', [$userId])['game_ended_at'] !== null;
    }

    /** Called by Rules::buy inside its transaction, before taking the credits. */
    public static function checkCanBuy(int $userId): void
    {
        if (self::ended($userId)) {
            throw new GameError('You already own DataTech.');
        }
        $left = self::topicsLeft($userId);
        if ($left) {
            throw new GameError(sprintf('Master every topic to buy DataTech: pass the %s exam%s first.',
                implode(', ', array_map(fn ($n) => "Topic $n", $left)), count($left) === 1 ? '' : 's'));
        }
    }

    /** Called by Rules::buy after taking the credits: the game is over. */
    public static function finish(int $userId): void
    {
        Db::exec('UPDATE users SET game_ended_at = NOW() WHERE id = ?', [$userId]);
        Rules::log($userId, 'ok', 'You bought DataTech. Go and get your dad.');
    }

    /** Back to just before buying DataTech: the credits come back and the game carries on. */
    public static function rewind(int $userId): void
    {
        Db::transaction(function () use ($userId): void {
            $u = Db::one('SELECT game_ended_at FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($u['game_ended_at'] === null) {
                throw new GameError('You haven’t bought DataTech yet.');
            }
            $price = (int) Db::one('SELECT price FROM shop_items WHERE item_code = ?', [self::ITEM])['price'];
            Db::exec('UPDATE users SET credits = credits + ?, game_ended_at = NULL WHERE id = ?', [$price, $userId]);
            Rules::log($userId, 'info', sprintf('Back to just before you bought DataTech: %s credits returned.', Money::short($price)));
        });
    }
}
