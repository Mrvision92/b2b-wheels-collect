<?php

declare(strict_types=1);

namespace Bwc\Mail;

use Bwc\HttpClient;

/** Envoi par l'API Resend (https://resend.com), qui ignore les doublons grâce à la clé d'idempotence. */
final class ResendMailer implements Mailer
{
    public function __construct(private string $apiKey, private string $from)
    {
    }

    public function kind(): string
    {
        return 'resend';
    }

    public function send(array $email): void
    {
        $payload = [
            'from' => $this->from,
            'to' => $email['to'],
            'subject' => $email['subject'],
            'html' => $email['html'],
            'text' => $email['text'],
        ];
        if (!empty($email['reply_to'])) {
            $payload['reply_to'] = $email['reply_to'];
        }
        if (!empty($email['attachments'])) {
            $payload['attachments'] = array_map(static fn (array $file): array => [
                'filename' => $file['filename'],
                'content' => base64_encode($file['content']),
                'content_type' => $file['content_type'],
            ], $email['attachments']);
        }

        try {
            $response = HttpClient::request('POST', 'https://api.resend.com/emails', [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Idempotency-Key: ' . $email['idempotency_key'],
            ], json_encode($payload, JSON_UNESCAPED_UNICODE), empty($email['attachments']) ? 10 : 25);
        } catch (\RuntimeException $error) {
            throw new MailerException('Resend injoignable : ' . $error->getMessage(), true);
        }
        $json = json_decode($response['body'], true) ?: [];
        if ($response['status'] >= 200 && $response['status'] < 300 && !empty($json['id'])) {
            return;
        }
        // Même clé déjà utilisée avec un autre contenu (renvoi sans les photos) : déjà accepté.
        $name = strtolower(($json['name'] ?? '') . ' ' . ($json['message'] ?? ''));
        if ($response['status'] === 409 && str_contains($name, 'idempotent') && !str_contains($name, 'concurrent')) {
            return;
        }
        throw new MailerException(
            trim("Resend a refusé l'envoi ({$response['status']} " . ($json['name'] ?? '') . ' ' . ($json['message'] ?? '') . ')'),
            $response['status'] === 429 || $response['status'] >= 500,
        );
    }
}
