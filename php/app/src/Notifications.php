<?php

declare(strict_types=1);

namespace Bwc;

use Bwc\Calendar\CalendarClient;
use Bwc\Calendar\CalendarUnavailable;
use Bwc\Mail\Mailer;
use Bwc\Mail\MailerException;
use Bwc\Mail\Templates;

/**
 * Suites d'une réservation : emails et copie dans Google Agenda.
 *
 * Chaque email correspond à un événement (confirmation, notification VBM,
 * enlèvement effectué, changement de créneau, annulation) et porte une clé
 * unique dans bwc_notifications : un double clic, un rechargement ou un
 * nouvel essai ne produisent jamais deux emails. Un email n'est marqué
 * « envoyé » qu'une fois accepté par le service d'envoi ; sinon la tâche
 * planifiée le renvoie.
 */
final class Notifications
{
    /** Après ce nombre d'essais, l'email (ou la copie Google) est marqué « failed ». */
    public const MAX_ATTEMPTS = 12;

    public const LABELS = [
        'garage_confirmation' => 'Confirmation au garage',
        'vbm_notification' => 'Notification à VBM',
        'pickup_completed' => "Confirmation d'enlèvement",
        'booking_rescheduled' => 'Changement de créneau',
        'booking_cancelled' => 'Annulation',
    ];

    public function __construct(
        private BookingStore $store,
        private Mailer $mailer,
        private ?CalendarClient $calendar,
        private Config $config,
        private ?PhotoStore $photos = null,
    ) {
    }

    private function pdo(): \PDO
    {
        return $this->store->pdo();
    }

    /** @return array{appUrl: string, contactPhone: string, contactEmail: string} */
    private function context(): array
    {
        $contact = $this->config->contact();
        return ['appUrl' => $this->config->appUrl(), 'contactPhone' => $contact['phone'], 'contactEmail' => $contact['email']];
    }

    private static function key(string $kind, array $b, string $suffix = ''): string
    {
        $slug = str_replace('_', '-', $kind);
        return "bwc/$slug/{$b['reference']}" . ($suffix !== '' ? "/$suffix" : '');
    }

    public static function garageKey(array $b): string
    {
        return self::key('garage_confirmation', $b);
    }

    public static function vbmKey(array $b): string
    {
        return self::key('vbm_notification', $b);
    }

    /** @return array<string, mixed>|null */
    private function findByKey(string $key): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM bwc_notifications WHERE idempotency_key = ?');
        $statement->execute([$key]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM bwc_notifications WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> emails d'une réservation, dans l'ordre */
    public function forBooking(int $bookingId): array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM bwc_notifications WHERE booking_id = ? ORDER BY id');
        $statement->execute([$bookingId]);
        return $statement->fetchAll();
    }

    /**
     * Inscrit l'email d'un événement. Si l'événement a déjà son email, c'est
     * celui-là qui est rendu : rien n'est envoyé deux fois.
     *
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    public function queue(array $b, string $kind, ?int $adminId = null, string $suffix = ''): array
    {
        $key = self::key($kind, $b, $suffix);
        $recipients = $kind === 'vbm_notification' ? $this->config->mail()['vbm_recipients'] : array_filter([$b['email']]);
        $now = BookingStore::datetime(time());
        $this->pdo()->prepare(
            'INSERT IGNORE INTO bwc_notifications (booking_id, kind, recipients, status, idempotency_key, admin_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$b['id'], $kind, implode(', ', $recipients), $recipients === [] ? 'skipped' : 'pending', $key, $adminId, $now, $now]);
        if ($kind === 'booking_rescheduled' || $kind === 'booking_cancelled') {
            // Un changement de créneau encore en attente devient inutile.
            $this->pdo()->prepare(
                "UPDATE bwc_notifications SET status = 'skipped', last_error = 'Remplacé par un message plus récent', updated_at = ?
                 WHERE booking_id = ? AND kind = 'booking_rescheduled' AND status IN ('pending', 'failed') AND idempotency_key <> ?",
            )->execute([$now, $b['id'], $key]);
        }
        return $this->findByKey($key);
    }

    /**
     * @param array<string, mixed> $b
     * @return array{subject: string, html: string, text: string}
     */
    private function render(string $kind, array $b, bool $photosAttached): array
    {
        return match ($kind) {
            'garage_confirmation' => Templates::garageConfirmation($b, $this->context()),
            'vbm_notification' => Templates::vbmNotification($b, $this->context(), $photosAttached, $this->garageConfirmationSent($b)),
            'pickup_completed' => Templates::pickupCompleted($b, $this->context()),
            'booking_rescheduled' => Templates::bookingRescheduled($b, $this->context()),
            'booking_cancelled' => Templates::bookingCancelled($b, $this->context()),
        };
    }

