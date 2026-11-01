<?php
/** Enlèvements : liste, filtres, recherche. */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Bwc\Admin\Auth;
use Bwc\Admin\View;
use Bwc\App;
use Bwc\Database;
use Bwc\Stats;

$admin = Auth::requirePage();
$now = time();
$stats = (new Stats(Database::pdo()))->dashboard($now);
$tab = in_array($_GET['tab'] ?? '', ['aujourdhui', 'a-enlever', 'enleves', 'annules'], true) ? $_GET['tab'] : 'tous';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$sort = in_array($_GET['tri'] ?? '', ['date', '-date', 'garage', '-garage'], true) ? $_GET['tri'] : '';
$pageNumber = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

View::render('pickups', [
    'stats' => $stats,
    'result' => App::store()->search(['tab' => $tab, 'q' => $q, 'sort' => $sort ?: null, 'page' => $pageNumber, 'per_page' => $perPage], $stats['today']),
    'tab' => $tab,
    'q' => $q,
    'sort' => $sort,
    'pageNumber' => $pageNumber,
    'perPage' => $perPage,
    'now' => $now,
    'today' => $stats['today'],
], ['active' => 'pickups', 'crumb' => 'Enlèvements', 'title' => 'Enlèvements', 'admin' => $admin]);
