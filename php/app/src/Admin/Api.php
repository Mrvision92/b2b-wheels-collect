<?php

declare(strict_types=1);

namespace Bwc\Admin;

use Bwc\App;
use Bwc\Availability;
use Bwc\BookingStore;
use Bwc\Calendar\CalendarUnavailable;
use Bwc\Http;
use Bwc\Log;
use Bwc\Phone;
use Bwc\Time;
use Bwc\Validator;

/**
 * Actions de l'administration (JSON). Chaque modification exige une session
 * et le jeton CSRF ; les réponses ne disent « email envoyé » que lorsque le
 * service d'envoi a accepté l'email.
 */
final class Api
{
    /** @param array<string, mixed> $admin */
    public function __construct(private array $admin)
    {
    }

    public function handle(string $method, string $action): void
    {
        try {
            if ($method === 'POST' && !Http::rateLimit('admin-write:' . $this->admin['id'], 120, 60)['ok']) {
                Http::json(['error' => 'rate_limited', 'message' => 'Trop d’actions en peu de temps. Patientez une minute.'], 429);
                return;
            }
            $body = $method === 'POST' ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : $_GET;
            $handler = match ("$method $action") {
                'GET booking' => fn () => $this->booking((int) ($body['id'] ?? 0)),
                'GET slots' => fn () => $this->slots((string) ($body['date'] ?? ''), (int) ($body['exclude'] ?? 0)),
                'GET garages' => fn () => $this->garages((string) ($body['q'] ?? ''), (int) ($body['exclude'] ?? 0)),
                'POST create' => fn () => $this->create($body),
                'POST update' => fn () => $this->update($body),
                'POST complete' => fn () => $this->complete((int) ($body['id'] ?? 0)),
                'POST confirm' => fn () => $this->confirm((int) ($body['id'] ?? 0), (bool) ($body['notify'] ?? true)),
                'POST cancel' => fn () => $this->cancel((int) ($body['id'] ?? 0), (string) ($body['reason'] ?? ''), (bool) ($body['notify'] ?? true)),
                'POST resend' => fn () => $this->resend((int) ($body['id'] ?? 0)),
                'POST reopen' => fn () => $this->reopen((int) ($body['id'] ?? 0)),
                'POST note' => fn () => $this->note((int) ($body['id'] ?? 0), (string) ($body['note'] ?? '')),
                'POST garage-update' => fn () => $this->garageUpdate($body),
                'POST garage-erase' => fn () => $this->garageErase((int) ($body['id'] ?? 0)),
                'POST garage-merge' => fn () => $this->garageMerge((int) ($body['source'] ?? 0), (int) ($body['target'] ?? 0)),
                default => null,
            };
            if ($handler === null) {
                Http::json(['error' => 'not_found', 'message' => 'Action inconnue.'], 404);
                return;
            }
            $handler();
        } catch (CalendarUnavailable $error) {
            Log::error('admin.calendar_unavailable', ['action' => $action, 'error' => $error->getMessage()]);
            Http::json(['error' => 'calendar_unavailable', 'message' => 'Google Agenda ne répond pas : impossible de vérifier ce créneau. Réessayez dans un instant.'], 503);
        } catch (\Throwable $error) {
            Log::error('admin.api_error', ['action' => $action, 'error' => $error->getMessage(), 'file' => basename($error->getFile()) . ':' . $error->getLine()]);
            Http::json(['error' => 'server_error', 'message' => 'Une erreur technique est survenue. Réessayez dans un instant.'], 500);
        }
    }

    private function adminId(): int
    {
        return (int) $this->admin['id'];
    }

    /* Lecture ------------------------------------------------------------- */

    private function booking(int $id): void
    {
        $b = App::store()->find($id);
        if ($b === null) {
            Http::json(['error' => 'not_found', 'message' => 'Enlèvement introuvable.'], 404);
            return;
        }
        $now = time();
        $names = [];
        foreach (Auth::store()->all() as $a) {
            $names[(int) $a['id']] = $a['name'];
        }
        ob_start();
        (static function (array $b, array $notifications, array $history, array $photos, array $adminNames, bool $calendarOn, int $now, string $today): void {
            require BWC_APP_DIR . '/views/admin/_details.php';
        })($b, App::notifications()->forBooking($id), App::store()->history($id), App::photos()->forBooking($id), $names, App::calendar() !== null, $now, Time::dateOf($now));
        Http::json([
            'html' => (string) ob_get_clean(),
            'booking' => [
                'id' => $b['id'], 'reference' => $b['reference'], 'status' => $b['status'], 'garageId' => $b['garage_id'],
                'garageName' => $b['garage_name'], 'contactName' => $b['contact_name'], 'phone' => Phone::format($b['phone']), 'email' => $b['email'],
                'street' => $b['street'], 'postalCode' => $b['postal_code'], 'city' => $b['city'], 'rimCount' => $b['rim_count'],
                'date' => $b['slot_date'], 'slot' => $b['slot_key'], 'garageReference' => $b['garage_reference'], 'comment' => $b['comment'],
            ],
        ]);
    }

