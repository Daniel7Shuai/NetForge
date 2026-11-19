<?php
declare(strict_types=1);

namespace NetForge\Learning;

/**
 * Elo rating for students and questions (proposal section 2.3, FR-3.3).
 *
 *   E     = 1 / (1 + 10^((Rq - Rs) / 400))   expected chance the student answers correctly
 *   Rs'   = Rs + K(Rs) * (S - E)             S = 1 if correct, 0 if not
 *   Rq'   = Rq - Kq * (S - E)                the question moves the opposite way
 *
 * K shrinks as the student's rating grows, so new players move faster.
 * Pure functions only: no database, so it is easy to unit test (tests/EloTest.php).
 */
final class Elo
{
    public const START_RATING = 800;
    public const QUESTION_K = 16;

    /** Rating at which each tier starts. */
    public const TIERS = [
        'Bronze'   => 0,
        'Silver'   => 1000,
        'Gold'     => 1200,
        'Platinum' => 1400,
    ];

    /** Credits and XP per rating point gained. Repeating easy questions gains ~0, so earns ~0. */
    // Questions pay XP only. Credits come from the network's passive income (Progress\Income);
    // XP raises the level that decides which equipment those credits can buy.
    public const CREDITS_PER_POINT = 0;
    public const XP_PER_POINT = 5;

    public static function expected(float $student, float $question): float
    {
        return 1 / (1 + 10 ** (($question - $student) / 400));
    }

    public static function kFactor(float $student): int
    {
        return match (true) {
            $student < 1000 => 40,
            $student < 1200 => 32,
            $student < 1400 => 24,
            default         => 16,
        };
    }

    /**
     * @return array{student:int, question:int, expected:float}
     */
    public static function update(int $student, int $question, bool $correct): array
    {
        $e = self::expected($student, $question);
        $s = $correct ? 1.0 : 0.0;
        return [
            'student'  => (int) round($student + self::kFactor($student) * ($s - $e)),
            'question' => (int) round($question - self::QUESTION_K * ($s - $e)),
            'expected' => $e,
        ];
    }

    public static function tier(int $rating): string
    {
        $name = 'Bronze';
        foreach (self::TIERS as $tier => $from) {
            if ($rating >= $from) {
                $name = $tier;
            }
        }
        return $name;
    }

    /** @return array{credits:int, xp:int} rewards for a rating change (nothing for a loss). */
    public static function rewards(int $before, int $after): array
    {
        $gain = max(0, $after - $before);
        return ['credits' => $gain * self::CREDITS_PER_POINT, 'xp' => $gain * self::XP_PER_POINT];
    }
}
