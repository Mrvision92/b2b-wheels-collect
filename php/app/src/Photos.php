<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Photos jointes au formulaire : vérifiées puis transformées en pièces jointes
 * de l'email envoyé à VBM. Elles ne sont jamais enregistrées sur le serveur.
 */
final class Photos
{
    public const MAX_COUNT = 5;
    public const MAX_TOTAL_BYTES = 4 * 1024 * 1024;

    /** Format reconnu d'après les premiers octets, sans se fier au type annoncé. */
    public static function sniff(string $bytes): ?array
    {
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return ['image/jpeg', 'jpg'];
        }
        if (strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return ['image/png', 'png'];
        }
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return ['image/webp', 'webp'];
        }
        if (substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['heic', 'heix', 'hevc', 'mif1', 'msf1'], true)) {
            return ['image/heic', 'heic'];
        }
        return null;
    }

    /**
     * @param array<string, mixed>|null $upload $_FILES['photos'] (champ « photos[] »)
     * @return array{ok: true, photos: list<array{filename: string, content_type: string, content: string}>}|array{ok: false, message: string}
     */
    public static function fromUpload(?array $upload): array
    {
        if ($upload === null || !isset($upload['tmp_name'])) {
            return ['ok' => true, 'photos' => []];
        }
        $tmpNames = (array) $upload['tmp_name'];
        $errors = (array) $upload['error'];
        $sizes = (array) $upload['size'];

        $files = [];
        foreach ($tmpNames as $i => $tmp) {
            $error = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                return ['ok' => false, 'message' => 'Les photos sont trop lourdes. Retirez-en une ou deux puis réessayez.'];
            }
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $tmp)) {
                return ['ok' => false, 'message' => "Une photo n'a pas pu être reçue. Réessayez."];
            }
            $files[] = ['tmp' => (string) $tmp, 'size' => (int) ($sizes[$i] ?? 0)];
        }

        if (count($files) > self::MAX_COUNT) {
            return ['ok' => false, 'message' => self::MAX_COUNT . ' photos maximum par enlèvement.'];
        }
        if (array_sum(array_column($files, 'size')) > self::MAX_TOTAL_BYTES) {
            return ['ok' => false, 'message' => 'Les photos sont trop lourdes. Retirez-en une ou deux puis réessayez.'];
        }

        $photos = [];
        foreach ($files as $index => $file) {
            $content = (string) file_get_contents($file['tmp']);
            $type = self::sniff($content);
            if ($type === null || $content === '') {
                return ['ok' => false, 'message' => 'Seules les photos (JPEG, PNG, WebP ou HEIC) peuvent être jointes.'];
            }
            $photos[] = ['filename' => 'photo-' . ($index + 1) . '.' . $type[1], 'content_type' => $type[0], 'content' => $content];
        }
        return ['ok' => true, 'photos' => $photos];
    }
}
