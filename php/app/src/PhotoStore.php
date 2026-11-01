<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Photos des réservations : fichiers dans app/storage/photos/<réservation>/,
 * hors de public_html, et liste dans bwc_booking_photos. Elles ne sont
 * visibles que dans l'administration (admin/photo.php vérifie la session).
 */
final class PhotoStore
{
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic'];

    public function __construct(private \PDO $pdo, private string $dir)
    {
    }

    private function folder(int $bookingId): string
    {
        return $this->dir . '/' . $bookingId;
    }

    /**
     * Enregistre les photos vérifiées (Photos::fromUpload) d'une réservation.
     *
     * @param list<array{filename: string, content_type: string, content: string}> $photos
     * @return int nombre de photos enregistrées
     */
    public function save(int $bookingId, array $photos): int
    {
        if ($photos === []) {
            return 0;
        }
        $folder = $this->folder($bookingId);
        if (!is_dir($folder) && !@mkdir($folder, 0750, true)) {
            throw new \RuntimeException("Dossier des photos impossible à créer : $folder");
        }
        $saved = 0;
        foreach (array_values($photos) as $index => $photo) {
            $extension = self::TYPES[$photo['content_type']] ?? null;
            if ($extension === null) {
                continue;
            }
            $position = $index + 1;
            $name = "photo-$position.$extension";
            if (file_put_contents("$folder/$name", $photo['content'], LOCK_EX) === false) {
                throw new \RuntimeException("Photo impossible à enregistrer : $name");
            }
            @chmod("$folder/$name", 0640);
            $this->pdo->prepare(
                'INSERT IGNORE INTO bwc_booking_photos (booking_id, position, file_name, content_type, size_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            )->execute([$bookingId, $position, $name, $photo['content_type'], strlen($photo['content']), BookingStore::datetime(time())]);
            $saved++;
        }
        return $saved;
    }

    /** @return list<array<string, mixed>> photos d'une réservation, dans l'ordre */
    public function forBooking(int $bookingId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bwc_booking_photos WHERE booking_id = ? ORDER BY position');
        $statement->execute([$bookingId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bwc_booking_photos WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** Chemin du fichier, ou null s'il a disparu. */
    public function path(array $photo): ?string
    {
        $path = $this->folder((int) $photo['booking_id']) . '/' . basename((string) $photo['file_name']);
        return is_file($path) ? $path : null;
    }

    /**
     * Les photos en pièces jointes, pour un nouvel envoi de la notification VBM.
     *
     * @return list<array{filename: string, content_type: string, content: string}>
     */
    public function attachments(int $bookingId): array
    {
        $files = [];
        foreach ($this->forBooking($bookingId) as $photo) {
            $path = $this->path($photo);
            if ($path !== null) {
                $files[] = ['filename' => $photo['file_name'], 'content_type' => $photo['content_type'], 'content' => (string) file_get_contents($path)];
            }
        }
        return $files;
    }

    /** Supprime les photos d'une réservation (fichiers et liste). */
    public function deleteForBooking(int $bookingId): int
    {
        $photos = $this->forBooking($bookingId);
        foreach ($photos as $photo) {
            $path = $this->path($photo);
            if ($path !== null) {
                @unlink($path);
            }
        }
        @rmdir($this->folder($bookingId));
        $this->pdo->prepare('DELETE FROM bwc_booking_photos WHERE booking_id = ?')->execute([$bookingId]);
        return count($photos);
    }
}
