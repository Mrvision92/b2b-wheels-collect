<?php
/**
 * Remet la base de test à zéro avant les tests navigateur. Refuse de tourner
 * avec une autre configuration que php/tests/config.test.php.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Bwc\Config;
use Bwc\Database;

$file = (string) getenv('BWC_CONFIG_FILE');
if (realpath($file) !== realpath(__DIR__ . '/config.test.php') || !Config::get()->demo()) {
    fwrite(STDERR, "Refusé : réservé à la base de test (BWC_CONFIG_FILE=php/tests/config.test.php).\n");
    exit(1);
}

$pdo = Database::pdo();
foreach (array_filter(array_map('trim', explode(';', (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql')))) as $statement) {
    if (preg_match('/^(CREATE|SET)/m', $statement) === 1) {
        $pdo->exec($statement);
    }
}
foreach (['bwc_bookings', 'bwc_booking_days', 'bwc_rate_limits'] as $table) {
    $pdo->exec("TRUNCATE TABLE $table");
}
foreach (glob(Config::get()->storage('demo-emails') . '/*.json') ?: [] as $email) {
    unlink($email);
}
echo "Base de test remise à zéro.\n";
