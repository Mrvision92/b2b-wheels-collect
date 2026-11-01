<?php
/** Export CSV (Excel) : enlèvements (mêmes filtres que la page) ou tous les garages clients (?quoi=garages). */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Bwc\Admin\Auth;
use Bwc\Admin\View;
use Bwc\App;
use Bwc\Time;

Auth::requirePage();
header('Content-Type: text/csv; charset=utf-8');
$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF"); // Excel reconnaît l'UTF-8
// Une cellule commençant par = + - @ serait lue comme une formule par Excel.
$cell = static fn ($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . $v : (string) $v;
$write = static fn (array $row) => fputcsv($out, array_map($cell, $row), ';', '"', '');
$day = static fn (?string $utc): string => $utc === null ? '' : Time::dateOf((int) strtotime($utc . ' UTC'));

if (($_GET['quoi'] ?? '') === 'garages') {
    // Tous les garages clients, avec leur activité.
    header('Content-Disposition: attachment; filename="garages-clients-' . date('Y-m-d') . '.csv"');
    $write(['Garage', 'Contact', 'Téléphone', 'Email', 'Rue', 'Code postal', 'Ville', 'Réservations', 'Enlèvements effectués', 'Jantes récupérées', 'Dernier enlèvement', 'Prochain enlèvement', 'Client depuis', 'Notes']);
    foreach (App::garages()->search(mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100), 1, 100000)['rows'] as $g) {
        $write([
            $g['name'], $g['contact_name'], View::phoneForExport($g['phone']), $g['email'], $g['street'], $g['postal_code'], $g['city'],
            (int) $g['bookings_total'], (int) $g['completed_total'], (int) $g['rims_total'], $day($g['last_completed_at']), $day($g['next_starts_at']),
            $day($g['created_at']), $g['notes'],
        ]);
    }
    fclose($out);
    exit;
}

$tab = in_array($_GET['tab'] ?? '', ['aujourdhui', 'a-enlever', 'enleves', 'annules'], true) ? $_GET['tab'] : 'tous';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
header('Content-Disposition: attachment; filename="enlevements-' . date('Y-m-d') . '.csv"');
$write(['Référence', 'Statut', 'Date', 'Créneau', 'Garage', 'Contact', 'Téléphone', 'Email', 'Rue', 'Code postal', 'Ville', 'Jantes', 'Réf. du garage', 'Instructions', 'Note interne', 'Photos', 'Reçue le', 'Enlevé le']);
foreach (App::store()->search(['tab' => $tab, 'q' => $q, 'sort' => 'date', 'per_page' => 100000], Time::dateOf(time()))['rows'] as $b) {
    $write([
        $b['reference'], View::statusLabel($b['status']), $b['slot_date'], View::range($b), $b['garage_name'], $b['contact_name'],
        View::phoneForExport($b['phone']), $b['email'], $b['street'], $b['postal_code'], $b['city'], $b['rim_count'], $b['garage_reference'],
        $b['comment'], $b['internal_note'], $b['photo_count'], View::datetime($b['created_at']), View::datetime($b['completed_at']),
    ]);
}
fclose($out);
