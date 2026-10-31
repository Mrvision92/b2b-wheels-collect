<?php

declare(strict_types=1);

namespace Bwc\Mail;

/**
 * Mode démonstration : rien ne sort du serveur. Les emails sont enregistrés
 * dans app/storage/demo-emails et consultables sur /demo/email.php?id=…
 */
final class DemoMailer implements Mailer
{
    public function __construct(private string $dir)
    {
    }

    public function kind(): string
    {
        return 'demo';
    }

    public static function idFor(string $idempotencyKey): string
    {
        return substr(hash('sha256', $idempotencyKey), 0, 24);
    }

    public function send(array $email): void
    {
        $file = $this->dir . '/' . self::idFor($email['idempotency_key']) . '.json';
        if (is_file($file)) {
            return; // Déjà « envoyé » : comme un service qui ignore les doublons.
        }
        $stored = $email;
        $stored['created_at'] = gmdate('c');
        $stored['attachments'] = array_map(static fn (array $file): array => [
            'filename' => $file['filename'],
            'content_type' => $file['content_type'],
            'base64' => base64_encode($file['content']),
        ], $email['attachments'] ?? []);
        if (file_put_contents($file, json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new MailerException("Impossible d'enregistrer l'email de démonstration", true);
        }
    }

    /** @return array<string, mixed>|null */
    public function read(string $id): ?array
    {
        if (preg_match('/^[a-f0-9]{24}$/', $id) !== 1) {
            return null;
        }
        $json = json_decode((string) @file_get_contents("{$this->dir}/$id.json"), true);
        return is_array($json) ? $json : null;
    }
}
