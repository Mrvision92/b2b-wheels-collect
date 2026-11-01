<?php
/** Sauvegarde complète de la base, créée à la demande et téléchargée (formulaire POST avec jeton CSRF). */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\Admin\Auth;
use Bwc\Backup;
use Bwc\Database;
use Bwc\Http;
use Bwc\Log;

$admin = Auth::requirePage();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::checkCsrf($_POST['csrf'] ?? null) || !Http::rateLimit('backup:' . $admin['id'], 10, 3600)['ok']) {
    header('Location: compte.php');
    exit;
}
$file = Backup::run(Database::pdo(), 'telechargement');
Log::info('admin.backup_downloaded', ['admin' => $admin['id']]);
header('Content-Type: application/gzip');
header('Content-Length: ' . filesize($file));
header('Content-Disposition: attachment; filename="' . basename($file) . '"');
readfile($file);
