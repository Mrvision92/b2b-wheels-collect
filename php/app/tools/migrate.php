<?php
/**
 * Installe ou met à jour la base de données, en ligne de commande :
 *
 *   php app/tools/migrate.php
 *
 * Base vide : crée toutes les tables. Base existante : sauvegarde d'abord
 * (app/storage/backups), puis applique les mises à jour manquantes.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Bwc\Backup;
use Bwc\Database;
use Bwc\Schema;

$pdo = Database::pdo();

if (!Schema::isInstalled($pdo)) {
    Schema::install($pdo);
    echo "Base installée : toutes les tables ont été créées.\n";
    exit(0);
}

$pending = Schema::pending($pdo);
if ($pending === []) {
    echo "La base est à jour.\n";
    exit(0);
}

$backup = Backup::run($pdo, 'avant-migration');
echo 'Sauvegarde : ' . $backup . PHP_EOL;
foreach ($pending as $name) {
    try {
        Schema::apply($pdo, $name);
        echo "Appliquée : $name\n";
    } catch (\Throwable $error) {
        fwrite(STDERR, "Échec de $name : " . $error->getMessage() . "\nRestaurez la sauvegarde ci-dessus dans phpMyAdmin avant de réessayer.\n");
        exit(1);
    }
}
echo "La base est à jour.\n";
