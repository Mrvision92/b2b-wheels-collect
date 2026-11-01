<?php
/** Actions de l'administration (JSON), voir app/src/Admin/Api.php. */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\Admin\Api;
use Bwc\Admin\Auth;
use Bwc\Http;

Http::securityHeaders();
header('X-Robots-Tag: noindex, nofollow');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true) || !Http::isSameOrigin()) {
    Http::json(['error' => 'forbidden', 'message' => 'Requête refusée.'], 403);
    exit;
}
$admin = Auth::requireApi();
(new Api($admin))->handle($_SERVER['REQUEST_METHOD'], (string) ($_GET['action'] ?? ''));
