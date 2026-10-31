<?php
/**
 * Vérifie la configuration, en ligne de commande (terminal SSH Hostinger) :
 *
 *   php app/tools/check.php                       base de données, Google Agenda, emails
 *   php app/tools/check.php --send-test=vous@x.be envoie aussi un email de test
 *
 * N'affiche jamais de mot de passe ni de clé.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Bwc\App;
use Bwc\Database;
use Bwc\Time;

$failures = 0;
$report = static function (bool $ok, string $label, string $detail = '') use (&$failures): void {
    $failures += $ok ? 0 : 1;
    echo ($ok ? '  OK  ' : '  !!  ') . $label . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
};

$config = App::config();
echo PHP_EOL . 'B2B Wheels Collect — vérification' . PHP_EOL . PHP_EOL;

$report(PHP_VERSION_ID >= 80100, 'PHP ' . PHP_VERSION);
foreach (['pdo_mysql', 'curl', 'openssl', 'mbstring'] as $extension) {
    $report(extension_loaded($extension), "Extension $extension");
}
$report($config->isConfigured(), 'Fichier app/config.php', $config->isConfigured() ? '' : 'copiez config.example.php en config.php');
$report(is_writable($config->storage('logs')), 'Dossier app/storage accessible en écriture');

try {
    $config->secret();
    $report(true, 'Clé secrète (app_secret)', $config->demo() ? 'mode démonstration' : '');
} catch (\RuntimeException $error) {
    $report(false, 'Clé secrète (app_secret)', $error->getMessage());
}

try {
    $count = (int) Database::pdo()->query('SELECT COUNT(*) FROM bwc_bookings')->fetchColumn();
    Database::pdo()->query('SELECT 1 FROM bwc_booking_days LIMIT 1');
    Database::pdo()->query('SELECT 1 FROM bwc_rate_limits LIMIT 1');
    $report(true, 'Base de données MySQL', "$count réservation(s)");
} catch (\Throwable $error) {
    $report(false, 'Base de données MySQL', $error->getMessage() . ' (avez-vous importé database/schema.sql ?)');
}

$calendar = App::calendar();
if ($calendar === null) {
    $report(true, 'Google Agenda', $config->demo() ? 'non utilisé en mode démonstration' : 'non connecté (facultatif)');
} else {
    try {
        $now = time();
        $events = $calendar->listEvents($now, $now + 7 * 86400);
        $report(true, 'Google Agenda', count($events) . ' événement(s) sur les 7 prochains jours');
    } catch (\Throwable $error) {
        $report(false, 'Google Agenda', $error->getMessage());
    }
}

$mail = $config->mail();
$report(true, 'Envoi des emails', match ($mail['mode']) {
    'smtp' => "SMTP {$mail['smtp']['host']}:{$mail['smtp']['port']} ({$mail['smtp']['encryption']})",
    'resend' => 'Resend',
    default => 'simulé (mode démonstration)',
});
$report($mail['vbm_recipients'] !== [], 'Destinataires VBM', implode(', ', $mail['vbm_recipients']));

$options = getopt('', ['send-test:']);
if (!empty($options['send-test'])) {
    try {
        App::mailer()->send([
            'to' => [$options['send-test']],
            'subject' => 'Test B2B Wheels Collect',
            'html' => '<p>Cet email de test confirme que l\'envoi fonctionne.</p>',
            'text' => "Cet email de test confirme que l'envoi fonctionne.",
            'attachments' => [],
            'idempotency_key' => 'bwc/test/' . bin2hex(random_bytes(8)),
        ]);
        $report(true, "Email de test envoyé à {$options['send-test']}");
    } catch (\Throwable $error) {
        $report(false, 'Email de test', $error->getMessage());
    }
}

echo PHP_EOL . ($failures === 0 ? 'Tout est prêt.' : "$failures point(s) à corriger.") . PHP_EOL . PHP_EOL;
exit($failures === 0 ? 0 : 1);
