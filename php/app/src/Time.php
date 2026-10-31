<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Dates « AAAA-MM-JJ » et heures « HH:MM » à l'heure de Bruxelles ; les
 * instants sont des horodatages Unix (UTC). Les changements d'heure été/hiver
 * sont gérés par PHP.
 */
final class Time
{
    public const ZONE = 'Europe/Brussels';

    private const DAYS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    private const MONTHS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private static ?\DateTimeZone $zone = null;

    public static function zone(): \DateTimeZone
    {
        return self::$zone ??= new \DateTimeZone(self::ZONE);
    }

    public static function isValidDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function isValidTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    public static function isValidMonth(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
    }

    /**
     * Heure de Bruxelles → horodatage. Null pour une heure qui n'existe pas
     * (l'heure sautée au passage à l'heure d'été).
     */
    public static function zonedToUtc(string $date, string $time): ?int
    {
        if (!self::isValidDate($date) || !self::isValidTime($time)) {
            return null;
        }
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", self::zone());
        if ($at === false || $at->format('Y-m-d H:i') !== "$date $time") {
            return null;
        }
        return $at->getTimestamp();
    }

    public static function dateOf(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(self::zone())->format('Y-m-d');
    }

    public static function timeOf(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(self::zone())->format('H:i');
    }

    public static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date . ' 12:00', new \DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** 1 = lundi … 7 = dimanche. */
    public static function isoWeekday(string $date): int
    {
        return (int) (new \DateTimeImmutable($date . ' 12:00', new \DateTimeZone('UTC')))->format('N');
    }

    public static function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    }

    public static function fromMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function daysInMonth(string $month): int
    {
        return (int) (new \DateTimeImmutable($month . '-01 12:00', new \DateTimeZone('UTC')))->format('t');
    }

    public static function addMonths(string $month, int $delta): string
    {
        [$y, $m] = array_map('intval', explode('-', $month));
        $index = $y * 12 + ($m - 1) + $delta;
        return sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
    }

    /* Libellés en français ------------------------------------------------ */

    /** « jeudi 29 octobre 2026 » */
    public static function formatDateLong(string $date): string
    {
        return self::formatDayMonth($date) . ' ' . substr($date, 0, 4);
    }

    /** « jeudi 29 octobre » */
    public static function formatDayMonth(string $date): string
    {
        [, $m, $d] = array_map('intval', explode('-', $date));
        return self::DAYS[self::isoWeekday($date)] . ' ' . $d . ' ' . self::MONTHS[$m];
    }

    /** « novembre 2026 » */
    public static function formatMonthTitle(string $month): string
    {
        return self::MONTHS[(int) substr($month, 5, 2)] . ' ' . substr($month, 0, 4);
    }

    /** « 9 h », « 9 h 30 », « 14 h » */
    public static function formatClock(string $time): string
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $m === 0 ? "$h h" : sprintf('%d h %02d', $h, $m);
    }

    /** « 9 h – 11 h » pour une plage, « 9 h 30 » pour une heure précise. */
    public static function formatSlotLabel(string $start, string $end, string $mode): string
    {
        return $mode === 'ranges' ? self::formatClock($start) . ' – ' . self::formatClock($end) : self::formatClock($start);
    }

    /** « entre 9 h et 11 h » ou « à 9 h 30 », dans une phrase. */
    public static function formatSlotPhrase(string $start, string $end, string $mode): string
    {
        return $mode === 'ranges'
            ? 'entre ' . self::formatClock($start) . ' et ' . self::formatClock($end)
            : 'à ' . self::formatClock($start);
    }

    public static function capitalize(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }
}
