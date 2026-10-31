<?php

declare(strict_types=1);

namespace Bwc;

/** Les dix jours fériés légaux belges. */
final class Holidays
{
    /** @var array<int, array<string, string>> */
    private static array $cache = [];

    /** Dimanche de Pâques (calendrier grégorien, algorithme anonyme). */
    private static function easterSunday(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** @return array<string, string> date => nom */
    public static function forYear(int $year): array
    {
        if (!isset(self::$cache[$year])) {
            $easter = self::easterSunday($year);
            self::$cache[$year] = [
                "$year-01-01" => "Jour de l'an",
                Time::addDays($easter, 1) => 'Lundi de Pâques',
                "$year-05-01" => 'Fête du Travail',
                Time::addDays($easter, 39) => 'Ascension',
                Time::addDays($easter, 50) => 'Lundi de Pentecôte',
                "$year-07-21" => 'Fête nationale',
                "$year-08-15" => 'Assomption',
                "$year-11-01" => 'Toussaint',
                "$year-11-11" => 'Armistice',
                "$year-12-25" => 'Noël',
            ];
        }
        return self::$cache[$year];
    }

    public static function isHoliday(string $date): bool
    {
        return isset(self::forYear((int) substr($date, 0, 4))[$date]);
    }
}
