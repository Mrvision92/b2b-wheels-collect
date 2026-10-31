<?php

declare(strict_types=1);

namespace Bwc;

/** Connexion PDO à MySQL / MariaDB, en UTC et en utf8mb4. */
final class Database
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            $db = Config::get()->db();
            if ($db['name'] === '' || $db['user'] === '') {
                throw new \RuntimeException('Base de données non configurée (config.php, section db).');
            }
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
            $pdo = new \PDO($dsn, $db['user'], $db['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'");
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /**
     * Exécute $work dans une transaction ; annule tout en cas d'erreur.
     *
     * @template T
     * @param callable(\PDO): T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public static function isDuplicateKey(\PDOException $error): bool
    {
        return ($error->errorInfo[1] ?? null) === 1062;
    }

    /** Interblocage (1213) ou attente de verrou trop longue (1205) : on peut réessayer. */
    public static function isLockConflict(\PDOException $error): bool
    {
        return in_array($error->errorInfo[1] ?? null, [1205, 1213], true);
    }
}
