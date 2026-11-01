<?php
/**
 * Tâche quotidienne : sauvegarde de la base (14 dernières gardées dans
 * app/storage/backups), nettoyage, et effacement des données personnelles
 * anciennes si une durée est fixée dans settings.php.
 *
 * hPanel → Avancé → Tâches Cron, une fois par jour (par exemple à 3 h) :
 *   /usr/bin/php /home/u123456789/domains/votre-domaine.be/app/cron/maintenance.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Bwc\App;
use Bwc\Backup;
use Bwc\BookingStore;
use Bwc\Database;
use Bwc\Log;

$pdo = Database::pdo();
$file = Backup::run($pdo, 'quotidienne');
echo 'Sauvegarde : ' . basename($file) . PHP_EOL;

$pdo->prepare('DELETE FROM bwc_rate_limits WHERE reset_at < ?')->execute([time() - 86400]);
$pdo->exec("DELETE FROM bwc_booking_days WHERE day < CURDATE() - INTERVAL 30 DAY");

$months = App::config()->retentionMonths();
if ($months !== null) {
    $limit = date('Y-m-d', strtotime("-$months months"));
    $bookings = $pdo->prepare(
        "UPDATE bwc_bookings SET contact_name = '—', phone = '', email = '', street = '—', comment = '', garage_reference = ''
         WHERE status IN ('completed', 'cancelled') AND slot_date < ? AND street <> '—'",
    );
    $bookings->execute([$limit]);
    $pdo->prepare('UPDATE bwc_notifications n JOIN bwc_bookings b ON b.id = n.booking_id SET n.recipients = \'\' WHERE b.street = \'—\'')->execute();
    // Garages sans enlèvement récent : le nom et la ville restent, pas les coordonnées de personnes.
    $garages = $pdo->prepare(
        "UPDATE bwc_garages g SET contact_name = '', phone = '', email = '', notes = '', updated_at = ?
         WHERE g.email <> '' AND NOT EXISTS (SELECT 1 FROM bwc_bookings b WHERE b.garage_id = g.id AND b.slot_date >= ?)",
    );
    $garages->execute([BookingStore::datetime(time()), $limit]);
    Log::info('maintenance.retention', ['bookings' => $bookings->rowCount(), 'garages' => $garages->rowCount(), 'months' => $months]);
    echo "Données personnelles effacées : {$bookings->rowCount()} enlèvement(s), {$garages->rowCount()} garage(s).\n";
}