    private function slots(string $date, int $exclude): void
    {
        if (!Time::isValidDate($date)) {
            Http::json(['slots' => [], 'message' => 'Choisissez une date.']);
            return;
        }
        $slots = Availability::daySlotsForAdmin($date, App::config()->booking(), App::store(), App::calendar(), time(), $exclude ?: null);
        Http::json(['slots' => $slots, 'message' => $slots === [] ? 'Jour fermé (week-end, jour férié ou fermeture).' : '']);
    }

    private function garages(string $q, int $exclude): void
    {
        $rows = mb_strlen(trim($q)) < 2 ? [] : App::garages()->suggest($q);
        $rows = array_values(array_filter($rows, static fn (array $g): bool => (int) $g['id'] !== $exclude));
        foreach ($rows as &$g) {
            $g['id'] = (int) $g['id'];
            $g['phone'] = Phone::format($g['phone']);
        }
        Http::json(['garages' => $rows]);
    }

    /* Enlèvements --------------------------------------------------------- */

    /** @return array{0: array<string, mixed>|null, 1: array<string, string>} */
    private function validate(array $body): array
    {
        $body['requestId'] = (string) ($body['requestId'] ?? '');
        return Validator::booking($body, (int) App::config()->booking()['max_rims_per_booking'], ['emailOptional' => true]);
    }

    private static function emailMessage(?array $notification, string $done, string $who = 'au garage'): string
    {
        return match ($notification['status'] ?? null) {
            null => $done . '.',
            'sent' => "$done. Email envoyé $who.",
            'skipped' => "$done (pas d’adresse email pour prévenir le garage).",
            default => "$done. L’email n’a pas pu partir pour l’instant : nouvel essai automatique.",
        };
    }

    private function create(array $body): void
    {
        [$input, $errors] = $this->validate($body);
        if ($input === null) {
            Http::json(['error' => 'validation', 'message' => 'Certains champs sont à corriger.', 'fields' => $errors], 400);
            return;
        }
        $result = App::bookings()->create($input, 0, [
            'source' => 'admin',
            'adminId' => $this->adminId(),
            'status' => ($body['status'] ?? '') === 'pending' ? 'pending' : 'confirmed',
            'garageId' => !empty($body['garageId']) ? (int) $body['garageId'] : null,
            'updateGarage' => (bool) ($body['updateGarage'] ?? false),
            'notifyGarage' => (bool) ($body['notifyGarage'] ?? true),
        ]);
        if (!$result['ok']) {
            Http::json(['error' => $result['code'], 'message' => $result['message'], 'fields' => ['slot' => $result['message']]], 409);
            return;
        }
        $b = $result['booking'];
        $message = $result['alreadyExisted'] ? 'Cet enlèvement était déjà enregistré' : "Enlèvement {$b['reference']} enregistré";
        $email = match ($result['garageEmail']) {
            'sent' => '. Confirmation envoyée au garage.',
            'pending' => '. La confirmation au garage partira dès que possible.',
            default => '.',
        };
        Http::json(['ok' => true, 'id' => $b['id'], 'reference' => $b['reference'], 'message' => $message . ($result['alreadyExisted'] ? '.' : $email)], $result['alreadyExisted'] ? 200 : 201);
    }

    private function update(array $body): void
    {
        // La référence d'origine ne change pas : l'identifiant de demande ne sert qu'à la création.
        [$input, $errors] = $this->validate(array_merge($body, ['requestId' => '00000000-0000-4000-8000-000000000000']));
        if ($input === null) {
            Http::json(['error' => 'validation', 'message' => 'Certains champs sont à corriger.', 'fields' => $errors], 400);
            return;
        }
        $result = App::bookings()->update((int) ($body['id'] ?? 0), $input, $this->adminId(), (bool) ($body['notifyChange'] ?? true));
        if (!$result['ok']) {
            Http::json(['error' => $result['code'], 'message' => $result['message'], 'fields' => $result['code'] === 'slot_unavailable' || $result['code'] === 'slot_closed' ? ['slot' => $result['message']] : []], 409);
            return;
        }
        Http::json(['ok' => true, 'id' => $result['booking']['id'], 'message' => self::emailMessage($result['notification'], 'Enlèvement modifié')]);
    }

    private function complete(int $id): void
    {
        $result = App::bookings()->complete($id, $this->adminId());
        if (!$result['ok']) {
            Http::json(['error' => $result['code'], 'message' => $result['message']], 409);
            return;
        }
        $message = $result['changed']
            ? self::emailMessage($result['notification'], 'Enlèvement marqué comme effectué', 'au garage')
            : 'Cet enlèvement était déjà marqué comme enlevé.';
        Http::json(['ok' => true, 'id' => $id, 'message' => $message]);
    }

