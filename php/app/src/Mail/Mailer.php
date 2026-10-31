<?php

declare(strict_types=1);

namespace Bwc\Mail;

/**
 * Envoi d'un email. Un email est un tableau :
 * ['to' => list<string>, 'subject', 'html', 'text', 'reply_to' => ?string,
 *  'attachments' => list<['filename', 'content_type', 'content']>, 'idempotency_key'].
 * La même clé d'idempotence désigne le même email.
 */
interface Mailer
{
    /** "smtp", "resend" ou "demo". */
    public function kind(): string;

    /**
     * @param array<string, mixed> $email
     * @throws MailerException
     */
    public function send(array $email): void;
}
