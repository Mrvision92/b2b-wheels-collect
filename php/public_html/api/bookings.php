<?php
/**
 * POST /api/bookings.php
 * Formulaire envoyé en multipart/form-data : champ « data » (JSON) et
 * jusqu'à cinq fichiers « photos[] ».
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\App;
use Bwc\Booking;
use Bwc\Calendar\CalendarUnavailable;
use Bwc\Http;
use Bwc\Log;
use Bwc\Mail\DemoMailer;
use Bwc\Notifications;
use Bwc\Photos;
use Bwc\Validator;

const MAX_JSON_BYTES = 16000;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::json(['error' => 'method_not_allowed'], 405, ['Allow' => 'POST']);
    exit;
}
if (!Http::isSameOrigin()) {
    Http::json(['error' => 'forbidden', 'message' => 'Requête refusée.'], 403);
    exit;
}

try {
    $limit = Http::rateLimit('booking:' . Http::clientIp(), 12, 600);
    if (!$limit['ok']) {
        Http::json(
            ['error' => 'rate_limited', 'message' => 'Trop de tentatives depuis cette connexion. Réessayez dans quelques minutes.'],
            429,
            ['Retry-After' => (string) $limit['retryAfter']],
        );
        exit;
    }

    // Envoi plus gros que post_max_size : PHP vide $_POST et $_FILES.
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && $_POST === [] && $_FILES === []) {
        Http::json(['error' => 'payload_too_large', 'message' => 'Envoi trop volumineux : retirez une ou deux photos puis réessayez.'], 413);
        exit;
    }

    $raw = $_POST['data'] ?? '';
    $body = is_string($raw) && strlen($raw) <= MAX_JSON_BYTES ? json_decode($raw, true) : null;
    if (!is_array($body) || array_is_list($body)) {
        Http::json(['error' => 'invalid_json', 'message' => 'Données illisibles. Rechargez la page.'], 400);
        exit;
    }

    // Champ piège, invisible pour les personnes : seuls les robots le remplissent.
    if (!empty($body['website'])) {
        Http::json(['error' => 'rejected', 'message' => 'Requête refusée.'], 400);
        exit;
    }

    $config = App::config();
    [$input, $errors] = Validator::booking($body, (int) $config->booking()['max_rims_per_booking']);
    $photos = Photos::fromUpload($_FILES['photos'] ?? null);
    if ($input === null || !$photos['ok']) {
        if (!$photos['ok']) {
            $errors['photos'] = $photos['message'];
        }
        Http::json(['error' => 'validation', 'message' => 'Certains champs sont à corriger.', 'fields' => $errors], 400);
        exit;
    }

    $turnstile = $config->turnstile();
    if ($turnstile['secret_key'] !== '' && !Http::verifyTurnstile($input['turnstileToken'], $turnstile['secret_key'])) {
        Http::json(['error' => 'captcha', 'message' => 'La vérification anti-spam a échoué. Réessayez.'], 400);
        exit;
    }

    $result = App::bookings()->create($input, count($photos['photos']));
    if (!$result['ok']) {
        Http::json(['error' => $result['code'], 'message' => $result['message']], 409);
        exit;
    }

    $booking = $result['booking'];
    $demoEmails = App::mailer()->kind() === 'demo';
    Http::json([
        'ok' => true,
        'alreadyExisted' => $result['alreadyExisted'],
        'booking' => Booking::publicSummary($booking),
        'emailStatus' => $result['garageEmail'] === 'sent' ? ($demoEmails ? 'simulated' : 'sent') : 'pending',
        'demo' => [
            'calendar' => App::calendar() === null,
            'email' => $demoEmails,
            'previews' => $demoEmails ? [
                'garage' => 'demo/email.php?id=' . DemoMailer::idFor(Notifications::garageKey($booking)),
                'vbm' => 'demo/email.php?id=' . DemoMailer::idFor(Notifications::vbmKey($booking)),
            ] : null,
        ],
    ], $result['alreadyExisted'] ? 200 : 201);

    if (!$result['alreadyExisted']) {
        // Après la réponse : notification à VBM (avec les photos), puis reprise
        // des envois restés en attente.
        Http::finishResponse();
        $notifications = App::notifications();
        $notifications->sendVbmNotification($booking, $photos['photos']);
        $notifications->retryPending(5);
    }
} catch (CalendarUnavailable $error) {
    Log::error('booking.calendar_unavailable', ['error' => $error->getMessage()]);
    Http::json([
        'error' => 'calendar_unavailable',
        'message' => "Votre enlèvement n'a pas pu être confirmé : l'agenda de planification ne répond pas. Réessayez dans un instant, vous ne risquez pas de réserver deux fois.",
    ], 503);
} catch (\Throwable $error) {
    Log::error('booking.unexpected_error', ['error' => $error->getMessage(), 'file' => basename($error->getFile()) . ':' . $error->getLine()]);
    if (!headers_sent()) {
        Http::json([
            'error' => 'server_error',
            'message' => "Votre enlèvement n'a pas pu être confirmé à cause d'une erreur technique. Réessayez dans un instant, vous ne risquez pas de réserver deux fois.",
        ], 500);
    }
}
