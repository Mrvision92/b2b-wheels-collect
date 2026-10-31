<?php

declare(strict_types=1);

namespace Bwc\Mail;

use Bwc\Booking;
use Bwc\Phone;

/**
 * Emails transactionnels, écrits en HTML à base de tableaux pour s'afficher
 * correctement dans Gmail, Outlook et Apple Mail. Tout texte saisi par un
 * garage est échappé.
 */
final class Templates
{
    private const FONT = "'Mona Sans','Helvetica Neue',Helvetica,Arial,sans-serif";
    private const INK = '#1d1d1f';
    private const MUTED = '#6e6e73';
    private const LINE = '#e3e3e1';
    private const PAPER = '#f8f8f8';

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function nl2br(string $value): string
    {
        return str_replace("\n", '<br>', self::e($value));
    }

    private static function layout(string $title, string $preheader, string $body, string $footer): string
    {
        $font = self::FONT;
        $ink = self::INK;
        $muted = self::MUTED;
        $line = self::LINE;
        $paper = self::PAPER;
        $title = self::e($title);
        $preheader = self::e($preheader);
        return <<<HTML
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>$title</title>
<link href="https://fonts.googleapis.com/css2?family=Mona+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  body{margin:0;padding:0;-webkit-text-size-adjust:100%;}
  a{color:$ink;}
  @media (max-width:600px){
    .card{padding:32px 24px!important;}
    .h1{font-size:26px!important;line-height:31px!important;}
    .when{font-size:21px!important;line-height:27px!important;}
    .btn{display:block!important;width:100%!important;box-sizing:border-box;margin:0 0 10px 0!important;text-align:center!important;}
    .label{display:block!important;width:auto!important;padding:14px 0 2px!important;border:0!important;}
    .value{display:block!important;width:auto!important;padding:0 0 14px!important;}
  }
</style>
</head>
<body style="margin:0;padding:0;background:$paper;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">$preheader&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:$paper;">
<tr><td align="center" style="padding:40px 12px 48px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:580px;">
<tr><td style="padding:0 4px 20px;font-family:$font;font-size:18px;font-weight:600;letter-spacing:-0.3px;color:$ink;">B2B Wheels Collect</td></tr>
<tr><td class="card" style="background:#ffffff;border:1px solid $line;padding:44px 48px;font-family:$font;color:$ink;">
$body
</td></tr>
<tr><td style="padding:22px 4px 0;font-family:$font;font-size:12px;line-height:18px;color:$muted;">$footer</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    /** @param list<array{0: string, 1: string}> $rows [libellé, valeur HTML] */
    private static function detailRows(array $rows): string
    {
        $font = self::FONT;
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid ' . self::LINE . ';margin-top:8px;">' . "\n";
        foreach ($rows as [$label, $value]) {
            $html .= '<tr>' . "\n"
                . '<td class="label" width="38%" valign="top" style="width:38%;padding:13px 16px 13px 0;border-bottom:1px solid ' . self::LINE . ";font-family:$font;font-size:14px;line-height:20px;color:" . self::MUTED . ';">' . self::e($label) . "</td>\n"
                . '<td class="value" valign="top" style="padding:13px 0;border-bottom:1px solid ' . self::LINE . ";font-family:$font;font-size:15px;line-height:21px;color:" . self::INK . ';">' . $value . "</td>\n"
                . '</tr>' . "\n";
        }
        return $html . '</table>';
    }

    private static function button(string $href, string $label, bool $primary): string
    {
        $style = $primary
            ? 'background:' . self::INK . ';color:#ffffff;border:1px solid ' . self::INK . ';'
            : 'background:#ffffff;color:' . self::INK . ';border:1px solid #d2d2d0;';
        return '<a class="btn" href="' . self::e($href) . '" style="' . $style . 'display:inline-block;margin:0 8px 10px 0;padding:12px 20px;border-radius:999px;font-family:' . self::FONT . ';font-size:15px;font-weight:500;line-height:18px;text-decoration:none;">' . self::e($label) . '</a>';
    }

    /** @param array{appUrl: string, contactPhone: string, contactEmail: string} $ctx */
    private static function contactSentence(array $ctx): string
    {
        $phone = $ctx['contactPhone'] !== '' ? Phone::normalize($ctx['contactPhone']) : null;
        return $phone !== null ? ' ou appelez-nous au ' . Phone::format($phone) : '';
    }

    private static function plural(int $count, string $word): string
    {
        return $word . ($count > 1 ? 's' : '');
    }

    /* Confirmation envoyée au garage ------------------------------------- */

    /**
     * @param array<string, mixed> $b
     * @param array{appUrl: string, contactPhone: string, contactEmail: string} $ctx
     * @return array{subject: string, html: string, text: string}
     */
    public static function garageConfirmation(array $b, array $ctx): array
    {
        $d = Booking::describe($b);
        $photoCount = (int) $b['photo_count'];
        $subject = "Confirmation de votre enlèvement — {$b['reference']}";
        $contact = self::contactSentence($ctx);
        $ink = self::INK;
        $muted = self::MUTED;

        $rows = [
            ['Garage', self::e($b['garage_name'])],
            ['Adresse', self::e($d['address'])],
            ['Date', self::e($d['dateLong'])],
            ['Créneau', self::e($d['slotLabel'])],
            ['Nombre de jantes', (string) $b['rim_count']],
            ['Référence', '<strong style="font-weight:600;">' . self::e($b['reference']) . '</strong>'],
        ];
        if ($b['garage_reference'] !== '') {
            $rows[] = ['Votre référence', self::e($b['garage_reference'])];
        }
        if ($b['comment'] !== '') {
            $rows[] = ['Commentaire', self::nl2br($b['comment'])];
        }
        $photosLine = $photoCount > 0 ? $d['photos'] . ' ' . self::plural($photoCount, 'transmise') . " à l'atelier" : '';
        if ($photosLine !== '') {
            $rows[] = ['Photos', self::e($photosLine)];
        }

        $body = '
<p style="margin:0 0 10px;font-size:14px;line-height:20px;color:' . $muted . ';">Enlèvement confirmé</p>
<h1 class="h1" style="margin:0 0 28px;font-size:30px;line-height:36px;font-weight:600;letter-spacing:-0.6px;color:' . $ink . ';">Votre enlèvement est planifié.</h1>
<p style="margin:0 0 14px;font-size:16px;line-height:24px;">Bonjour ' . self::e($b['contact_name']) . ',</p>
<p style="margin:0 0 28px;font-size:16px;line-height:24px;">Votre enlèvement de jantes a bien été planifié. Nous passerons à l\'adresse indiquée ' . self::e($d['slotPhrase']) . '.</p>
<p class="when" style="margin:0;font-size:23px;line-height:29px;font-weight:600;letter-spacing:-0.4px;color:' . $ink . ';">' . self::e($d['dateLong']) . '</p>
<p style="margin:2px 0 24px;font-size:23px;line-height:29px;font-weight:600;letter-spacing:-0.4px;color:' . $muted . ';">' . self::e($d['slotLabel']) . '</p>
' . self::detailRows($rows) . '
<p style="margin:28px 0 0;font-size:15px;line-height:23px;color:' . $ink . ';">Besoin de modifier ou d\'annuler&nbsp;? Répondez simplement à cet email' . self::e($contact) . ', en mentionnant votre référence.</p>
<p style="margin:24px 0 0;font-size:15px;line-height:23px;color:' . $ink . ';">Merci pour votre confiance.<br>L\'équipe B2B Wheels Collect / VBM Wheels Repair</p>';

        $host = parse_url($ctx['appUrl'], PHP_URL_HOST) ?: $ctx['appUrl'];
        $footer = 'Cet email confirme une réservation effectuée sur ' . self::e($host) . '. Vos coordonnées servent uniquement à organiser cet enlèvement. <a href="' . self::e($ctx['appUrl']) . '/#confidentialite" style="color:' . $muted . ';">Confidentialité</a>';

        $text = [
            "Bonjour {$b['contact_name']},",
            '',
            'Votre enlèvement de jantes a bien été planifié.',
            '',
            'Récapitulatif :',
            "Garage : {$b['garage_name']}",
            "Adresse : {$d['address']}",
            "Date : {$d['dateLong']}",
            "Créneau : {$d['slotLabel']}",
            "Nombre de jantes : {$b['rim_count']}",
            "Référence : {$b['reference']}",
        ];
        if ($b['garage_reference'] !== '') {
            $text[] = "Votre référence : {$b['garage_reference']}";
        }
        if ($b['comment'] !== '') {
            $text[] = "Commentaire : {$b['comment']}";
        }
        if ($photosLine !== '') {
            $text[] = "Photos : $photosLine";
        }
        array_push(
            $text,
            '',
            "Besoin de modifier ou d'annuler ? Répondez simplement à cet email$contact, en mentionnant votre référence.",
            '',
            'Merci pour votre confiance.',
            "L'équipe B2B Wheels Collect / VBM Wheels Repair",
        );

        return [
            'subject' => $subject,
            'html' => self::layout($subject, "{$d['dateLong']}, {$d['slotLabel']} — {$d['rims']}", $body, $footer),
            'text' => implode("\n", $text),
        ];
    }

    /* Notification envoyée à VBM ----------------------------------------- */

    /**
     * @param array<string, mixed> $b
     * @param array{appUrl: string, contactPhone: string, contactEmail: string} $ctx
     * @return array{subject: string, html: string, text: string}
     */
    public static function vbmNotification(array $b, array $ctx, bool $photosAttached): array
    {
        $d = Booking::describe($b);
        $photoCount = (int) $b['photo_count'];
        $ink = self::INK;
        $muted = self::MUTED;
        $photosText = '';
        if ($photoCount > 0) {
            $photosText = $photosAttached
                ? $d['photos'] . ' ' . self::plural($photoCount, 'jointe') . ' à cet email'
                : $d['photos'] . ' ' . self::plural($photoCount, 'envoyée') . ' par le garage mais non ' . self::plural($photoCount, 'jointe') . ' à ce renvoi : demandez-les au garage';
        }
        $subject = preg_replace('/[\r\n]+/', ' ', "Nouvel enlèvement — {$b['garage_name']} — {$d['dateLong']}");

        $buttons = self::button($d['mapsUrl'], 'Itinéraire Google Maps', true)
            . self::button("tel:{$b['phone']}", 'Appeler ' . Phone::format($b['phone']), false)
            . self::button("mailto:{$b['email']}?subject=" . rawurlencode("Votre enlèvement {$b['reference']}"), 'Envoyer un email', false)
            . (!empty($b['google_link']) ? self::button($b['google_link'], 'Ouvrir dans Google Agenda', false) : '');

        $rows = [
            ['Garage', self::e($b['garage_name'])],
            ['Contact', self::e($b['contact_name'])],
            ['Téléphone', '<a href="tel:' . self::e($b['phone']) . '" style="color:' . $ink . ';">' . self::e($d['phone']) . '</a>'],
            ['Email', '<a href="mailto:' . self::e($b['email']) . '" style="color:' . $ink . ';">' . self::e($b['email']) . '</a>'],
            ['Adresse', '<a href="' . self::e($d['mapsUrl']) . '" style="color:' . $ink . ';">' . self::e($d['address']) . '</a>'],
            ['Date', self::e($d['dateLong'])],
            ['Créneau', self::e($d['slotLabel'])],
            ['Nombre de jantes', '<strong style="font-weight:600;">' . (int) $b['rim_count'] . '</strong>'],
            ['Commentaire', $b['comment'] !== '' ? self::nl2br($b['comment']) : '<span style="color:' . $muted . ';">Aucun</span>'],
        ];
        if ($photoCount > 0) {
            $rows[] = ['Photos', self::e($photosText)];
        }
        if ($b['garage_reference'] !== '') {
            $rows[] = ['Réf. du garage', self::e($b['garage_reference'])];
        }
        $rows[] = ['Référence', self::e($b['reference'])];

        $garageSent = $b['garage_mail'] === 'sent';
        $warning = $garageSent ? '' : '<p style="margin:0 0 24px;padding:12px 14px;border:1px solid #f0c4c6;background:#fdf5f5;font-size:14px;line-height:20px;color:#9b1c22;">L\'email de confirmation n\'a pas encore pu être envoyé au garage. Un nouvel essai est fait automatiquement&nbsp;; en cas de doute, prévenez le garage par téléphone.</p>';
        $agenda = $b['google_status'] === 'off'
            ? "L'enlèvement est enregistré dans la base de données du site (Google Agenda n'est pas connecté)."
            : "L'enlèvement a été ajouté automatiquement à l'agenda Google de VBM.";

        $body = '
' . $warning . '<p style="margin:0 0 10px;font-size:14px;line-height:20px;color:' . $muted . ';">Nouvel enlèvement · ' . self::e($b['reference']) . '</p>
<h1 class="h1" style="margin:0 0 6px;font-size:30px;line-height:36px;font-weight:600;letter-spacing:-0.6px;color:' . $ink . ';">' . self::e($b['garage_name']) . '</h1>
<p style="margin:0 0 26px;font-size:16px;line-height:24px;color:' . $muted . ';">' . self::e($d['rims']) . ' à enlever</p>
<p class="when" style="margin:0;font-size:23px;line-height:29px;font-weight:600;letter-spacing:-0.4px;">' . self::e($d['dateLong']) . '</p>
<p style="margin:2px 0 26px;font-size:23px;line-height:29px;font-weight:600;letter-spacing:-0.4px;color:' . $muted . ';">' . self::e($d['slotLabel']) . '</p>
<div style="margin:0 0 22px;">' . $buttons . '</div>
' . self::detailRows($rows) . '
<p style="margin:24px 0 0;font-size:13px;line-height:19px;color:' . $muted . ';">' . self::e($agenda) . '</p>';

        $text = ["Nouvel enlèvement — {$b['reference']}", ''];
        if (!$garageSent) {
            array_push($text, "ATTENTION : l'email de confirmation n'a pas encore pu être envoyé au garage (nouvel essai automatique).", '');
        }
        array_push(
            $text,
            "Garage : {$b['garage_name']}",
            "Contact : {$b['contact_name']}",
            "Téléphone : {$d['phone']}",
            "Email : {$b['email']}",
            "Adresse : {$d['address']}",
            "Date : {$d['dateLong']}",
            "Créneau : {$d['slotLabel']}",
            "Nombre de jantes : {$b['rim_count']}",
            'Commentaire : ' . ($b['comment'] !== '' ? $b['comment'] : 'aucun'),
        );
        if ($photoCount > 0) {
            $text[] = "Photos : $photosText";
        }
        if ($b['garage_reference'] !== '') {
            $text[] = "Réf. du garage : {$b['garage_reference']}";
        }
        array_push($text, "Référence : {$b['reference']}", '', "Itinéraire : {$d['mapsUrl']}");
        if (!empty($b['google_link'])) {
            $text[] = "Google Agenda : {$b['google_link']}";
        }

        return [
            'subject' => $subject,
            'html' => self::layout(
                $subject,
                "{$d['dateLong']}, {$d['slotLabel']} — {$d['rims']} — {$d['address']}",
                $body,
                'Notification automatique B2B Wheels Collect. Ne pas transférer : contient des données personnelles.',
            ),
            'text' => implode("\n", $text),
        ];
    }
}
