<?php
declare(strict_types=1);

namespace NetForge;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;
    /** Bumped by every write to the tables that decide power and income, and by every rollback. */
    private static int $gearVersion = 0;
    private static array $memo = [];
    private const GEAR_TABLES = '/\b(devices|cables|user_furniture|user_facility|item_types|staff)\b/i';

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::get('db.host'),
                (int) Config::get('db.port', 3306),
                Config::get('db.name')
            );
            self::$pdo = new PDO($dsn, (string) Config::get('db.user'), (string) Config::get('db.password'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // NOW() and CURDATE() must match PHP's clock, whatever zone the server runs in.
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        }
        return self::$pdo;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function exec(string $sql, array $params = []): int
    {
        if (preg_match(self::GEAR_TABLES, $sql)) {
            self::$gearVersion++;
        }
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            self::$gearVersion++; // whatever was read inside may be gone now
            throw $e;
        }
    }

    /**
     * Computes something about a student's gear once per request: the same key returns the
     * cached result until a write to devices, cables, furniture, facility upgrades or item types.
     */
    public static function gearMemo(string $key, callable $fn): mixed
    {
        $hit = self::$memo[$key] ?? null;
        if ($hit !== null && $hit[0] === self::$gearVersion) {
            return $hit[1];
        }
        $value = $fn();
        self::$memo[$key] = [self::$gearVersion, $value];
        return $value;
    }

    /** Reads a value from game_config, cast to int. */
    public static function configInt(string $name, int $default): int
    {
        $row = self::one('SELECT value FROM game_config WHERE name = ?', [$name]);
        return $row === null ? $default : (int) $row['value'];
    }
}
