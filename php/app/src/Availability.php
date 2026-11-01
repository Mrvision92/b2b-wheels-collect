<?php

declare(strict_types=1);

namespace Bwc;

use Bwc\Calendar\CalendarClient;

/**
 * Occupation des créneaux.
 *
 * La base MySQL fait foi : chaque réservation en attente, à enlever ou
 * enlevée prend une place. Quand Google Agenda est connecté, ce que VBM y
 * ajoute lui-même compte aussi : un rendez-vous pris à la main occupe une
 * place, un événement « Fermé », « Bloqué »… (ou sur toute la journée) ferme
 * le créneau. Les événements créés par le site, eux, sont ignorés : ils ne
 * sont qu'une copie des réservations.
 */
final class Availability
{
    /** Titres qui ferment un créneau, sans tenir compte des accents ni des majuscules. */
    private const BLOCKING_WORDS = '/\b(bloque|bloquee|ferme|fermee|fermeture|indisponible|complet|conge|conges)\b/';

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
     * @param list<array<string, mixed>> $rows Réservations MySQL actives qui chevauchent le créneau (ou plus large)
     * @param list<array<string, mixed>>|null $events Événements Google, null si l'agenda n'est pas connecté
     * @return array{blocked: bool, taken: int, available: bool}
     */
    public static function evaluate(array $slot, array $rows, ?array $events, int $capacity): array
    {
        $blocked = false;
        $taken = 0;
        foreach ($events ?? [] as $event) {
            if (self::isOwnEvent($event) || !self::isActive($event) || $event['start'] >= $slot['end'] || $event['end'] <= $slot['start']) {
                continue;
            }
            $blocked = $blocked || self::isBlocking($event);
            $taken++;
        }
        foreach ($rows as $row) {
            if ($row['start_ts'] < $slot['end'] && $row['end_ts'] > $slot['start'] && in_array($row['status'], BookingStore::ACTIVE, true)) {
                $taken++;
            }
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
        $events = $calendar !== null ? EventCache::list($calendar, $timeMin, $timeMax) : null;

        $capacity = (int) $config['capacity_per_slot'];
        foreach ($candidates as $date => $slots) {
            $base['days'][] = [
                'date' => $date,
                'slots' => array_map(static fn (array $slot): array => [
                    'key' => $slot['key'],
                    'label' => $slot['label'],
                    'available' => self::evaluate($slot, $rows, $events, $capacity)['available'],
                ], $slots),
            ];
        }
        return $base;
    }

    /**
     * Créneaux d'un jour pour l'administration : sans délai de prévenance ni
     * limite de réservation à l'avance, mais avec les mêmes places que le site.
     *
     * @param array<string, mixed> $config
     * @return list<array{key: string, label: string, available: bool, remaining: int, past: bool}>
     */
    public static function daySlotsForAdmin(string $date, array $config, BookingStore $store, ?CalendarClient $calendar, int $now, ?int $excludeId = null): array
    {
        $slots = Schedule::slotsForDate($date, $config);
        if ($slots === []) {
            return [];
        }
        $rows = $store->activeBetween($slots[0]['start'], end($slots)['end'], $excludeId);
        $events = $calendar !== null ? $calendar->listEvents($slots[0]['start'], end($slots)['end']) : null;
        $capacity = (int) $config['capacity_per_slot'];
        return array_map(static function (array $slot) use ($rows, $events, $capacity, $now): array {
            $result = self::evaluate($slot, $rows, $events, $capacity);
            $past = $slot['end'] <= $now;
            return [
                'key' => $slot['key'],
                'label' => $slot['startTime'] . ' – ' . $slot['endTime'],
                'available' => $result['available'] && !$past,
                'remaining' => $result['blocked'] ? 0 : max(0, $capacity - $result['taken']),
                'past' => $past,
            ];
        }, $slots);
    }
}
