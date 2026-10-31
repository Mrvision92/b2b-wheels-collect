<?php

declare(strict_types=1);

namespace Bwc\Mail;

/** Construction du message MIME (texte + HTML + pièces jointes) pour l'envoi SMTP. */
final class Mime
{
    /** « Nom <adresse> » → [nom, adresse]. */
    public static function parseAddress(string $value): array
    {
        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $value, $m) === 1) {
            return [trim($m[1], " \""), trim($m[2])];
        }
        return ['', trim($value)];
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) === 1 ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n") : $value;
    }

    private static function formatAddress(string $value): string
    {
        [$name, $address] = self::parseAddress($value);
        if ($name === '') {
            return $address;
        }
        $encoded = preg_match('/[^\x20-\x7E]/', $name) === 1 ? self::encodeHeader($name) : '"' . addcslashes($name, '"\\') . '"';
        return "$encoded <$address>";
    }

    /** @param array<string, mixed> $email */
    public static function build(array $email, string $from): string
    {
        [, $fromAddress] = self::parseAddress($from);
        $domain = substr(strrchr($fromAddress, '@') ?: '@localhost', 1);
        $seed = hash('sha256', $email['idempotency_key'] . microtime());
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . self::formatAddress($from),
            'To: ' . implode(', ', array_map([self::class, 'formatAddress'], $email['to'])),
            'Subject: ' . self::encodeHeader($email['subject']),
            'Message-ID: <' . substr($seed, 0, 32) . '@' . $domain . '>',
            'MIME-Version: 1.0',
        ];
        if (!empty($email['reply_to'])) {
            $headers[] = 'Reply-To: ' . self::formatAddress($email['reply_to']);
        }

        $alternative = 'alt-' . substr($seed, 32, 16);
        $body = "--$alternative\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(str_replace("\n", "\r\n", $email['text'])) . "\r\n"
            . "--$alternative\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(str_replace("\n", "\r\n", $email['html'])) . "\r\n"
            . "--$alternative--\r\n";

        $attachments = $email['attachments'] ?? [];
        if ($attachments === []) {
            $headers[] = "Content-Type: multipart/alternative; boundary=\"$alternative\"";
            return implode("\r\n", $headers) . "\r\n\r\n" . $body;
        }

        $mixed = 'mix-' . substr($seed, 48, 16);
        $headers[] = "Content-Type: multipart/mixed; boundary=\"$mixed\"";
        $message = "--$mixed\r\nContent-Type: multipart/alternative; boundary=\"$alternative\"\r\n\r\n$body";
        foreach ($attachments as $file) {
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['filename']);
            $message .= "--$mixed\r\n"
                . "Content-Type: {$file['content_type']}; name=\"$name\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n"
                . chunk_split(base64_encode($file['content']), 76, "\r\n");
        }
        $message .= "--$mixed--\r\n";
        return implode("\r\n", $headers) . "\r\n\r\n" . $message;
    }
}
