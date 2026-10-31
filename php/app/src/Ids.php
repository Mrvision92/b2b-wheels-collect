<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Identifiants dérivés de l'identifiant de demande du navigateur et d'une clé
 * secrète. La même demande donne toujours la même réservation (double clic,
 * connexion perdue), sans qu'on puisse deviner les identifiants des autres.
 */
final class Ids
{
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private static function hmac(string $secret, string $purpose, string $value): string
    {
        return hash_hmac('sha256', "$purpose:$value", $secret, true);
    }

    /** Empreinte stockée à la place de l'identifiant de demande. */
    public static function requestHash(string $requestId, string $secret): string
    {
        return bin2hex(self::hmac($secret, 'request', $requestId));
    }

    /** Google Agenda accepte les lettres a à v et les chiffres : l'hexadécimal convient. */
    public static function eventId(string $requestId, string $secret): string
    {
        return 'rc' . substr(bin2hex(self::hmac($secret, 'event-id', $requestId)), 0, 40);
    }

    /** Référence courte et lisible, ex. « BWC-7K4M-2QX9 ». */
    public static function reference(string $requestId, string $secret): string
    {
        $bytes = self::hmac($secret, 'reference', $requestId);
        $bits = 0;
        $value = 0;
        $out = '';
        foreach (str_split($bytes) as $byte) {
            $value = (($value << 8) | ord($byte)) & 0xFFFF;
            $bits += 8;
            while ($bits >= 5 && strlen($out) < 8) {
                $out .= self::CROCKFORD[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
            if (strlen($out) === 8) {
                break;
            }
        }
        return 'BWC-' . substr($out, 0, 4) . '-' . substr($out, 4);
    }
}
