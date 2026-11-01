<?php

declare(strict_types=1);

namespace Bwc\Admin;

use Bwc\App;
use Bwc\Database;
use Bwc\Meta;
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
        // Lu dans la base : les tâches planifiées tournent côté site, même si l'administration est installée à part.
        $metaTime = static fn (string $name): ?int => ($value = Meta::get($name)) === null ? null : (int) $value;
        $siteSettings = Meta::get('settings.site');
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
            'lastBackup' => $metaTime('backup.last'),
            'backupCount' => (int) (Meta::get('backup.count') ?? 0),
            'cronNotifications' => $metaTime('cron.notifications'),
            'cronMaintenance' => $metaTime('cron.maintenance'),
            'settingsMatch' => $siteSettings === null || $siteSettings === Meta::settingsFingerprint(),
            'migrationsPending' => Schema::pending($pdo),
            'retention' => $config->retentionMonths(),
            'legalComplete' => !in_array('', array_intersect_key($config->legal(), array_flip(['entity', 'address', 'vat', 'retention'])), true),
        ];
    }
}
