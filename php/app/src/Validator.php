<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Vérification du formulaire de réservation. C'est la seule qui compte : le
 * navigateur refait les mêmes contrôles uniquement pour répondre plus vite.
 */
final class Validator
{
    private const CONTROL_CHARS = '/[\x00-\x1F\x7F]+/u';
    private const CONTROL_CHARS_EXCEPT_NEWLINE = '/[\x00-\x09\x0B-\x1F\x7F]+/u';

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>|null, 1: array<string, string>} [données nettoyées, erreurs par champ]
     */
    public static function booking(array $in, int $maxRims): array
    {
        $errors = [];
        $data = [];
        $check = static function (string $field, callable $rule) use ($in, &$data, &$errors): void {
            $result = $rule($in[$field] ?? null);
            if (is_array($result) && isset($result['error'])) {
                $errors[$field] = $result['error'];
            } else {
                $data[$field] = $result;
            }
        };

        $check('requestId', static fn (mixed $v) => is_string($v)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v) === 1
            ? strtolower($v) : ['error' => 'Requête invalide. Rechargez la page.']);

        $check('garageName', static fn (mixed $v) => self::singleLine($v, 2, 120, 'Indiquez le nom du garage.', 'Ce nom est trop long (120 caractères maximum).'));

        $check('contactName', static function (mixed $v) {
            $value = self::singleLine($v, 3, 120, 'Indiquez le prénom et le nom.', 'Ce nom est trop long (120 caractères maximum).');
            if (is_string($value) && count(explode(' ', $value)) < 2) {
                return ['error' => 'Indiquez le prénom et le nom.'];
            }
            return $value;
        });

        $check('phone', static function (mixed $v) {
            if (!is_string($v) || trim($v) === '') {
                return ['error' => 'Indiquez un numéro de téléphone.'];
            }
            if (mb_strlen($v) > 40) {
                return ['error' => 'Numéro de téléphone invalide.'];
            }
            return Phone::normalize($v) ?? ['error' => 'Numéro invalide. Exemple : 0470 12 34 56 ou +32 2 123 45 67.'];
        });

        $check('email', static function (mixed $v) {
            $value = is_string($v) ? trim($v) : '';
            if ($value === '') {
                return ['error' => 'Indiquez une adresse email.'];
            }
            if (strlen($value) > 254) {
                return ['error' => 'Adresse email trop longue.'];
            }
            if (filter_var($value, FILTER_VALIDATE_EMAIL) === false || preg_match('/\.[a-z]{2,}$/i', $value) !== 1) {
                return ['error' => 'Adresse email invalide. Exemple : atelier@garage.be'];
            }
            return $value;
        });

        $check('street', static function (mixed $v) {
            $value = self::singleLine($v, 3, 160, 'Indiquez la rue et le numéro.', 'Adresse trop longue (160 caractères maximum).');
            if (is_string($value) && preg_match('/\d/', $value) !== 1) {
                return ['error' => 'Ajoutez le numéro de rue.'];
            }
            return $value;
        });

        $check('postalCode', static function (mixed $v) {
            $value = is_string($v) ? trim($v) : '';
            if ($value === '') {
                return ['error' => 'Indiquez le code postal.'];
            }
            return preg_match('/^[1-9]\d{3}$/', $value) === 1 ? $value : ['error' => 'Code postal belge à 4 chiffres.'];
        });

        $check('city', static fn (mixed $v) => self::singleLine($v, 2, 80, 'Indiquez la ville.', 'Nom de ville trop long.'));

        $check('rimCount', static function (mixed $v) use ($maxRims) {
            if (is_string($v)) {
                $v = trim($v) === '' ? 0 : (is_numeric($v) ? $v + 0 : null);
            }
            if (!is_int($v) && !is_float($v)) {
                return ['error' => 'Indiquez le nombre de jantes.'];
            }
            if ((float) $v !== floor((float) $v)) {
                return ['error' => 'Indiquez un nombre entier.'];
            }
            if ($v < 1) {
                return ['error' => 'Indiquez au moins 1 jante.'];
            }
            if ($v > $maxRims) {
                return ['error' => "Pour plus de $maxRims jantes, contactez-nous directement."];
            }
            return (int) $v;
        });

        $check('date', static fn (mixed $v) => Time::isValidDate($v) ? $v : ['error' => 'Choisissez une date.']);

        $check('slot', static fn (mixed $v) => is_string($v) && preg_match('/^\d{4}$/', $v) === 1 ? $v : ['error' => 'Choisissez un créneau.']);

        $check('comment', static function (mixed $v) {
            $raw = is_string($v) ? $v : '';
            $message = 'Commentaire trop long (500 caractères maximum).';
            if (mb_strlen($raw) > 2000) {
                return ['error' => $message];
            }
            $value = trim((string) preg_replace(self::CONTROL_CHARS_EXCEPT_NEWLINE, ' ', $raw));
            return mb_strlen($value) > 500 ? ['error' => $message] : $value;
        });

        $check('garageReference', static function (mixed $v) {
            $raw = is_string($v) ? $v : '';
            $message = 'Référence trop longue (60 caractères maximum).';
            if (mb_strlen($raw) > 200) {
                return ['error' => $message];
            }
            $value = self::collapse($raw);
            return mb_strlen($value) > 60 ? ['error' => $message] : $value;
        });

        $check('turnstileToken', static fn (mixed $v) => is_string($v) ? substr($v, 0, 4096) : '');

        return [$errors === [] ? $data : null, $errors];
    }

    private static function collapse(string $value): string
    {
        $value = (string) preg_replace(self::CONTROL_CHARS, ' ', $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @return string|array{error: string} */
    private static function singleLine(mixed $v, int $min, int $max, string $minMessage, string $maxMessage): string|array
    {
        if (!is_string($v)) {
            return ['error' => $minMessage];
        }
        $value = self::collapse($v);
        if (mb_strlen($value) < $min) {
            return ['error' => $minMessage];
        }
        if (mb_strlen($value) > $max) {
            return ['error' => $maxMessage];
        }
        return $value;
    }
}
