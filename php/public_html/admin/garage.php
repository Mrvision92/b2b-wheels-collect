<?php
/** Fiche d'un garage. */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\Admin\Auth;
use Bwc\Admin\View;
use Bwc\App;
use Bwc\Time;

$admin = Auth::requirePage();
$garage = App::garages()->find((int) ($_GET['id'] ?? 0));
if ($garage === null) {
    http_response_code(404);
    header('Location: garages.php');
    exit;
}
$now = time();

View::render('garage', [
    'garage' => $garage,
    'activity' => App::garages()->activity($garage['id']),
    'bookings' => App::store()->forGarage($garage['id']),
    'variants' => App::garages()->variants($garage),
    'now' => $now,
    'today' => Time::dateOf($now),
], ['active' => 'garages', 'crumb' => 'Garages clients', 'title' => $garage['name'], 'admin' => $admin]);