    private function garageConfirmationSent(array $b): bool
    {
        $notification = $this->findByKey(self::garageKey($b));
        return $notification === null || in_array($notification['status'], ['sent', 'skipped'], true);
    }

    /**
     * Envoie un email inscrit, avec quelques essais rapprochés, et enregistre
     * le résultat. Seuls les essais en arrière-plan comptent pour la limite.
     *
     * @param array<string, mixed> $notification
     * @param array<string, mixed> $b
     * @param list<array{filename: string, content_type: string, content: string}> $attachments
     * @param list<float> $delays secondes d'attente avant chaque essai
     * @return array<string, mixed> l'email à jour
     */
    public function deliver(array $notification, array $b, array $attachments = [], array $delays = [0, 0.8, 2.5], bool $background = false): array
    {
        if ($notification['status'] !== 'pending' && $notification['status'] !== 'failed') {
            return $notification;
        }
        // Les photos sont enregistrées : un nouvel essai de la notification VBM les joint aussi.
        if ($notification['kind'] === 'vbm_notification' && $attachments === [] && $this->photos !== null) {
            $attachments = $this->photos->attachments((int) $b['id']);
        }
        $email = $this->render($notification['kind'], $b, $attachments !== []) + [
            'to' => array_map('trim', explode(',', $notification['recipients'])),
            'reply_to' => $notification['kind'] === 'vbm_notification' ? ($b['email'] ?: null) : ($this->config->mail()['reply_to'] ?: null),
            'attachments' => $attachments,
            'idempotency_key' => $notification['idempotency_key'],
        ];

        $response = null;
        $error = null;
        foreach ($delays as $attempt => $delay) {
            if ($delay > 0) {
                usleep((int) ($delay * 1_000_000));
            }
            try {
                $response = $this->mailer->send($email);
                break;
            } catch (MailerException $exception) {
                $error = $exception->getMessage();
                Log::warn('email.send_failed', ['kind' => $notification['kind'], 'reference' => $b['reference'], 'attempt' => $attempt + 1, 'retryable' => $exception->retryable, 'error' => $error]);
                if (!$exception->retryable) {
                    break;
                }
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
                Log::error('email.unexpected_error', ['kind' => $notification['kind'], 'reference' => $b['reference'], 'error' => $error]);
                break;
            }
        }

        $attempts = (int) $notification['attempts'] + ($background ? 1 : 0);
        $now = BookingStore::datetime(time());
        if ($response !== null) {
            $fields = ['status' => 'sent', 'attempts' => $attempts, 'provider_response' => mb_substr($response, 0, 255), 'last_error' => null, 'sent_at' => $now];
        } else {
            $failed = $attempts >= self::MAX_ATTEMPTS;
            if ($failed) {
                Log::error('email.gave_up', ['kind' => $notification['kind'], 'reference' => $b['reference']]);
            }
            $fields = ['status' => $failed ? 'failed' : 'pending', 'attempts' => $attempts, 'last_error' => mb_substr((string) $error, 0, 500)];
        }
        $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($fields)));
        $this->pdo()->prepare("UPDATE bwc_notifications SET $set, updated_at = ? WHERE id = ?")->execute([...array_values($fields), $now, $notification['id']]);
        return array_merge($notification, $fields);
    }

    /**
     * Inscrit puis envoie tout de suite l'email d'un événement.
     *
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    public function send(array $b, string $kind, ?int $adminId = null, string $suffix = '', array $attachments = [], array $delays = [0, 0.8, 2.5]): array
    {
        return $this->deliver($this->queue($b, $kind, $adminId, $suffix), $b, $attachments, $delays);
    }

    /**
     * Nouvel essai demandé depuis l'administration pour un email en échec.
     *
     * @return array<string, mixed>|null l'email à jour, ou null s'il n'y a rien à renvoyer
     */
    public function retryNow(int $notificationId): ?array
    {
        $notification = $this->find($notificationId);
        if ($notification === null || !in_array($notification['status'], ['pending', 'failed'], true)) {
            return null;
        }
        $b = $this->store->find((int) $notification['booking_id']);
        return $b === null ? null : $this->deliver($notification, $b, [], [0]);
    }

    /**
     * Met Google Agenda en accord avec la réservation : un événement pour un
     * enlèvement confirmé ou effectué, aucun pour une réservation en attente
     * ou annulée. L'identifiant de l'événement est fixe : un nouvel essai ne
     * crée jamais de doublon.
     *
     * @param array<string, mixed> $b
     * @return array<string, mixed> la réservation à jour
     */
    public function syncCalendar(array $b, bool $background = false): array
    {
        if ($this->calendar === null || $b['google_status'] !== 'pending') {
            return $b;
        }
        $tries = $b['google_tries'] + ($background ? 1 : 0);
        try {
            $link = $b['google_link'];
            if (in_array($b['status'], ['confirmed', 'completed'], true)) {
                $event = Booking::calendarEvent($b);
                $created = $this->calendar->insertEvent(array_filter($event, static fn ($value): bool => $value !== null));
                $result = $created ?? $this->calendar->patchEvent($b['google_event_id'], $event);
                $link = $result['htmlLink'] ?? $link;
            } else {
                $this->calendar->deleteEvent($b['google_event_id']);
            }
            $fields = ['google_status' => 'synced', 'google_link' => $link, 'google_synced_at' => BookingStore::datetimeMs(microtime(true)), 'google_tries' => $tries];
            EventCache::clear();
        } catch (CalendarUnavailable $error) {
            Log::warn('calendar.sync_failed', ['reference' => $b['reference'], 'error' => $error->getMessage()]);
            $fields = ['google_status' => $tries >= self::MAX_ATTEMPTS ? 'failed' : 'pending', 'google_tries' => $tries];
        }
        $this->store->update($b['id'], $fields);
        return array_merge($b, $fields);
    }

    /**
     * Reprise de ce qui reste à faire (tâche planifiée, et après chaque
     * réservation). Les envois récents sont laissés à la requête qui les a faits.
     *
     * @return array{emails: int, sent: int, calendar: int}
     */
    public function retryPending(int $limit = 20): array
    {
        $summary = ['emails' => 0, 'sent' => 0, 'calendar' => 0];
        $statement = $this->pdo()->prepare(
            "SELECT * FROM bwc_notifications WHERE status = 'pending' AND created_at < ? ORDER BY created_at, id LIMIT " . max(1, $limit),
        );
        $statement->execute([BookingStore::datetime(time() - 120)]);
        foreach ($statement->fetchAll() as $notification) {
            $b = $this->store->find((int) $notification['booking_id']);
            if ($b === null) {
                continue;
            }
            $summary['emails']++;
            $result = $this->deliver($notification, $b, [], [0], true);
            $summary['sent'] += $result['status'] === 'sent' ? 1 : 0;
        }
        if ($this->calendar !== null) {
            foreach ($this->store->pendingCalendarSync(120, $limit) as $b) {
                $b = $this->syncCalendar($b, true);
                $summary['calendar'] += $b['google_status'] === 'synced' ? 1 : 0;
            }
        }
        return $summary;
    }
}
