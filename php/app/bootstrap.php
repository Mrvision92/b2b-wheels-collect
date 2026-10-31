<?php
/**
 * Point d'entrée commun : réglages PHP, chargement automatique des classes
 * (dossier src/, espace de noms Bwc\) et journaux hors de public_html.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('B2B Wheels Collect nécessite PHP 8.1 ou plus récent.');
}

define('BWC_APP_DIR', __DIR__);

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(__DIR__ . '/storage/logs') || @mkdir(__DIR__ . '/storage/logs', 0750, true)) {
    ini_set('error_log', __DIR__ . '/storage/logs/php-errors.log');
}

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Bwc\\', 4) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
