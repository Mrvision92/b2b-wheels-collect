<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Petites informations de fonctionnement dans la base (bwc_meta), lisibles
 * par le site comme par l'administration même installés séparément.
 */
final class Meta
{
    public static function set(string $name, string $value): void
    {
        try {
            Database::pdo()->prepare('INSERT INTO bwc_meta (name, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = ?, updated_at = ?')
                ->execute([$name, $value, BookingStore::datetime(time()), $value, BookingStore::datetime(time())]);
        } catch (\PDOException $error) {
            Log::warn('meta.write_failed', ['name' => $name, 'error' => $error->getMessage()]);
        }
    }

    public static function get(string $name): ?string
    {
        try {
            $statement = Database::pdo()->prepare('SELECT value FROM bwc_meta WHERE name = ?');
            $statement->execute([$name]);
            $value = $statement->fetchColumn();
            return $value === false ? null : (string) $value;
        } catch (\PDOException) {
            return null;
        }
    }

    /** Empreinte des réglages de créneaux : site et administration doivent avoir les mêmes. */
    public static function settingsFingerprint(): string
    {
        return substr(hash('sha256', json_encode(Config::get()->booking())), 0, 16);
    }
}
