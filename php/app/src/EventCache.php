<?php

declare(strict_types=1);

namespace Bwc;

use Bwc\Calendar\CalendarClient;

/**
 * Cache de quelques secondes des événements Google pour l'affichage du
 * calendrier. La réservation, elle, relit toujours l'agenda.
 */
final class EventCache
{
    private const TTL_SECONDS = 20;

    /** @return array{0: list<array<string, mixed>>, 1: float} [événements, moment de la lecture] */
    public static function list(CalendarClient $calendar, int $timeMin, int $timeMax): array
    {
        $file = Config::get()->storage('cache') . '/events-' . md5("$timeMin-$timeMax") . '.json';
        $cached = json_decode((string) @file_get_contents($file), true);
        if (is_array($cached) && ($cached['fetchedAt'] ?? 0) > microtime(true) - self::TTL_SECONDS) {
            return [$cached['events'], (float) $cached['fetchedAt']];
        }
        $fetchedAt = microtime(true);
        $events = $calendar->listEvents($timeMin, $timeMax);
        @file_put_contents($file, json_encode(['fetchedAt' => $fetchedAt, 'events' => $events]), LOCK_EX);
        return [$events, $fetchedAt];
    }

    public static function clear(): void
    {
        foreach (glob(Config::get()->storage('cache') . '/events-*.json') ?: [] as $file) {
            @unlink($file);
        }
    }
}
