<?php

declare(strict_types=1);

namespace Bwc;

use Bwc\Calendar\CalendarClient;

/**
 * Occupation des créneaux.
 *
 * Chaque réservation confirmée dans MySQL prend une place. Quand Google
 * Agenda est connecté, c'est le planning de VBM qui fait foi pour ce qui y a
 * été changé : un rendez-vous ajouté à la main prend une place, un événement
 * « Fermé » ou « Bloqué » (ou sur toute la journée) ferme le créneau, une
 * réservation déplacée suit son événement et une réservation dont
 * l'événement a été supprimé libère sa place.
 */
final class Availability
{
    /** Titres qui ferment un créneau, sans tenir compte des accents ni des majuscules. */
    private const BLOCKING_WORDS = '/\b(bloque|bloquee|ferme|fermee|fermeture|indisponible|complet|conge|conges)\b/';

    /** Marge de sécurité entre la lecture de l'agenda et la copie d'une réservation. */
    private const SYNC_MARGIN_SECONDS = 2.0;

    private static function normalize(string $text): string
    {
        return strtr(mb_strtolower($text), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u',
            'ü' => 'u', 'ú' => 'u', 'ç' => 'c', 'ÿ' => 'y',
        ]);
    }

    /** @param array<string, mixed> $event */
    public static function isOwnEvent(array $event): bool
    {
        return ($event['props']['bwc'] ?? '') === '1' && ($event['props']['bwcRef'] ?? '') !== '';
    }

    /** Les réservations du site ne ferment jamais un créneau, même si le nom du garage contient « complet ». */
    public static function isBlocking(array $event): bool
    {
        return !self::isOwnEvent($event)
            && ($event['allDay'] || preg_match(self::BLOCKING_WORDS, self::normalize($event['summary'])) === 1);
    }

    /** Événements qui prennent une place (ni annulés, ni marqués « Disponible »). */
    public static function isActive(array $event): bool
    {
        return $event['status'] !== 'cancelled' && !$event['transparent'];
    }

    /**
     * @param array<string, mixed> $slot
     * @param list<array<string, mixed>> $rows Réservations MySQL qui chevauchent le créneau (ou plus large)
     * @param list<array<string, mixed>>|null $events Événements Google, null si l'agenda n'est pas connecté
     * @param float|null $fetchedAt Moment où la lecture de l'agenda a commencé
     * @return array{blocked: bool, taken: int, available: bool}
     */
    public static function evaluate(array $slot, array $rows, ?array $events, ?float $fetchedAt, int $capacity): array
    {
        $blocked = false;
        $taken = 0;
        $inCalendar = [];

        foreach ($events ?? [] as $event) {
            if (!self::isActive($event) || $event['start'] >= $slot['end'] || $event['end'] <= $slot['start']) {
                continue;
            }
            if (self::isBlocking($event)) {
                $blocked = true;
            }
            if (self::isOwnEvent($event)) {
                $inCalendar[$event['props']['bwcRef']] = true;
            }
            $taken++;
        }

        foreach ($rows as $row) {
            if ($row['start_ts'] >= $slot['end'] || $row['end_ts'] <= $slot['start'] || isset($inCalendar[$row['reference']])) {
                continue;
            }
            // Copiée dans l'agenda avant sa lecture, mais absente de ce créneau :
            // VBM a déplacé ou supprimé l'événement.
            if ($events !== null && $row['google_status'] === 'synced' && $row['synced_ts'] !== null
                && $row['synced_ts'] < $fetchedAt - self::SYNC_MARGIN_SECONDS) {
                continue;
            }
            $taken++;
        }

        return ['blocked' => $blocked, 'taken' => $taken, 'available' => !$blocked && $taken < $capacity];
    }

    /**
     * Disponibilités d'un mois pour le calendrier du formulaire. Ne contient
     * jamais qui a réservé quoi.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function month(string $month, array $config, BookingStore $store, ?CalendarClient $calendar, int $now): array
    {
        $window = Schedule::window($now, $config);
        $base = [
            'month' => $month,
            'today' => $window['today'],
            'firstMonth' => substr($window['today'], 0, 7),
            'lastMonth' => substr($window['lastDate'], 0, 7),
            'mode' => $config['mode'],
            'days' => [],
        ];

        $first = "$month-01";
        $last = sprintf('%s-%02d', $month, Time::daysInMonth($month));
        $from = max($first, $window['today']);
        $to = min($last, $window['lastDate']);
        if ($from > $to) {
            return $base;
        }

        $candidates = [];
        for ($date = $from; $date <= $to; $date = Time::addDays($date, 1)) {
            $slots = array_values(array_filter(
                Schedule::slotsForDate($date, $config),
                static fn (array $slot): bool => Schedule::isBookable($slot, $now, $config),
            ));
            if ($slots !== []) {
                $candidates[$date] = $slots;
            }
        }
        if ($candidates === []) {
            return $base;
        }

        $timeMin = (int) Time::zonedToUtc($from, '00:00');
        $timeMax = (int) Time::zonedToUtc(Time::addDays($to, 1), '00:00');
        $rows = $store->activeBetween($timeMin, $timeMax);
        $events = null;
        $fetchedAt = null;
        if ($calendar !== null) {
            [$events, $fetchedAt] = EventCache::list($calendar, $timeMin, $timeMax);
        }

        $capacity = (int) $config['capacity_per_slot'];
        foreach ($candidates as $date => $slots) {
            $base['days'][] = [
                'date' => $date,
                'slots' => array_map(static fn (array $slot): array => [
                    'key' => $slot['key'],
                    'label' => $slot['label'],
                    'available' => self::evaluate($slot, $rows, $events, $fetchedAt, $capacity)['available'],
                ], $slots),
            ];
        }
        return $base;
    }
}
