<?php

declare(strict_types=1);

namespace Bwc\Admin;

use Bwc\App;
use Bwc\Booking;
use Bwc\Phone;
use Bwc\Time;

/** Gabarit et petits outils d'affichage de l'administration. */
final class View
{
    public const NAV = [
        'dashboard' => ['index.php', "Vue d'ensemble", 'Accueil', 'grid', 'home'],
        'pickups' => ['enlevements.php', 'Enlèvements', 'Enlèvements', 'box', 'truck'],
        'garages' => ['garages.php', 'Garages clients', 'Garages', 'users', 'users'],
        'planning' => ['planning.php', 'Planning', 'Planning', 'calendar', 'calendar'],
    ];

    private const SHORT_MONTHS = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    private const SHORT_DAYS = [1 => 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    private const DAYS = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    private const ICONS = [
        'grid' => '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
        'home' => '<path d="M4 10.5 12 4l8 6.5V19a1 1 0 0 1-1 1h-4.5v-5.5h-5V20H5a1 1 0 0 1-1-1z"/>',
        'box' => '<path d="M12 3 20 7.5v9L12 21l-8-4.5v-9z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
        'truck' => '<path d="M2.5 6.5h11v9h-11zM13.5 9.5h4l3 3v3h-7z"/><circle cx="6.5" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><path d="M15.5 5.2a3 3 0 0 1 0 5.6M17.2 14.3c1.8.6 3 2.2 3.3 4.7"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/>',
        'bell' => '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 1.5h-15z"/><path d="M10 20.5a2.2 2.2 0 0 0 4 0"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'check-circle' => '<circle cx="12" cy="12" r="8.5"/><path d="m8.5 12.2 2.4 2.4 4.8-5"/>',
        'bars' => '<path d="M6 19v-6M10.7 19V8M15.3 19v-9M20 19v-4M3.5 19.5h17"/>',
        'trend' => '<path d="m3.5 16.5 6-6 4 4 7-7"/><path d="M15 7.5h5.5V13"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'arrow' => '<path d="M7 17 17 7M9 7h8v8"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6"/><path d="m15 15 5 5"/>',
        'phone' => '<path d="M5 4.5h3.5l1.7 4.2-2.2 1.4a11 11 0 0 0 5.9 5.9l1.4-2.2 4.2 1.7V19a1.5 1.5 0 0 1-1.6 1.5C10.5 20 4 13.5 3.5 6.1A1.5 1.5 0 0 1 5 4.5z"/>',
        'mail' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="m4 7 8 6 8-6"/>',
        'pin' => '<path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'chevron-left' => '<path d="M14.5 5.5 8 12l6.5 6.5"/>',
        'chevron-right' => '<path d="M9.5 5.5 16 12l-6.5 6.5"/>',
        'sliders' => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
        'download' => '<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19.5h14"/>',
        'user' => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c.8-3.8 3.6-6 7-6s6.2 2.2 7 6"/>',
        'logout' => '<path d="M14 4.5H6.5A1.5 1.5 0 0 0 5 6v12a1.5 1.5 0 0 0 1.5 1.5H14"/><path d="M10 12h10M16.5 8.5 20 12l-3.5 3.5"/>',
        'refresh' => '<path d="M19.5 12a7.5 7.5 0 1 1-2.2-5.3M19.5 4.5v4h-4"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M3 3l18 18M10.6 6a9.6 9.6 0 0 1 1.4-.1c6 0 9.5 6.1 9.5 6.1a17 17 0 0 1-2.7 3.4M6.3 7.4A16.6 16.6 0 0 0 2.5 12S6 18.5 12 18.5a9 9 0 0 0 4.3-1.1M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'camera' => '<path d="M4 8.5h3l1.5-2.5h7L17 8.5h3a1 1 0 0 1 1 1V18a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.3" r="3.3"/>',
    ];

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function icon(string $name, string $class = 'icon'): string
    {
        return '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . (self::ICONS[$name] ?? '') . '</svg>';
    }

    public static function logo(string $class = 'logo-mark'): string
    {
        return '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10" stroke-width="2"/><circle cx="12" cy="12" r="3.1" stroke-width="1.8"/><path stroke-width="1.6" stroke-linecap="round" d="M12 8.9V2.6M15 11 21 9M13.8 14.5l3.8 5.2M10.2 14.5l-3.8 5.2M9 11 3 9"/></svg>';
    }

    /** Fichier statique avec sa date de modification, pour forcer le rechargement après une mise à jour. */
    public static function asset(string $path): string
    {
        // Les pages de l'administration sont toutes dans le même dossier que assets/.
        return self::e($path) . '?v=' . (@filemtime(dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) . '/' . $path) ?: '1');
    }

