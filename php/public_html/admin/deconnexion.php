<?php
/** Déconnexion (formulaire POST avec jeton CSRF). */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Bwc\Admin\Auth;

Auth::headers();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::checkCsrf($_POST['csrf'] ?? null)) {
    Auth::logout();
}
header('Location: connexion.php');
