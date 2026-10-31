<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Accès à la table bwc_bookings. Une réservation circule dans le code sous
 * forme de tableau aux noms de colonnes, complété de start_ts / end_ts
 * (horodatages Unix) et synced_ts.
 */
final class BookingStore
{
    private const COLUMNS = [
        'reference', 'request_hash', 'status', 'slot_date', 'slot_key', 'start_time', 'end_time', 'starts_at', 'ends_at',
        'slot_mode', 'garage_name', 'contact_name', 'phone', 'email', 'street', 'postal_code', 'city', 'rim_count',
        'garage_reference', 'comment', 'photo_count', 'google_event_id', 'google_status', 'google_link', 'google_tries',
        'google_synced_at', 'garage_mail', 'garage_mail_tries', 'vbm_mail', 'vbm_mail_tries', 'created_at',
    ];

    public function __construct(private \PDO $pdo)
    {
    }

    public static function datetime(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    public static function datetimeMs(float $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', (int) floor($timestamp)) . sprintf('.%03d', (int) (fmod($timestamp, 1) * 1000));
    }

    private static function timestamp(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', str_contains($value, '.') ? $value : "$value.000", new \DateTimeZone('UTC'));
        return $at === false ? null : (float) $at->format('U.u');
    }

    /** @param array<string, mixed>|false $row @return array<string, mixed>|null */
    private static function hydrate(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        foreach (['id', 'rim_count', 'photo_count', 'google_tries', 'garage_mail_tries', 'vbm_mail_tries'] as $int) {
            $row[$int] = (int) $row[$int];
        }
        $row['start_ts'] = (int) self::timestamp($row['starts_at']);
        $row['end_ts'] = (int) self::timestamp($row['ends_at']);
        $row['synced_ts'] = self::timestamp($row['google_synced_at']);
        return $row;
    }

    /** @return array<string, mixed>|null */
    public function findByRequestHash(string $hash): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bwc_bookings WHERE request_hash = ?');
        $statement->execute([$hash]);
        return self::hydrate($statement->fetch());
    }

    /** @return array<string, mixed>|null */
    public function findByReference(string $reference): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bwc_bookings WHERE reference = ?');
        $statement->execute([$reference]);
        return self::hydrate($statement->fetch());
    }

    /**
     * Réservations confirmées qui chevauchent la période [start, end[.
     *
     * @return list<array<string, mixed>>
     */
    public function activeBetween(int $start, int $end): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM bwc_bookings WHERE status = 'confirmed' AND starts_at < ? AND ends_at > ? ORDER BY starts_at, id",
        );
        $statement->execute([self::datetime($end), self::datetime($start)]);
        return array_map(static fn (array $row): array => self::hydrate($row), $statement->fetchAll());
    }

    /** Crée la ligne du jour à verrouiller, hors transaction (sinon deux créations simultanées se bloquent). */
    public function ensureDay(string $date): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO bwc_booking_days (day) VALUES (?)')->execute([$date]);
    }

    /**
     * Verrouille la journée jusqu'à la fin de la transaction en cours : les
     * réservations d'un même jour sont enregistrées l'une après l'autre.
     */
    public function lockDay(string $date): void
    {
        $this->pdo->prepare('SELECT day FROM bwc_booking_days WHERE day = ? FOR UPDATE')->execute([$date]);
    }

    /** @param array<string, mixed> $booking */
    public function insert(array $booking): void
    {
        $fields = array_intersect_key($booking, array_flip(self::COLUMNS));
        $columns = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $this->pdo->prepare("INSERT INTO bwc_bookings ($columns) VALUES ($placeholders)")->execute(array_values($fields));
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::COLUMNS));
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $column): string => "$column = ?", array_keys($fields)));
        $this->pdo->prepare("UPDATE bwc_bookings SET $set WHERE id = ?")->execute([...array_values($fields), $id]);
    }

    /**
     * Réservations dont un email ou la copie Google Agenda reste à faire,
     * en laissant les plus récentes à la requête qui les a créées.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingFollowUps(int $olderThanSeconds, int $limit, bool $withCalendar): array
    {
        $calendar = $withCalendar ? " OR google_status = 'pending'" : '';
        $statement = $this->pdo->prepare(
            "SELECT * FROM bwc_bookings
             WHERE status = 'confirmed'
               AND (garage_mail = 'pending' OR vbm_mail = 'pending'$calendar)
               AND created_at < ?
             ORDER BY created_at, id
             LIMIT " . max(1, $limit),
        );
        $statement->execute([self::datetime(time() - $olderThanSeconds)]);
        return array_map(static fn (array $row): array => self::hydrate($row), $statement->fetchAll());
    }
}
