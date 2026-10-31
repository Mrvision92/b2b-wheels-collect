<?php

declare(strict_types=1);

namespace Bwc;

use Bwc\Calendar\CalendarUnavailable;
use Bwc\Calendar\CalendarClient;
use Bwc\Mail\Mailer;
use Bwc\Mail\MailerException;
use Bwc\Mail\Templates;

/**
 * Suites d'une réservation : copie dans Google Agenda, email au garage,
 * notification à VBM. Chaque étape est notée dans MySQL, ce qui permet à la
 * tâche planifiée (cron) de reprendre ce qui a échoué, sans doublon.
 */
final class Notifications
{
    /** Après ce nombre d'essais en arrière-plan, l'étape est marquée « failed ». */
    public const MAX_BACKGROUND_ATTEMPTS = 12;

    public function __construct(
        private BookingStore $store,
        private Mailer $mailer,
        private ?CalendarClient $calendar,
        private Config $config,
    ) {
    }

    public static function garageKey(array $b): string
    {
        return "bwc/garage-confirmation/{$b['reference']}";
    }

    public static function vbmKey(array $b): string
    {
        return "bwc/vbm-notification/{$b['reference']}";
    }

    /** @return array{appUrl: string, contactPhone: string, contactEmail: string} */
    private function context(): array
    {
        $contact = $this->config->contact();
        return ['appUrl' => $this->config->appUrl(), 'contactPhone' => $contact['phone'], 'contactEmail' => $contact['email']];
    }

    /**
     * Crée l'événement Google Agenda (identifiant fixe : un nouvel essai ne
     * crée jamais de doublon).
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
            $event = $this->calendar->insertEvent(Booking::calendarEvent($b)) ?? $this->calendar->getEvent($b['google_event_id']);
            $syncedAt = microtime(true);
            $fields = [
                'google_status' => 'synced',
                'google_link' => $event['htmlLink'] ?? null,
                'google_synced_at' => BookingStore::datetimeMs($syncedAt),
                'google_tries' => $tries,
            ];
            EventCache::clear();
            $b['synced_ts'] = $syncedAt;
        } catch (CalendarUnavailable $error) {
            Log::warn('calendar.sync_failed', ['reference' => $b['reference'], 'error' => $error->getMessage()]);
            $fields = [
                'google_status' => $tries >= self::MAX_BACKGROUND_ATTEMPTS ? 'failed' : 'pending',
                'google_tries' => $tries,
            ];
        }
        $this->store->update($b['id'], $fields);
        return array_merge($b, $fields);
    }

    /**
     * @param array<string, mixed> $email
     * @param list<float> $delays secondes d'attente avant chaque essai
     */
    private function deliver(array $email, array $delays, string $kind, string $reference): bool
    {
        foreach ($delays as $attempt => $delay) {
            if ($delay > 0) {
                usleep((int) ($delay * 1_000_000));
            }
            try {
                $this->mailer->send($email);
                return true;
            } catch (MailerException $error) {
                Log::warn('email.send_failed', ['kind' => $kind, 'reference' => $reference, 'attempt' => $attempt + 1, 'retryable' => $error->retryable, 'error' => $error->getMessage()]);
                if (!$error->retryable) {
                    break;
                }
            } catch (\Throwable $error) {
                Log::error('email.unexpected_error', ['kind' => $kind, 'reference' => $reference, 'error' => $error->getMessage()]);
                break;
            }
        }
        return false;
    }

    /**
     * Enregistre le résultat d'un envoi. Seuls les essais en arrière-plan
     * comptent pour la limite.
     *
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    private function record(array $b, string $column, bool $sent, bool $background): array
    {
        $tries = $b[$column . '_tries'] + ($background ? 1 : 0);
        $status = $sent ? 'sent' : ($tries >= self::MAX_BACKGROUND_ATTEMPTS ? 'failed' : 'pending');
        if ($status === 'failed') {
            Log::error('email.gave_up', ['kind' => $column, 'reference' => $b['reference']]);
        }
        $fields = [$column => $status, $column . '_tries' => $tries];
        $this->store->update($b['id'], $fields);
        return array_merge($b, $fields);
    }

    /**
     * @param array<string, mixed> $b
     * @param list<float> $delays
     * @return array<string, mixed>
     */
    public function sendGarageConfirmation(array $b, array $delays = [0, 0.8, 2.5], bool $background = false): array
    {
        $email = Templates::garageConfirmation($b, $this->context()) + [
            'to' => [$b['email']],
            'reply_to' => $this->config->mail()['reply_to'] ?: null,
            'attachments' => [],
            'idempotency_key' => self::garageKey($b),
        ];
        return $this->record($b, 'garage_mail', $this->deliver($email, $delays, 'garage', $b['reference']), $background);
    }

    /**
     * Les photos ne sont jointes qu'au premier envoi : elles ne sont pas conservées.
     *
     * @param array<string, mixed> $b
     * @param list<array{filename: string, content_type: string, content: string}> $photos
     * @param list<float> $delays
     * @return array<string, mixed>
     */
    public function sendVbmNotification(array $b, array $photos = [], array $delays = [0, 0.8, 2.5], bool $background = false): array
    {
        $recipients = $this->config->mail()['vbm_recipients'];
        if ($recipients === []) {
            Log::warn('email.vbm_recipient_missing', ['reference' => $b['reference']]);
            $this->store->update($b['id'], ['vbm_mail' => 'skipped']);
            return array_merge($b, ['vbm_mail' => 'skipped']);
        }
        $email = Templates::vbmNotification($b, $this->context(), $photos !== []) + [
            'to' => $recipients,
            'reply_to' => $b['email'],
            'attachments' => $photos,
            'idempotency_key' => self::vbmKey($b),
        ];
        return $this->record($b, 'vbm_mail', $this->deliver($email, $delays, 'vbm', $b['reference']), $background);
    }

    /**
     * Reprise de ce qui reste à faire (appelé par le cron et après chaque réservation).
     *
     * @return array{processed: int, calendar: int, emails: int}
     */
    public function retryPending(int $limit = 20): array
    {
        $summary = ['processed' => 0, 'calendar' => 0, 'emails' => 0];
        foreach ($this->store->pendingFollowUps(120, $limit, $this->calendar !== null) as $b) {
            $summary['processed']++;
            if ($b['google_status'] === 'pending' && $this->calendar !== null) {
                $b = $this->syncCalendar($b, true);
                $summary['calendar'] += $b['google_status'] === 'synced' ? 1 : 0;
            }
            if ($b['garage_mail'] === 'pending') {
                $b = $this->sendGarageConfirmation($b, [0], true);
                $summary['emails'] += $b['garage_mail'] === 'sent' ? 1 : 0;
            }
            if ($b['vbm_mail'] === 'pending') {
                $b = $this->sendVbmNotification($b, [], [0], true);
                $summary['emails'] += $b['vbm_mail'] === 'sent' ? 1 : 0;
            }
        }
        return $summary;
    }
}
