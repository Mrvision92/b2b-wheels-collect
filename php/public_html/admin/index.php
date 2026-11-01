<?php
/** Vue d'ensemble. */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\Admin\Auth;
use Bwc\Admin\View;
use Bwc\App;
use Bwc\Database;
use Bwc\Stats;

$admin = Auth::requirePage();
$now = time();
$stats = (new Stats(Database::pdo()))->dashboard($now, isset($_GET['mois']) ? (string) $_GET['mois'] : null);
$today = $stats['today'];

View::render('dashboard', [
    'stats' => $stats,
    'todayRows' => App::store()->between($today, $today),
    'upcoming' => App::store()->upcoming($now, 4),
    'now' => $now,
], ['active' => 'dashboard', 'crumb' => "Vue d'ensemble", 'title' => "Vue d'ensemble", 'admin' => $admin]);
