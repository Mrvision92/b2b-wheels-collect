<?php

declare(strict_types=1);

namespace Bwc\Calendar;

/**
 * Agenda de planification. Un événement circule sous la forme :
 * ['id', 'status', 'summary', 'start', 'end' (horodatages), 'allDay',
 *  'transparent', 'htmlLink', 'props' (propriétés privées)].
 */
interface CalendarClient
{
    /**
     * @return list<array<string, mixed>> événements qui chevauchent la période
     * @throws CalendarUnavailable
     */
    public function listEvents(int $timeMin, int $timeMax): array;

    /**
     * @return array<string, mixed>|null
     * @throws CalendarUnavailable
     */
    public function getEvent(string $id): ?array;

    /**
     * @param array<string, mixed> $event corps de l'événement au format Google
     * @return array<string, mixed>|null l'événement créé, ou null s'il existait déjà
     * @throws CalendarUnavailable
     */
    public function insertEvent(array $event): ?array;
}
