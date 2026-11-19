<?php
declare(strict_types=1);

namespace NetForge\Content;

use NetForge\Auth;
use NetForge\Csrf;

/** Small helpers shared by the lecturer pages in public/lecturer/. */
final class Staff
{
    /** Guards a lecturer page: staff only, and every POST must carry a valid CSRF token. */
    public static function begin(): array
    {
        $me = Auth::requireRole(Auth::STAFF, '../');
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
            self::flash('error', 'The form expired. Try again.');
            self::back();
        }
        return $me;
    }

    public static function flash(string $kind, string $message): void
    {
        $_SESSION['flash'][] = [$kind, $message];
    }

    /** @return list<array{0:string,1:string}> */
    public static function takeFlash(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }

    public static function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }

    /** Back to the page that posted (same URL), so a refresh doesn't resubmit. */
    public static function back(): never
    {
        self::redirect($_SERVER['REQUEST_URI'] ?? 'index.php');
    }

    public static function int(mixed $v, int $min, int $max, int $default): int
    {
        $s = trim((string) $v);
        return ctype_digit($s) ? max($min, min($max, (int) $s)) : $default;
    }

    /** "W0 Networking basics" style labels for every world, keyed by id. */
    public static function worldOptions(): array
    {
        $out = [];
        foreach (\NetForge\Db::all('SELECT id, number, title FROM worlds ORDER BY number') as $w) {
            $out[(int) $w['id']] = 'W' . $w['number'] . ' ' . $w['title'];
        }
        return $out;
    }
}
