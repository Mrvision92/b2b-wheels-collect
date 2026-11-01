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
Bwc\Schema::dropAll($pdo);
Bwc\Schema::install($pdo);
// Compte de test des tests navigateur (base de test uniquement).
$pdo->prepare('INSERT INTO bwc_admins (email, name, password_hash, created_at, password_changed_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
    ->execute(['admin@test.local', 'Équipe VBM', password_hash('Test-admin-2026!', PASSWORD_DEFAULT)]);
foreach (glob(Config::get()->storage('demo-emails') . '/*.json') ?: [] as $email) {
    unlink($email);
}
echo "Base de test remise à zéro.\n";
