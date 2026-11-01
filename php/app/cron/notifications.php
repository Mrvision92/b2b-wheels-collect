<?php
/**
 * Tâche planifiée : reprend les emails et les copies Google Agenda restés en
 * attente (service momentanément indisponible).
 *
 * hPanel → Avancé → Tâches Cron, toutes les 15 minutes (* /15 * * * *) :
 *   /usr/bin/php /home/u123456789/domains/votre-domaine.be/app/cron/notifications.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

// Dernière exécution, affichée dans l'administration (Mon compte → État du service).
Bwc\Meta::set('cron.notifications', (string) time());
// Réglages de créneaux du site, comparés à ceux de l'administration.
Bwc\Meta::set('settings.site', Bwc\Meta::settingsFingerprint());

$summary = Bwc\App::notifications()->retryPending(50);
if ($summary['emails'] + $summary['calendar'] > 0) {
    Bwc\Log::info('cron.notifications', $summary);
}
echo json_encode($summary), PHP_EOL;
