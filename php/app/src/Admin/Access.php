<?php

declare(strict_types=1);

namespace Bwc\Admin;

use Bwc\App;
use Bwc\Log;
use Bwc\Mail\Templates;

/** Liens à usage unique pour choisir un mot de passe : invitation (72 h) ou oubli (1 h). */
final class Access
{
    public const INVITATION_HOURS = 72;
    public const RESET_HOURS = 1;

    public static function link(string $token): string
    {
        return App::config()->appUrl() . '/admin/mot-de-passe.php?jeton=' . rawurlencode($token);
    }

    /**
     * Crée le lien et l'envoie par email.
     *
     * @param array<string, mixed> $admin
     * @return string le jeton (montré seulement en mode démonstration, où aucun email ne part)
     * @throws \Bwc\Mail\MailerException si l'email n'a pas pu partir
     */
    public static function send(array $admin, bool $invitation): string
    {
        $hours = $invitation ? self::INVITATION_HOURS : self::RESET_HOURS;
        $token = Auth::store()->issueToken($admin['id'], $hours);
        $link = self::link($token);
        App::mailer()->send(Templates::adminAccess($admin['name'], $link, $invitation, $hours) + [
            'to' => [$admin['email']],
            'attachments' => [],
            'idempotency_key' => 'bwc/admin-access/' . $admin['id'] . '/' . bin2hex(random_bytes(6)),
        ]);
        Log::info($invitation ? 'admin.invited' : 'admin.reset_requested', ['admin' => $admin['id']]);
        return $token;
    }
}
