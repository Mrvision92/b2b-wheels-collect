<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Créneaux tels que configurés dans settings.php, avant de regarder les
 * réservations et l'agenda.
 *
 * Un créneau : ['key' => '0900', 'date', 'startTime', 'endTime', 'start', 'end', 'label']
 * (start et end en horodatages Unix).
 */
final class Schedule
{
    /** @param array<string, mixed> $config */
    public static function isOpenDay(string $date, array $config): bool
    {
        if (in_array($date, $config['closed_dates'], true)) {
            return false;
        }
        if ($config['close_on_public_holidays'] && Holidays::isHoliday($date)) {
            return false;
        }
        return !empty($config['weekly_schedule'][Time::isoWeekday($date)]);
    }

    /**
     * Tous les créneaux configurés d'un jour, sans tenir compte du délai de prévenance.
     *
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    public static function slotsForDate(string $date, array $config): array
    {
        if (!Time::isValidDate($date) || !self::isOpenDay($date, $config)) {
            return [];
        }
        $length = (int) $config['slot_minutes'];
        $step = (int) ($config['slot_step_minutes'] ?? 0) ?: $length;
        $slots = [];
        foreach ($config['weekly_schedule'][Time::isoWeekday($date)] as [$from, $to]) {
            $close = Time::toMinutes($to);
            for ($t = Time::toMinutes($from); $t + $length <= $close; $t += $step) {
                $startTime = Time::fromMinutes($t);
                $endTime = Time::fromMinutes($t + $length);
                $start = Time::zonedToUtc($date, $startTime);
                $end = Time::zonedToUtc($date, $endTime);
                // Les heures qui n'existent pas un jour de changement d'heure sont ignorées.
                if ($start === null || $end === null) {
                    continue;
                }
                $slots[] = [
                    'key' => str_replace(':', '', $startTime),
                    'date' => $date,
                    'startTime' => $startTime,
                    'endTime' => $endTime,
                    'start' => $start,
                    'end' => $end,
                    'label' => Time::formatSlotLabel($startTime, $endTime, $config['mode']),
                ];
            }
        }
        usort($slots, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        return $slots;
    }

    /**
     * Période pendant laquelle un garage peut choisir une date.
     *
     * @param array<string, mixed> $config
     * @return array{today: string, lastDate: string, earliestStart: int}
     */
    public static function window(int $now, array $config): array
    {
        $today = Time::dateOf($now);
        return [
            'today' => $today,
            'lastDate' => Time::addDays($today, (int) $config['max_advance_days']),
            'earliestStart' => $now + (int) round((float) $config['min_notice_hours'] * 3600),
        ];
    }

    /**
     * Vrai quand le créneau respecte le délai de prévenance et la limite de réservation à l'avance.
     *
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $config
     */
    public static function isBookable(array $slot, int $now, array $config): bool
    {
        $window = self::window($now, $config);
        return $slot['start'] >= $window['earliestStart'] && $slot['date'] <= $window['lastDate'];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    public static function findSlot(string $date, string $key, array $config): ?array
    {
        foreach (self::slotsForDate($date, $config) as $slot) {
            if ($slot['key'] === $key) {
                return $slot;
            }
        }
        return null;
    }
}
