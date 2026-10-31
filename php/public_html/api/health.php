<?php
/**
 * GET /api/health.php — état des services, sans aucune donnée sensible.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\App;
use Bwc\Database;
use Bwc\Http;

$database = 'ok';
try {
    Database::pdo()->query('SELECT 1 FROM bwc_bookings LIMIT 1');
} catch (\Throwable) {
    $database = 'error';
}

$config = App::config();
Http::json([
    'ok' => $database === 'ok',
    'database' => $database,
    'calendar' => $config->googleEnabled() ? 'google' : 'off',
    'email' => $config->mailTransport(),
    'demo' => $config->demo(),
], $database === 'ok' ? 200 : 503);
