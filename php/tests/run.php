<?php
/**
 * Tests de la version PHP, sans dépendance : php php/tests/run.php
 * Nécessite la base MySQL de test (voir README, « Tests »).
 */

declare(strict_types=1);

putenv('BWC_CONFIG_FILE=' . __DIR__ . '/config.test.php');
putenv('BWC_STORAGE_DIR=' . dirname(__DIR__, 2) . '/.data/php-tests');

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/support.php';

$filter = $argv[1] ?? '';
foreach (['unit', 'booking', 'http'] as $suite) {
    require __DIR__ . "/$suite.php";
}

$passed = 0;
$failed = 0;
foreach (Tests::$all as [$name, $fn]) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    try {
        $fn();
        $passed++;
        echo "  ✓ $name\n";
    } catch (\Throwable $error) {
        $failed++;
        echo "  ✗ $name\n      " . get_class($error) . ': ' . $error->getMessage() . "\n      " . basename($error->getFile()) . ':' . $error->getLine() . "\n";
    }
}
Tests::stopServers();
echo "\n$passed réussi(s), $failed échec(s)\n";
exit($failed === 0 ? 0 : 1);