    private function confirm(int $id, bool $notify): void
    {
        $result = App::bookings()->confirm($id, $this->adminId(), $notify);
        if (!$result['ok']) {
            Http::json(['error' => $result['code'], 'message' => $result['message']], 409);
            return;
        }
        Http::json(['ok' => true, 'id' => $id, 'message' => self::emailMessage($result['notification'], 'Enlèvement confirmé')]);
    }

    private function cancel(int $id, string $reason, bool $notify): void
    {
        $result = App::bookings()->cancel($id, $this->adminId(), $reason, $notify);
        if (!$result['ok']) {
            Http::json(['error' => $result['code'], 'message' => $result['message']], 409);
            return;
        }
        Http::json(['ok' => true, 'id' => $id, 'message' => self::emailMessage($result['notification'], 'Enlèvement annulé')]);
    }

    private function reopen(int $id): void
    {
        $result = App::bookings()->reopen($id, $this->adminId());
        if (!$result['ok']) {
            Http::json(['error' => $result['code'], 'message' => $result['message']], 409);
            return;
        }
        Http::json(['ok' => true, 'id' => $id, 'message' => 'L’enlèvement est de nouveau « à enlever ».']);
    }

    private function note(int $id, string $note): void
    {
        $result = App::bookings()->setNote($id, $note, $this->adminId());
        if (!$result['ok']) {
            Http::json(['error' => $result['code'], 'message' => $result['message']], 404);
            return;
        }
        Http::json(['ok' => true, 'id' => $id, 'message' => 'Note enregistrée.']);
    }

    private function resend(int $notificationId): void
    {
        $result = App::notifications()->retryNow($notificationId);
        if ($result === null) {
            Http::json(['error' => 'nothing_to_send', 'message' => 'Cet email est déjà parti ou ne peut pas être renvoyé.'], 409);
            return;
        }
        $sent = $result['status'] === 'sent';
        Http::json(['ok' => $sent, 'id' => (int) $result['booking_id'], 'message' => $sent ? 'Email envoyé.' : 'L’email n’a toujours pas pu partir : ' . ($result['last_error'] ?? 'erreur inconnue')], $sent ? 200 : 502);
    }

    /* Garages ------------------------------------------------------------- */

    private function garageUpdate(array $body): void
    {
        $id = (int) ($body['id'] ?? 0);
        if (App::garages()->find($id) === null) {
            Http::json(['error' => 'not_found', 'message' => 'Garage introuvable.'], 404);
            return;
        }
        $clean = static fn ($v, int $max): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $v)), 0, $max);
        $fields = [
            'name' => $clean($body['name'] ?? '', 120), 'contact_name' => $clean($body['contact_name'] ?? '', 120),
            'phone' => $clean($body['phone'] ?? '', 40), 'email' => strtolower($clean($body['email'] ?? '', 254)),
            'street' => $clean($body['street'] ?? '', 160), 'postal_code' => $clean($body['postal_code'] ?? '', 4),
            'city' => $clean($body['city'] ?? '', 80), 'notes' => mb_substr(trim((string) ($body['notes'] ?? '')), 0, 2000),
        ];
        $errors = [];
        if (mb_strlen($fields['name']) < 2) {
            $errors['name'] = 'Indiquez le nom du garage.';
        }
        if ($fields['phone'] !== '') {
            $phone = Phone::normalize($fields['phone']);
            $phone === null ? $errors['phone'] = 'Numéro invalide.' : $fields['phone'] = $phone;
        }
        if ($fields['email'] !== '' && filter_var($fields['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Adresse email invalide.';
        }
        if ($fields['postal_code'] !== '' && preg_match('/^[1-9]\d{3}$/', $fields['postal_code']) !== 1) {
            $errors['postal_code'] = 'Code postal belge à 4 chiffres.';
        }
        if ($errors !== []) {
            Http::json(['error' => 'validation', 'message' => 'Certains champs sont à corriger.', 'fields' => $errors], 400);
            return;
        }
        App::garages()->update($id, $fields);
        Log::info('admin.garage_updated', ['garage' => $id, 'admin' => $this->adminId()]);
        Http::json(['ok' => true, 'message' => 'Fiche du garage enregistrée.']);
    }

    private function garageErase(int $id): void
    {
        if (App::garages()->find($id) === null) {
            Http::json(['error' => 'not_found', 'message' => 'Garage introuvable.'], 404);
            return;
        }
        $result = App::garages()->erase($id, App::photos(), $this->adminId());
        Http::json($result + ['error' => $result['ok'] ? null : 'open_bookings'], $result['ok'] ? 200 : 409);
    }

    private function garageMerge(int $source, int $target): void
    {
        if ($source === $target || App::garages()->find($source) === null || App::garages()->find($target) === null) {
            Http::json(['error' => 'invalid', 'message' => 'Choisissez une autre fiche.'], 400);
            return;
        }
        App::garages()->merge($source, $target);
        Log::info('admin.garage_merged', ['source' => $source, 'target' => $target, 'admin' => $this->adminId()]);
        Http::json(['ok' => true, 'message' => 'Fiches fusionnées : les réservations sont rattachées à ce garage.']);
    }
}
