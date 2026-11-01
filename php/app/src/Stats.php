<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Chiffres du tableau de bord, calculés dans la base, à l'heure de Bruxelles.
 *
 * Règles de comptage :
 * - « Aujourd'hui » : enlèvements prévus ce jour (en attente, à enlever ou
 *   déjà enlevés), d'après la date du créneau ; les annulés ne comptent pas.
 * - « À venir » : enlèvements en attente ou à enlever, prévus à partir
 *   d'aujourd'hui (ceux du jour encore à faire compris).
 * - « Effectués » et « Jantes récupérées » : d'après la date réelle de
 *   l'enlèvement (completed_at), pas la date de réservation ni du créneau.
 *   Le mois en cours est comparé à la même période du mois précédent (du 1er
 *   au même jour, à la même heure) ; un mois passé, au mois précédent entier.
 * - « Réservations reçues » : d'après la date de création de la réservation.
 */
final class Stats
{
    private const MONTHS = [1 => 'Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array{0: string, 1: string} début et fin (UTC, exclue) d'un mois de Bruxelles */
    public static function monthBounds(string $month): array
    {
        return [
            BookingStore::datetime((int) Time::zonedToUtc("$month-01", '00:00')),
            BookingStore::datetime((int) Time::zonedToUtc(Time::addMonths($month, 1) . '-01', '00:00')),
        ];
    }

    public static function monthLabel(string $month): string
    {
        return self::MONTHS[(int) substr($month, 5, 2)];
    }

    private function scalar(string $sql, array $params): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    /**
     * @param int|null $until arrêter le décompte à cet instant (mois en cours)
     * @return array{completed: int, rims: int, received: int}
     */
    public function month(string $month, ?int $until = null): array
    {
        [$from, $to] = self::monthBounds($month);
        if ($until !== null) {
            $to = min($to, BookingStore::datetime($until));
        }
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) AS completed, COALESCE(SUM(rim_count), 0) AS rims FROM bwc_bookings WHERE status = 'completed' AND completed_at >= ? AND completed_at < ?",
        );
        $statement->execute([$from, $to]);
        $row = $statement->fetch();
        return [
            'completed' => (int) $row['completed'],
            'rims' => (int) $row['rims'],
            'received' => $this->scalar('SELECT COUNT(*) FROM bwc_bookings WHERE created_at >= ? AND created_at < ?', [$from, $to]),
        ];
    }

    /**
     * @param string|null $month mois choisi (AAAA-MM), le mois en cours par défaut
     * @return array<string, mixed>
     */
    public function dashboard(int $now, ?string $month = null): array
    {
        $today = Time::dateOf($now);
        $current = substr($today, 0, 7);
        $month = $month !== null && Time::isValidMonth($month) && $month <= $current ? $month : $current;
        $selected = $this->month($month);
        $previousMonth = Time::addMonths($month, -1);
        // Même période du mois précédent : le 15 novembre à 10 h se compare au 15 octobre à 10 h.
        $samePeriod = null;
        if ($month === $current) {
            $elapsed = $now - (int) Time::zonedToUtc("$month-01", '00:00');
            $samePeriod = min((int) Time::zonedToUtc("$month-01", '00:00'), (int) Time::zonedToUtc("$previousMonth-01", '00:00') + $elapsed);
        }
        $previous = $this->month($previousMonth, $samePeriod);

        $chart = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = Time::addMonths($month, -$i);
            $chart[] = ['month' => $m, 'label' => self::monthLabel($m)] + ($m === $month ? $selected : $this->month($m));
        }

        return [
            'today' => $today,
            'month' => $month,
            'isCurrentMonth' => $month === $current,
            'todayCount' => $this->scalar("SELECT COUNT(*) FROM bwc_bookings WHERE slot_date = ? AND status <> 'cancelled'", [$today]),
            'upcomingCount' => $this->scalar("SELECT COUNT(*) FROM bwc_bookings WHERE slot_date >= ? AND status IN ('pending', 'confirmed')", [$today]),
            'overdueCount' => $this->scalar("SELECT COUNT(*) FROM bwc_bookings WHERE ends_at <= ? AND slot_date < ? AND status IN ('pending', 'confirmed')", [BookingStore::datetime($now), $today]),
            'completed' => $selected['completed'],
            'rims' => $selected['rims'],
            'received' => $selected['received'],
            'previousCompleted' => $previous['completed'],
            'change' => $previous['completed'] > 0 ? round(($selected['completed'] - $previous['completed']) / $previous['completed'] * 100, 1) : null,
            'chart' => $chart,
        ];
    }
}
