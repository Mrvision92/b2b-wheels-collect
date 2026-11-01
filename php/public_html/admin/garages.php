<?php
/** Garages clients. */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Bwc\Admin\Auth;
use Bwc\Admin\View;
use Bwc\App;

$admin = Auth::requirePage();
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$pageNumber = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

View::render('garages', [
    'result' => App::garages()->search($q, $pageNumber, $perPage),
    'q' => $q,
    'pageNumber' => $pageNumber,
    'perPage' => $perPage,
], ['active' => 'garages', 'crumb' => 'Garages clients', 'title' => 'Garages clients', 'admin' => $admin]);
