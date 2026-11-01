<?php
/** Export CSV de la liste des enlèvements (mêmes filtres que la page). */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\Admin\Auth;
use Bwc\Admin\View;
use Bwc\App;
use Bwc\Time;

Auth::requirePage();
$tab = in_array($_GET['tab'] ?? '', ['aujourdhui', 'a-enlever', 'enleves', 'annules'], true) ? $_GET['tab'] : 'tous';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$rows = App::store()->search(['tab' => $tab, 'q' => $q, 'sort' => 'date', 'per_page' => 5000], Time::dateOf(time()))['rows'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="enlevements-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF"); // Excel reconnaît l'UTF-8
// Une cellule commençant par = + - @ serait lue comme une formule par Excel.
$cell = static fn ($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . $v : (string) $v;
fputcsv($out, ['Référence', 'Statut', 'Date', 'Créneau', 'Garage', 'Contact', 'Téléphone', 'Email', 'Rue', 'Code postal', 'Ville', 'Jantes', 'Réf. du garage', 'Instructions', 'Reçue le', 'Enlevé le'], ';', '"', '');
foreach ($rows as $b) {
    fputcsv($out, array_map($cell, [
        $b['reference'], View::statusLabel($b['status']), $b['slot_date'], View::range($b), $b['garage_name'], $b['contact_name'],
        View::phone($b['phone']), $b['email'], $b['street'], $b['postal_code'], $b['city'], $b['rim_count'], $b['garage_reference'],
        $b['comment'], View::datetime($b['created_at']), View::datetime($b['completed_at']),
    ]), ';', '"', '');
}
fclose($out);
