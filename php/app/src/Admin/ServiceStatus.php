<?php

declare(strict_types=1);

namespace Bwc\Admin;

use Bwc\App;
use Bwc\Backup;
use Bwc\Database;
use Bwc\Schema;

/** État du service, pour savoir d'un coup d'œil si tout fonctionne. */
final class ServiceStatus
{
    /** @return array<string, mixed> */
    public static function collect(): array
    {
        $pdo = Database::pdo();
        $config = App::config();
        $count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        $backups = glob(Backup::dir() . '/bwc-*.sql.gz') ?: [];
        $lastBackup = $backups === [] ? null : max(array_map('filemtime', $backups));
        $lastRun = static function (string $name) use ($config): ?int {
            $value = @file_get_contents($config->storage('cron') . "/$name.last");
            return $value === false ? null : (int) $value;
        };
        return [
            'demo' => $config->demo(),
            'mail' => $config->mailTransport(),
            'mailPending' => $count("SELECT COUNT(*) FROM bwc_notifications WHERE status = 'pending'"),
            'mailFailed' => $count("SELECT COUNT(*) FROM bwc_notifications WHERE status = 'failed'"),
            'mailProblems' => $pdo->query(
                "SELECT n.id, n.kind, n.status, n.attempts, n.last_error, b.id AS booking_id, b.garage_name, b.reference
                 FROM bwc_notifications n JOIN bwc_bookings b ON b.id = n.booking_id
                 WHERE n.status = 'failed' OR (n.status = 'pending' AND n.attempts > 0)
                 ORDER BY n.updated_at DESC LIMIT 8",
            )->fetchAll(),
            'calendar' => App::calendar() !== null,
            'calendarPending' => $count("SELECT COUNT(*) FROM bwc_bookings WHERE google_status = 'pending'"),
            'calendarFailed' => $count("SELECT COUNT(*) FROM bwc_bookings WHERE google_status = 'failed'"),
            'lastBackup' => $lastBackup,
            'backupCount' => count($backups),
            'cronNotifications' => $lastRun('notifications'),
            'cronMaintenance' => $lastRun('maintenance'),
            'migrationsPending' => Schema::pending($pdo),
            'retention' => $config->retentionMonths(),
            'legalComplete' => !in_array('', array_intersect_key($config->legal(), array_flip(['entity', 'address', 'vat', 'retention'])), true),
        ];
    }
}
