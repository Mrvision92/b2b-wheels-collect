<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Création et mise à jour des tables. Une installation neuve reçoit
 * database/schema.sql ; une installation existante reçoit les fichiers de
 * database/migrations/ qu'elle n'a pas encore, dans l'ordre.
 */
final class Schema
{
    private static function dir(): string
    {
        return BWC_APP_DIR . '/database';
    }

    /** @return list<string> instructions SQL d'un fichier, sans les commentaires */
    public static function statements(string $file): array
    {
        $sql = (string) file_get_contents($file);
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', preg_split('/;\s*(?:\n|$)/', $sql) ?: [])));
    }

    public static function isInstalled(\PDO $pdo): bool
    {
        return $pdo->query("SHOW TABLES LIKE 'bwc_bookings'")->fetchColumn() !== false;
    }

    public static function install(\PDO $pdo): void
    {
        foreach (self::statements(self::dir() . '/schema.sql') as $statement) {
            $pdo->exec($statement);
        }
    }

    /** @return list<string> noms des migrations pas encore appliquées */
    public static function pending(\PDO $pdo): array
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS bwc_migrations (name VARCHAR(100) NOT NULL, applied_at DATETIME NOT NULL, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $applied = $pdo->query('SELECT name FROM bwc_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        $pending = [];
        foreach (glob(self::dir() . '/migrations/*.sql') ?: [] as $file) {
            $name = basename($file, '.sql');
            if (!in_array($name, $applied, true)) {
                $pending[] = $name;
            }
        }
        sort($pending);
        return $pending;
    }

    public static function apply(\PDO $pdo, string $name): void
    {
        foreach (self::statements(self::dir() . "/migrations/$name.sql") as $statement) {
            $pdo->exec($statement);
        }
        $pdo->prepare('INSERT INTO bwc_migrations (name, applied_at) VALUES (?, UTC_TIMESTAMP())')->execute([$name]);
    }

    /** Efface toutes les tables du site (tests et démonstration uniquement). */
    public static function dropAll(\PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query("SHOW TABLES LIKE 'bwc\\_%'")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec("DROP TABLE `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
