<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Accès à la table bwc_bookings. Une réservation circule dans le code sous
 * forme de tableau aux noms de colonnes, complété de start_ts / end_ts
 * (horodatages Unix).
 */
final class BookingStore
{
    /** Statuts qui occupent une place sur un créneau. */
    public const ACTIVE = ['pending', 'confirmed', 'completed'];
    /** Statuts encore à enlever. */
    public const OPEN = ['pending', 'confirmed'];

    private const COLUMNS = [
        'reference', 'request_hash', 'garage_id', 'source', 'status', 'slot_date', 'slot_key', 'start_time', 'end_time',
        'starts_at', 'ends_at', 'slot_mode', 'garage_name', 'contact_name', 'phone', 'email', 'street', 'postal_code', 'city',
        'rim_count', 'garage_reference', 'comment', 'internal_note', 'photo_count', 'google_event_id', 'google_status', 'google_link',
        'google_tries', 'google_synced_at', 'created_by', 'completed_at', 'completed_by', 'cancelled_at', 'cancelled_by',
        'cancel_reason', 'created_at',
    ];

    public function __construct(private \PDO $pdo)
    {
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public static function datetime(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    public static function datetimeMs(float $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', (int) floor($timestamp)) . sprintf('.%03d', (int) (fmod($timestamp, 1) * 1000));
    }

    /** @param array<mixed> $values @return string « ?, ?, ? » */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /** @param array<string, mixed>|false $row @return array<string, mixed>|null */
    private static function hydrate(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        foreach (['id', 'rim_count', 'photo_count', 'google_tries'] as $int) {
            $row[$int] = (int) $row[$int];
        }
        foreach (['garage_id', 'created_by', 'completed_by', 'cancelled_by'] as $nullable) {
            $row[$nullable] = $row[$nullable] === null ? null : (int) $row[$nullable];
        }
        $row['start_ts'] = (int) strtotime($row['starts_at'] . ' UTC');
        $row['end_ts'] = (int) strtotime($row['ends_at'] . ' UTC');
        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map(static fn (array $row): array => self::hydrate($row), $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    private function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return self::hydrate($statement->fetch());
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        return $this->fetchOne('SELECT * FROM bwc_bookings WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByRequestHash(string $hash): ?array
    {
        return $this->fetchOne('SELECT * FROM bwc_bookings WHERE request_hash = ?', [$hash]);
    }

    /** @return array<string, mixed>|null */
    public function findByReference(string $reference): ?array
    {
        return $this->fetchOne('SELECT * FROM bwc_bookings WHERE reference = ?', [$reference]);
    }

    /**
     * Réservations qui occupent une place et chevauchent la période [start, end[.
     *
     * @return list<array<string, mixed>>
     */
    public function activeBetween(int $start, int $end, ?int $excludeId = null): array
    {
        return $this->fetchAll(
            'SELECT * FROM bwc_bookings WHERE status IN (' . self::placeholders(self::ACTIVE) . ') AND starts_at < ? AND ends_at > ? AND id <> ? ORDER BY starts_at, id',
            [...self::ACTIVE, self::datetime($end), self::datetime($start), $excludeId ?? 0],
        );
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

    /** @param array<string, mixed> $booking @return int identifiant créé */
    public function insert(array $booking): int
    {
        $fields = array_intersect_key($booking, array_flip(self::COLUMNS));
        $columns = implode(', ', array_keys($fields));
        $this->pdo->prepare("INSERT INTO bwc_bookings ($columns) VALUES (" . self::placeholders($fields) . ')')->execute(array_values($fields));
        return (int) $this->pdo->lastInsertId();
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

    /** Ajoute une ligne à l'historique de la réservation. */
    public function log(int $bookingId, string $action, ?string $from, ?string $to, string $details = '', ?int $adminId = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO bwc_booking_history (booking_id, action, from_status, to_status, details, admin_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([$bookingId, $action, $from, $to, mb_substr($details, 0, 1000), $adminId, self::datetime(time())]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> historique, le plus récent d'abord, avec le nom de l'administrateur */
    public function history(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT h.*, a.name AS admin_name FROM bwc_booking_history h LEFT JOIN bwc_admins a ON a.id = h.admin_id WHERE h.booking_id = ? ORDER BY h.id DESC',
        );
        $statement->execute([$bookingId]);
        return $statement->fetchAll();
    }

    /**
     * Liste filtrée et paginée de la page « Enlèvements ».
     *
     * @param array{tab?: string, q?: string, sort?: string, page?: int, per_page?: int} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, string $today): array
    {
        $where = [];
        $params = [];
        $tab = $filters['tab'] ?? 'tous';
        switch ($tab) {
            case 'aujourdhui':
                $where[] = "slot_date = ? AND status <> 'cancelled'";
                $params[] = $today;
                break;
            case 'a-enlever':
                $where[] = 'status IN (' . self::placeholders(self::OPEN) . ')';
                array_push($params, ...self::OPEN);
                break;
            case 'enleves':
                $where[] = "status = 'completed'";
                break;
            case 'annules':
                $where[] = "status = 'cancelled'";
                break;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $digits = (string) preg_replace('/\D/', '', $q);
            $conditions = ['garage_name LIKE ?', 'reference LIKE ?', 'city LIKE ?', 'contact_name LIKE ?', 'email LIKE ?', 'garage_reference LIKE ?'];
            array_push($params, $like, $like, $like, $like, $like, $like);
            if (strlen($digits) >= 4) {
                $conditions[] = 'phone LIKE ?';
                $params[] = '%' . ltrim($digits, '0') . '%';
            }
            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }
        $sql = ' FROM bwc_bookings' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $defaultSort = match ($tab) {
            'a-enlever', 'aujourdhui' => 'date',
            'enleves' => 'completed',
            default => '-date',
        };
        $order = match ($filters['sort'] ?? $defaultSort) {
            'date' => 'starts_at ASC, id ASC',
            'garage' => 'garage_name ASC, starts_at DESC',
            '-garage' => 'garage_name DESC, starts_at DESC',
            'completed' => 'completed_at DESC, id DESC',
            default => 'starts_at DESC, id DESC',
        };

        $count = $this->pdo->prepare('SELECT COUNT(*)' . $sql);
        $count->execute($params);
        $perPage = max(1, (int) ($filters['per_page'] ?? 25));
        $offset = (max(1, (int) ($filters['page'] ?? 1)) - 1) * $perPage;
        return [
            'rows' => $this->fetchAll("SELECT *$sql ORDER BY $order LIMIT $perPage OFFSET $offset", $params),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /**
     * Réservations d'une période (dates de Bruxelles incluses), pour le planning.
     *
     * @return list<array<string, mixed>>
     */
    public function between(string $fromDate, string $toDate, bool $withCancelled = false): array
    {
        return $this->fetchAll(
            'SELECT * FROM bwc_bookings WHERE slot_date BETWEEN ? AND ?' . ($withCancelled ? '' : " AND status <> 'cancelled'") . ' ORDER BY starts_at, id',
            [$fromDate, $toDate],
        );
    }

    /** @return list<array<string, mixed>> prochains enlèvements encore à faire */
    public function upcoming(int $now, int $limit): array
    {
        return $this->fetchAll(
            'SELECT * FROM bwc_bookings WHERE status IN (' . self::placeholders(self::OPEN) . ') AND ends_at > ? ORDER BY starts_at, id LIMIT ' . max(1, $limit),
            [...self::OPEN, self::datetime($now)],
        );
    }

    /** @return list<array<string, mixed>> dernières réservations reçues sur le site */
    public function latestReceived(int $limit): array
    {
        return $this->fetchAll("SELECT * FROM bwc_bookings WHERE source = 'public' ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit));
    }

    /** @return list<array<string, mixed>> réservations d'un garage, les plus récentes d'abord */
    public function forGarage(int $garageId): array
    {
        return $this->fetchAll('SELECT * FROM bwc_bookings WHERE garage_id = ? ORDER BY starts_at DESC, id DESC', [$garageId]);
    }

    /** @return list<array<string, mixed>> copies Google Agenda à refaire */
    public function pendingCalendarSync(int $olderThanSeconds, int $limit): array
    {
        return $this->fetchAll(
            "SELECT * FROM bwc_bookings WHERE google_status = 'pending' AND updated_at < ? ORDER BY updated_at, id LIMIT " . max(1, $limit),
            [self::datetime(time() - $olderThanSeconds)],
        );
    }
}
