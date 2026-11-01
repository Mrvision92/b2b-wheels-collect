<?php
/**
 * Photo d'une réservation, réservée aux administrateurs connectés. Les
 * fichiers restent hors de public_html : ils ne sont jamais accessibles
 * par une adresse directe.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\Admin\Auth;
use Bwc\App;
use Bwc\PhotoStore;

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
if (Auth::current() === null) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Connexion requise.');
}

$photo = App::photos()->find((int) ($_GET['id'] ?? 0));
$path = $photo !== null ? App::photos()->path($photo) : null;
if ($photo === null || $path === null || !isset(PhotoStore::TYPES[$photo['content_type']])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Photo introuvable.');
}

$booking = App::store()->find((int) $photo['booking_id']);
$name = ($booking['reference'] ?? 'enlevement') . '-' . $photo['file_name'];
header('Content-Type: ' . $photo['content_type']);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
header('Content-Disposition: ' . (isset($_GET['telecharger']) ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
readfile($path);
