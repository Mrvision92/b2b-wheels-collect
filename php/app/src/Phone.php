<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Numéros de téléphone : les formats belges sont compris sans indicatif
 * (« 0470 12 34 56 », « 02 123 45 67 »), les numéros étrangers avec « + » ou
 * « 00 ». Tout est normalisé au format international (« +32470123456 »).
 */
final class Phone
{
    public static function normalize(string $raw): ?string
    {
        $value = preg_replace('/[\s.\-\/()]/', '', trim($raw)) ?? '';
        if (str_starts_with($value, '00')) {
            $value = '+' . substr($value, 2);
        } elseif (str_starts_with($value, '0')) {
            $value = '+32' . substr($value, 1);
        }
        if (preg_match('/^\+[1-9]\d{6,14}$/', $value) !== 1) {
            return null;
        }
        if (str_starts_with($value, '+32')) {
            $national = substr($value, 3);
            if (str_starts_with($national, '0')) {
                return null;
            }
            // Mobiles : 4xx xx xx xx (9 chiffres). Fixes : 8 chiffres.
            $expected = str_starts_with($national, '4') ? 9 : 8;
            if (strlen($national) !== $expected) {
                return null;
            }
        }
        return $value;
    }

    /** « +32 470 12 34 56 », « +32 2 123 45 67 », ou tel quel pour les autres pays. */
    public static function format(string $e164): string
    {
        if (!str_starts_with($e164, '+32')) {
            return $e164;
        }
        $n = substr($e164, 3);
        if (strlen($n) === 9) {
            return sprintf('+32 %s %s %s %s', substr($n, 0, 3), substr($n, 3, 2), substr($n, 5, 2), substr($n, 7));
        }
        if (str_contains('2349', $n[0])) {
            return sprintf('+32 %s %s %s %s', $n[0], substr($n, 1, 3), substr($n, 4, 2), substr($n, 6));
        }
        return sprintf('+32 %s %s %s %s', substr($n, 0, 2), substr($n, 2, 2), substr($n, 4, 2), substr($n, 6));
    }
}
