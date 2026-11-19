<?php
declare(strict_types=1);

namespace NetForge;

/** Helpers shared by every endpoint in public/api/. */
final class Api
{
    /** Guards an endpoint: JSON only, signed in, CSRF on writes. Returns the user id. */
    public static function begin(string $method = 'GET'): int
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if ($_SERVER['REQUEST_METHOD'] !== $method) {
            self::fail(405, 'Use ' . $method . ' for this endpoint.');
        }
        // current() re-reads the account each time, so a deactivated account or an idle
        // session is signed out here too, not just on the next page load
        $me = Auth::current();
        if ($me === null) {
            self::fail(401, 'Your session ended. Sign in again.');
        }
        $uid = (int) $me['id'];
        if ($method !== 'GET' && !Csrf::check()) {
            self::fail(403, 'This request could not be verified. Reload the page.');
        }
        return $uid;
    }

    /** Decoded JSON body of a POST. */
    public static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function ok(array $data = []): never
    {
        echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function fail(int $status, string $message): never
    {
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => $message]);
        exit;
    }

    /** Runs an endpoint body, turning uncaught errors into a JSON 500. */
    public static function run(callable $fn): never
    {
        try {
            $fn();
        } catch (GameError $e) {
            self::fail(422, $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            self::fail(500, Config::get('app.debug') ? $e->getMessage() : 'The server hit a problem. Try again.');
        }
        self::ok();
    }
}
