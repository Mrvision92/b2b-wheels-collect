<?php
/**
 * GET /api/availability.php?month=AAAA-MM
 * Créneaux libres d'un mois pour le calendrier du formulaire.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\App;
use Bwc\Availability;
use Bwc\Calendar\CalendarUnavailable;
use Bwc\Http;
use Bwc\Log;
use Bwc\Schedule;
use Bwc\Time;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Http::json(['error' => 'method_not_allowed'], 405, ['Allow' => 'GET']);
    exit;
}

try {
    $limit = Http::rateLimit('availability:' . Http::clientIp(), 120, 60);
    if (!$limit['ok']) {
        Http::json(['error' => 'rate_limited', 'message' => 'Trop de demandes. Patientez quelques secondes.'], 429, ['Retry-After' => (string) $limit['retryAfter']]);
        exit;
    }

    $settings = App::config()->booking();
    $now = time();
    $window = Schedule::window($now, $settings);
    $first = substr($window['today'], 0, 7);
    $last = substr($window['lastDate'], 0, 7);
    $month = $_GET['month'] ?? $first;
    if (!Time::isValidMonth($month)) {
        $month = $first;
    }
    $month = max($first, min($last, $month));

    Http::json(Availability::month($month, $settings, App::store(), App::calendar(), $now));
} catch (CalendarUnavailable $error) {
    Log::error('availability.calendar_unavailable', ['error' => $error->getMessage()]);
    Http::json(['error' => 'calendar_unavailable', 'message' => "L'agenda de planification ne répond pas. Réessayez dans un instant."], 503);
} catch (\Throwable $error) {
    Log::error('availability.failed', ['error' => $error->getMessage()]);
    Http::json(['error' => 'server_error', 'message' => 'Les disponibilités ne peuvent pas être chargées pour le moment. Réessayez dans un instant.'], 500);
}
