<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Annuaire des garages clients. Chaque réservation est rattachée à un garage :
 * celui choisi par VBM, sinon celui qui a la même adresse email, sinon le même
 * téléphone et le même nom. Deux garages aux noms proches ne sont jamais
 * fusionnés automatiquement : VBM peut le faire depuis la fiche du garage.
 */
final class GarageStore
{
    private const COLUMNS = ['name', 'contact_name', 'phone', 'email', 'street', 'postal_code', 'city', 'notes'];

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bwc_garages WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row === false ? null : ['id' => (int) $row['id']] + $row;
    }

    /**
     * Garage correspondant à une réservation, ou null s'il faut en créer un.
     *
     * @param array<string, mixed> $booking colonnes garage_name, phone, email
     */
    public function match(array $booking): ?int
    {
        $email = strtolower(trim((string) $booking['email']));
        if ($email !== '') {
            $statement = $this->pdo->prepare('SELECT id FROM bwc_garages WHERE email = ? ORDER BY updated_at DESC, id DESC LIMIT 1');
            $statement->execute([$email]);
            $id = $statement->fetchColumn();
            return $id === false ? null : (int) $id;
        }
        if ((string) $booking['phone'] !== '') {
            $statement = $this->pdo->prepare('SELECT id FROM bwc_garages WHERE phone = ? AND LOWER(name) = LOWER(?) ORDER BY id DESC LIMIT 1');
            $statement->execute([$booking['phone'], $booking['garage_name']]);
            $id = $statement->fetchColumn();
            return $id === false ? null : (int) $id;
        }
        return null;
    }

    /** @param array<string, mixed> $booking @return int identifiant du garage créé */
    public function createFromBooking(array $booking): int
    {
        $now = BookingStore::datetime(time());
        $this->pdo->prepare(
            'INSERT INTO bwc_garages (name, contact_name, phone, email, street, postal_code, city, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $booking['garage_name'], $booking['contact_name'], $booking['phone'], strtolower(trim((string) $booking['email'])),
            $booking['street'], $booking['postal_code'], $booking['city'], $now, $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::COLUMNS));
        if (isset($fields['email'])) {
            $fields['email'] = strtolower(trim((string) $fields['email']));
        }
        if ($fields === []) {
            return;
        }
        $fields['updated_at'] = BookingStore::datetime(time());
        $set = implode(', ', array_map(static fn (string $column): string => "$column = ?", array_keys($fields)));
        $this->pdo->prepare("UPDATE bwc_garages SET $set WHERE id = ?")->execute([...array_values($fields), $id]);
    }

    /** Coordonnées d'une réservation reprises sur la fiche du garage. */
    public function updateFromBooking(int $id, array $booking): void
    {
        $this->update($id, [
            'name' => $booking['garage_name'], 'contact_name' => $booking['contact_name'], 'phone' => $booking['phone'],
            'email' => $booking['email'], 'street' => $booking['street'], 'postal_code' => $booking['postal_code'], 'city' => $booking['city'],
        ]);
    }

    /**
     * Rattache toutes les réservations de $sourceId à $targetId puis supprime
     * le doublon. L'historique des réservations est conservé.
     */
    public function merge(int $sourceId, int $targetId): void
    {
        if ($sourceId === $targetId) {
            return;
        }
        Database::transaction(function () use ($sourceId, $targetId): void {
            $source = $this->find($sourceId);
            $target = $this->find($targetId);
            if ($source === null || $target === null) {
                throw new \InvalidArgumentException('Garage introuvable.');
            }
            $this->pdo->prepare('UPDATE bwc_bookings SET garage_id = ? WHERE garage_id = ?')->execute([$targetId, $sourceId]);
            if ($source['notes'] !== '') {
                $notes = trim($target['notes'] . "\n" . $source['notes']);
                $this->pdo->prepare('UPDATE bwc_garages SET notes = ? WHERE id = ?')->execute([mb_substr($notes, 0, 2000), $targetId]);
            }
            $this->pdo->prepare('DELETE FROM bwc_garages WHERE id = ?')->execute([$sourceId]);
        });
    }

    /** Agrégats d'activité, une seule requête pour la liste. */
    private const ACTIVITY = "
        COUNT(b.id) AS bookings_total,
        SUM(b.status = 'completed') AS completed_total,
        COALESCE(SUM(CASE WHEN b.status = 'completed' THEN b.rim_count END), 0) AS rims_total,
        MAX(CASE WHEN b.status = 'completed' THEN COALESCE(b.completed_at, b.starts_at) END) AS last_completed_at,
        MIN(CASE WHEN b.status IN ('pending', 'confirmed') AND b.ends_at > UTC_TIMESTAMP() THEN b.starts_at END) AS next_starts_at";

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(string $q, int $page, int $perPage = 25): array
    {
        $where = '';
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where = 'WHERE (g.name LIKE ? OR g.city LIKE ? OR g.contact_name LIKE ? OR g.email LIKE ? OR g.phone LIKE ?)';
            $digits = ltrim((string) preg_replace('/\D/', '', $q), '0');
            array_push($params, $like, $like, $like, $like, strlen($digits) >= 4 ? "%$digits%" : $like);
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM bwc_garages g $where");
        $count->execute($params);
        $offset = (max(1, $page) - 1) * $perPage;
        $statement = $this->pdo->prepare(
            'SELECT g.*, ' . self::ACTIVITY . " FROM bwc_garages g LEFT JOIN bwc_bookings b ON b.garage_id = g.id $where
             GROUP BY g.id ORDER BY (MAX(b.created_at) IS NULL), MAX(b.created_at) DESC, g.name LIMIT $perPage OFFSET $offset",
        );
        $statement->execute($params);
        return ['rows' => $statement->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array<string, mixed> activité d'un garage */
    public function activity(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ACTIVITY . ' FROM bwc_bookings b WHERE b.garage_id = ?');
        $statement->execute([$id]);
        return $statement->fetch() ?: [];
    }

    /**
     * Autres contacts et adresses utilisés dans les réservations du garage.
     *
     * @return array{contacts: list<array<string, string>>, addresses: list<array<string, string>>}
     */
    public function variants(array $garage): array
    {
        $statement = $this->pdo->prepare(
            'SELECT contact_name, phone, email, MAX(created_at) AS used_at FROM bwc_bookings WHERE garage_id = ? GROUP BY contact_name, phone, email ORDER BY used_at DESC',
        );
        $statement->execute([$garage['id']]);
        $contacts = array_values(array_filter($statement->fetchAll(), static fn (array $c): bool => !(
            $c['contact_name'] === $garage['contact_name'] && $c['phone'] === $garage['phone'] && strtolower($c['email']) === $garage['email']
        )));
        $statement = $this->pdo->prepare(
            'SELECT street, postal_code, city, MAX(created_at) AS used_at FROM bwc_bookings WHERE garage_id = ? GROUP BY street, postal_code, city ORDER BY used_at DESC',
        );
        $statement->execute([$garage['id']]);
        $addresses = array_values(array_filter($statement->fetchAll(), static fn (array $a): bool => !(
            $a['street'] === $garage['street'] && $a['postal_code'] === $garage['postal_code'] && $a['city'] === $garage['city']
        )));
        return ['contacts' => $contacts, 'addresses' => $addresses];
    }

    /** @return list<array<string, mixed>> garages pour la recherche du formulaire « Nouvel enlèvement » */
    public function suggest(string $q, int $limit = 8): array
    {
        $like = '%' . addcslashes(trim($q), '%_\\') . '%';
        $statement = $this->pdo->prepare(
            'SELECT id, name, contact_name, phone, email, street, postal_code, city FROM bwc_garages
             WHERE name LIKE ? OR city LIKE ? OR contact_name LIKE ? OR email LIKE ? ORDER BY name LIMIT ' . max(1, $limit),
        );
        $statement->execute([$like, $like, $like, $like]);
        return $statement->fetchAll();
    }

    /**
     * Droit à l'effacement (RGPD) : efface les données personnelles du garage
     * et de toutes ses réservations, ainsi que leurs photos. Les chiffres
     * (dates, nombre de jantes) restent pour les statistiques.
     *
     * @return array{ok: bool, message: string, bookings?: int}
     */
    public function erase(int $id, ?PhotoStore $photos, int $adminId): array
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM bwc_bookings WHERE garage_id = ? AND status IN ('pending', 'confirmed')");
        $statement->execute([$id]);
        if ((int) $statement->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'Ce garage a encore des enlèvements à faire : annulez-les ou marquez-les enlevés avant d’effacer ses données.'];
        }
        $ids = Database::transaction(function () use ($id, $adminId): array {
            $statement = $this->pdo->prepare('SELECT id FROM bwc_bookings WHERE garage_id = ?');
            $statement->execute([$id]);
            $ids = array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
            $this->pdo->prepare(
                "UPDATE bwc_bookings SET garage_name = 'Client effacé', contact_name = '—', phone = '', email = '', street = '—',
                 comment = '', garage_reference = '', internal_note = '', cancel_reason = '' WHERE garage_id = ?",
            )->execute([$id]);
            $this->pdo->prepare('UPDATE bwc_notifications n JOIN bwc_bookings b ON b.id = n.booking_id SET n.recipients = \'\' WHERE b.garage_id = ?')->execute([$id]);
            $now = BookingStore::datetime(time());
            foreach ($ids as $bookingId) {
                $this->pdo->prepare("INSERT INTO bwc_booking_history (booking_id, action, details, admin_id, created_at) VALUES (?, 'erased', 'Données personnelles effacées à la demande du garage', ?, ?)")
                    ->execute([$bookingId, $adminId, $now]);
            }
            $this->pdo->prepare("UPDATE bwc_garages SET name = 'Client effacé', contact_name = '', phone = '', email = '', street = '', postal_code = '', notes = '', updated_at = ? WHERE id = ?")
                ->execute([$now, $id]);
            return $ids;
        });
        foreach ($ids as $bookingId) {
            $photos?->deleteForBooking($bookingId);
        }
        Log::info('garage.erased', ['garage' => $id, 'bookings' => count($ids), 'admin' => $adminId]);
        return ['ok' => true, 'message' => 'Données personnelles effacées (' . count($ids) . ' réservation' . (count($ids) > 1 ? 's' : '') . ' anonymisée' . (count($ids) > 1 ? 's' : '') . ').', 'bookings' => count($ids)];
    }
}