    public static function initials(string $name): string
    {
        $words = preg_split('/[\s\-]+/u', trim($name)) ?: [];
        $letters = array_map(static fn (string $w): string => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(array_filter($words), 0, 2));
        if (mb_strtoupper(trim($name)) === 'ÉQUIPE VBM' || str_contains(mb_strtolower($name), 'vbm')) {
            return 'VB';
        }
        return implode('', $letters) ?: '?';
    }

    /* Dates et heures --------------------------------------------------- */

    /** « 30 oct. 2026 » */
    public static function dateShort(string $date): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return $d . ' ' . self::SHORT_MONTHS[$m] . ' ' . $y;
    }

    /** « Vendredi 30 octobre 2026 » */
    public static function dateLong(string $date): string
    {
        return Time::capitalize(Time::formatDateLong($date));
    }

    /** « Vendredi 30 octobre » */
    public static function dayMonth(string $date): string
    {
        return Time::capitalize(Time::formatDayMonth($date));
    }

    /** « Lun » */
    public static function shortDay(string $date): string
    {
        return self::SHORT_DAYS[Time::isoWeekday($date)];
    }

    /** « Lundi 26 » */
    public static function dayNumber(string $date): string
    {
        return self::DAYS[Time::isoWeekday($date)] . ' ' . (int) substr($date, 8, 2);
    }

    /** « 09:00 – 11:00 » */
    public static function range(array $b): string
    {
        return $b['start_time'] . ' – ' . $b['end_time'];
    }

    /** « Aujourd'hui », « Demain » ou « Lun. 2 nov. » */
    public static function relativeDay(string $date, string $today): string
    {
        if ($date === $today) {
            return "Aujourd'hui";
        }
        if ($date === Time::addDays($today, 1)) {
            return 'Demain';
        }
        [, $m, $d] = array_map('intval', explode('-', $date));
        return self::SHORT_DAYS[Time::isoWeekday($date)] . '. ' . $d . ' ' . self::SHORT_MONTHS[$m];
    }

    /** Instant UTC de la base → « 30 oct. 2026 à 14:05 » (heure de Bruxelles) */
    public static function datetime(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $ts = (int) strtotime($utc . ' UTC');
        return self::dateShort(Time::dateOf($ts)) . ' à ' . Time::timeOf($ts);
    }

    public static function rims(int $count): string
    {
        return Booking::rimsLabel($count);
    }

    public static function phone(string $e164): string
    {
        return $e164 !== '' ? Phone::format($e164) : '';
    }

    /** Téléphone pour Excel : « 0470 12 34 56 », ou « 0033… » à l'étranger (un « + » serait lu comme une formule). */
    public static function phoneForExport(string $e164): string
    {
        if ($e164 === '') {
            return '';
        }
        return str_starts_with($e164, '+32') ? '0' . substr(Phone::format($e164), 4) : '00' . substr($e164, 1);
    }

    public static function mapsUrl(array $place): string
    {
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode("{$place['street']}, {$place['postal_code']} {$place['city']}, Belgique");
    }

    /** Champ mot de passe avec le bouton « œil » pour afficher ce qui est tapé. */
    public static function passwordField(string $id, string $name, string $autocomplete, string $attrs = ''): string
    {
        return '<div class="password-field"><input id="' . self::e($id) . '" name="' . self::e($name) . '" type="password" class="input" autocomplete="' . self::e($autocomplete) . '" ' . $attrs . '>'
            . '<button type="button" class="password-toggle" aria-label="Afficher le mot de passe" aria-pressed="false" aria-controls="' . self::e($id) . '">'
            . self::icon('eye', 'icon icon-show') . self::icon('eye-off', 'icon icon-hide') . '</button></div>';
    }

    /** Petit appareil photo et nombre de photos, à côté du nom du garage. */
    public static function photoBadge(array $b): string
    {
        $count = (int) $b['photo_count'];
        return $count > 0
            ? ' <span class="photo-badge" title="' . $count . ' photo' . ($count > 1 ? 's' : '') . ' du garage">' . self::icon('camera', 'icon') . $count . '<span class="sr-only"> photo' . ($count > 1 ? 's' : '') . '</span></span>'
            : '';
    }

    /* Statuts ------------------------------------------------------------ */

    public static function isOverdue(array $b, int $now): bool
    {
        return in_array($b['status'], ['pending', 'confirmed'], true) && $b['end_ts'] <= $now && $b['slot_date'] < Time::dateOf($now);
    }

    public static function statusLabel(string $status): string
    {
        return ['pending' => 'En attente', 'confirmed' => 'À enlever', 'completed' => 'Enlevé', 'cancelled' => 'Annulé'][$status] ?? $status;
    }

    public static function pill(array $b, ?int $now = null): string
    {
        $status = $b['status'];
        $overdue = $now !== null && self::isOverdue($b, $now);
        $class = $overdue ? 'late' : $status;
        $label = $overdue ? 'En retard' : self::statusLabel($status);
        return '<span class="pill pill-' . $class . '"><span class="pill-dot" aria-hidden="true"></span>' . $label . '</span>';
    }

    /* Gabarit -------------------------------------------------------------- */

    /**
     * Affiche une vue de app/views/admin dans le gabarit de l'administration.
     *
     * @param array<string, mixed> $vars variables de la vue
     * @param array{active: string, crumb: string, title: string, admin: array<string, mixed>, mobileTitle?: bool} $page
     */
    public static function render(string $template, array $vars, array $page): void
    {
        self::page(self::capture($template, $vars), $page);
    }

    /** Contenu déjà prêt, affiché dans le gabarit. */
    public static function page(string $content, array $page): void
    {
        $csrf = Auth::csrf();
        $config = App::config();
        $now = time();
        $latest = App::store()->latestReceived(8);
        require BWC_APP_DIR . '/views/admin/_layout.php';
    }

    /** Vue rendue avec ses seules variables (aucun conflit avec celles du gabarit). */
    private static function capture(string $__template, array $__vars): string
    {
        extract($__vars);
        ob_start();
        require BWC_APP_DIR . "/views/admin/$__template.php";
        return (string) ob_get_clean();
    }

    /** En-tête de page : surtitre, titre, sous-titre et bouton « Nouvel enlèvement ». */
    public static function pageHeader(string $eyebrow, string $title, string $subtitle, bool $newButton = true, string $mobileTitle = '', string $mobileSubtitle = ''): string
    {
        $both = static fn (string $desktop, string $mobile): string => $mobile !== ''
            ? '<span class="desktop-only">' . self::e($desktop) . '</span><span class="mobile-only">' . self::e($mobile) . '</span>'
            : self::e($desktop);
        $html = '<div class="page-head"><div>';
        $html .= $eyebrow !== '' ? '<p class="eyebrow">' . self::e($eyebrow) . '</p>' : '';
        $html .= '<h1 class="page-title">' . $both($title, $mobileTitle) . '</h1>';
        $html .= '<p class="page-subtitle">' . $both($subtitle, $mobileSubtitle) . '</p></div>';
        if ($newButton) {
            $html .= '<button type="button" class="btn btn-primary btn-new" data-new-booking>' . self::icon('plus') . 'Nouvel enlèvement</button>';
        }
        return $html . '</div>';
    }

    /** Carte de chiffre clé ($caption est déjà échappée) ; avec $href, elle mène à la liste correspondante. */
    public static function kpi(string $label, string $icon, string $value, string $caption, string $extraClass = '', string $mobileLabel = '', string $href = ''): string
    {
        $title = $mobileLabel !== ''
            ? '<span class="desktop-only">' . self::e($label) . '</span><span class="mobile-only">' . self::e($mobileLabel) . '</span>'
            : self::e($label);
        $tag = $href !== '' ? 'a href="' . self::e($href) . '"' : 'div';
        return '<' . $tag . ' class="kpi ' . ($href !== '' ? 'kpi-link ' : '') . $extraClass . '"><div class="kpi-head"><span>' . $title . '</span>' . self::icon($icon) . '</div>'
            . '<p class="kpi-value">' . self::e($value) . '</p><p class="kpi-caption">' . $caption . '</p></' . ($href !== '' ? 'a' : 'div') . '>';
    }

    /** Nombre sur deux chiffres, comme sur les maquettes : « 04 ». */
    public static function count(int $value): string
    {
        return $value < 10 ? sprintf('%02d', $value) : (string) $value;
    }
}
