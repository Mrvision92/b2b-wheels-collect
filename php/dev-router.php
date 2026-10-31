<?php
/**
 * Routeur du serveur PHP intégré, pour essayer le site en local uniquement :
 *   php -S localhost:8080 -t public_html dev-router.php
 * (en ligne, c'est public_html/.htaccess qui protège les mêmes fichiers).
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#/[._]#', $path) === 1) {
    http_response_code(404);
    return true;
}
return false;
