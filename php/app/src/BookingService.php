<?php

declare(strict_types=1);

namespace Bwc;

use Bwc\Calendar\CalendarClient;

/**
 * Cycle de vie d'un enlèvement, le même pour le site public et pour
 * l'administration : création, modification, changement de créneau,
 * confirmation, annulation, enlèvement effectué.
 *
 * Toute création ou tout changement de créneau se fait la journée
 * verrouillée dans MySQL, avec les mêmes places que le calendrier public.
 * Tout changement est inscrit dans l'historique de la réservation.
 */
final class BookingService
{
    private const SLOT_TAKEN = [
        'ok' => false,
        'code' => 'slot_unavailable',
        'message' => "Ce créneau vient d'être complété. Choisissez un autre créneau : vos informations sont conservées.",
    ];

    private const SLOT_CLOSED = [
        'ok' => false,
        'code' => 'slot_closed',
        'message' => "Ce créneau n'est plus réservable (date passée, délai trop court ou jour fermé). Choisissez une autre date.",
    ];

    private const BUSY = [
        'ok' => false,
        'code' => 'busy',
        'message' => 'Ce créneau est en cours de réservation par un autre garage. Réessayez dans un instant.',
    ];

    private const REQUEST_CLOSED = [
        'ok' => false,
        'code' => 'request_closed',
        'message' => "Cette demande n'a pas pu aboutir : le créneau n'est plus disponible. Choisissez un autre créneau.",
    ];

    /** Libellés des champs modifiables, pour l'historique. */
    private const FIELD_LABELS = [
        'garage_name' => 'garage', 'contact_name' => 'contact', 'phone' => 'téléphone', 'email' => 'email', 'street' => 'rue',
        'postal_code' => 'code postal', 'city' => 'ville', 'rim_count' => 'nombre de jantes', 'garage_reference' => 'référence du garage',
        'comment' => 'commentaire',
    ];

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private Config $config,
        private BookingStore $store,
        private GarageStore $garages,
        private ?CalendarClient $calendar,
        private Notifications $notifications,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? 'time';
    }

    private static function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    private static function sameAddress(string $a, string $b): bool
    {
        $norm = static fn (string $s): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower($s));
        return $norm($a) === $norm($b);
    }

    /** Champs d'une réservation issus du formulaire validé. */
    private static function fieldsFrom(array $input): array
    {
        return [
            'garage_name' => $input['garageName'], 'contact_name' => $input['contactName'], 'phone' => $input['phone'],
            'email' => $input['email'], 'street' => $input['street'], 'postal_code' => $input['postalCode'], 'city' => $input['city'],
            'rim_count' => $input['rimCount'], 'garage_reference' => $input['garageReference'], 'comment' => $input['comment'],
        ];
    }

    /** @param array<string, mixed> $slot @return array<string, mixed> */
    private static function slotFields(array $slot): array
    {
        return [
            'slot_date' => $slot['date'], 'slot_key' => $slot['key'], 'start_time' => $slot['startTime'], 'end_time' => $slot['endTime'],
            'starts_at' => BookingStore::datetime($slot['start']), 'ends_at' => BookingStore::datetime($slot['end']),
            'start_ts' => $slot['start'], 'end_ts' => $slot['end'],
        ];
    }

    private function garageEmailStatus(array $b): string
    {
        foreach ($this->notifications->forBooking($b['id']) as $n) {
            if ($n['kind'] === 'garage_confirmation') {
                return in_array($n['status'], ['sent', 'skipped'], true) ? $n['status'] : 'pending';
            }
        }
        return 'pending';
    }

    /** @param array<string, mixed> $row */
    private function existing(array $row): array
    {
        if (!in_array($row['status'], BookingStore::ACTIVE, true)) {
            return self::REQUEST_CLOSED;
        }
        return ['ok' => true, 'booking' => $row, 'alreadyExisted' => true, 'garageEmail' => $this->garageEmailStatus($row)];
    }

    /** Événements Google du créneau (rendez-vous manuels, fermetures), null sans agenda. */
    private function calendarEvents(array $slot): ?array
    {
        return $this->calendar?->listEvents($slot['start'], $slot['end']);
    }

    /**
     * Exécute $work dans une transaction, avec quelques nouveaux essais en cas
     * d'interblocage MySQL.
     *
     * @param callable(): array<string, mixed> $work
     * @return array<string, mixed>
     */
    private function withRetry(callable $work, ?callable $onDuplicate = null): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return Database::transaction($work);
            } catch (\PDOException $error) {
                if ($onDuplicate !== null && Database::isDuplicateKey($error)) {
                    $result = $onDuplicate();
                    if ($result !== null) {
                        return $result;
                    }
                }
                if (!Database::isLockConflict($error)) {
                    throw $error;
                }
                if ($attempt === 3) {
                    return self::BUSY;
                }
                usleep(random_int(50_000, 200_000));
            }
        }
    }

    /**
     * Enregistre un enlèvement.
     *
     * @param array<string, mixed> $input données validées (Validator::booking)
     * @param array{source?: string, adminId?: int|null, status?: string, garageId?: int|null, updateGarage?: bool, notifyGarage?: bool} $options
     * @return array<string, mixed> ['ok' => true, 'booking', 'alreadyExisted', 'garageEmail'] ou ['ok' => false, 'code', 'message']
     * @throws Calendar\CalendarUnavailable quand l'agenda ne peut pas être lu
     */
    public function create(array $input, int $photoCount = 0, array $options = []): array
    {
        $source = ($options['source'] ?? 'public') === 'admin' ? 'admin' : 'public';
        $adminId = $options['adminId'] ?? null;
        $status = ($options['status'] ?? 'confirmed') === 'pending' ? 'pending' : 'confirmed';
        $settings = $this->config->booking();
        $now = ($this->clock)();

        $slot = Schedule::findSlot($input['date'], $input['slot'], $settings);
        // VBM peut saisir un enlèvement pour plus tard dans la journée, le site public non.
        $bookable = $slot !== null && ($source === 'admin' ? $slot['end'] > $now : Schedule::isBookable($slot, $now, $settings));
        if (!$bookable) {
            return self::SLOT_CLOSED;
        }

        $secret = $this->config->secret();
        $hash = Ids::requestHash($input['requestId'], $secret);
        $previous = $this->store->findByRequestHash($hash);
        if ($previous !== null) {
            return $this->existing($previous);
        }

        $events = $this->calendarEvents($slot);
        $booking = self::fieldsFrom($input) + self::slotFields($slot) + [
            'reference' => Ids::reference($input['requestId'], $secret),
            'request_hash' => $hash,
            'source' => $source,
            'status' => $status,
            'slot_mode' => $settings['mode'],
            'photo_count' => $photoCount,
            'google_event_id' => Ids::eventId($input['requestId'], $secret),
            'google_status' => $this->calendar !== null ? 'pending' : 'off',
            'created_by' => $source === 'admin' ? $adminId : null,
            'created_at' => BookingStore::datetime(time()),
        ];

        $this->store->ensureDay($slot['date']);
        $outcome = $this->withRetry(function () use ($slot, $settings, $hash, $events, $booking, $options, $source, $adminId): array {
            $this->store->lockDay($slot['date']);
            $previous = $this->store->findByRequestHash($hash);
            if ($previous !== null) {
                return $this->existing($previous);
            }

            $rows = $this->store->activeBetween($slot['start'], $slot['end']);
            // Même garage déjà inscrit sur ce créneau (page rechargée après une
            // confirmation perdue) : on lui montre cette réservation au lieu d'en créer une autre.
            foreach ($rows as $row) {
                if ($booking['email'] !== '' && strtolower($row['email']) === strtolower($booking['email']) && self::sameAddress($row['street'], $booking['street'])) {
                    return $this->existing($row);
                }
            }
            if (!Availability::evaluate($slot, $rows, $events, (int) $settings['capacity_per_slot'])['available']) {
                return self::SLOT_TAKEN;
            }

            $garageId = isset($options['garageId']) && $this->garages->find((int) $options['garageId']) !== null ? (int) $options['garageId'] : null;
            if ($garageId !== null && ($options['updateGarage'] ?? false)) {
                $this->garages->updateFromBooking($garageId, $booking);
            }
            $garageId ??= $this->garages->match($booking) ?? $this->garages->createFromBooking($booking);

            $id = $this->store->insert($booking + ['garage_id' => $garageId]);
            $this->store->log($id, 'created', null, $booking['status'], $source === 'admin' ? 'Saisi dans l’administration' : 'Réservation reçue sur le site', $adminId);
            return ['ok' => true, 'booking' => $this->store->find($id), 'alreadyExisted' => false];
        }, fn () => ($row = $this->store->findByRequestHash($hash)) !== null ? $this->existing($row) : null);

        if (!$outcome['ok'] || $outcome['alreadyExisted']) {
            return $outcome;
        }

        $booking = $outcome['booking'];
        Log::info('booking.created', ['reference' => $booking['reference'], 'date' => $booking['slot_date'], 'slot' => $booking['slot_key'], 'source' => $source]);
        EventCache::clear();

        // La place est réservée : la copie dans l'agenda et les emails ne peuvent plus l'enlever.
        if ($source === 'public') {
            $this->notifications->queue($booking, 'vbm_notification');
        }
        $booking = $this->notifications->syncCalendar($booking);
        $garageEmail = 'skipped';
        if ($status === 'confirmed' && ($options['notifyGarage'] ?? true)) {
            $sent = $this->notifications->send($booking, 'garage_confirmation', $adminId);
            $garageEmail = in_array($sent['status'], ['sent', 'skipped'], true) ? $sent['status'] : 'pending';
        }
        return ['ok' => true, 'booking' => $booking, 'alreadyExisted' => false, 'garageEmail' => $garageEmail];
    }

    /**
     * Modifie un enlèvement à venir : coordonnées, nombre de jantes, et
     * éventuellement le créneau (avec les mêmes places que le site).
     *
     * @param array<string, mixed> $input données validées (Validator::booking)
     * @return array<string, mixed> ['ok' => true, 'booking', 'notification' => ?array] ou ['ok' => false, 'code', 'message']
     */
    public function update(int $id, array $input, int $adminId, bool $notifyGarage = true): array
    {
        $booking = $this->store->find($id);
        if ($booking === null) {
            return self::fail('not_found', 'Enlèvement introuvable.');
        }
        if (!in_array($booking['status'], BookingStore::OPEN, true)) {
            return self::fail('not_editable', 'Un enlèvement effectué ou annulé ne peut plus être modifié.');
        }
        $settings = $this->config->booking();
        $fields = self::fieldsFrom($input);
        $moved = $input['date'] !== $booking['slot_date'] || $input['slot'] !== $booking['slot_key'];
        $slot = null;
        $events = null;
        if ($moved) {
            $slot = Schedule::findSlot($input['date'], $input['slot'], $settings);
            if ($slot === null || $slot['end'] <= ($this->clock)()) {
                return self::fail('slot_closed', "Ce créneau n'existe pas, est fermé ou déjà passé. Choisissez-en un autre.");
            }
            $events = $this->calendarEvents($slot);
            $this->store->ensureDay($slot['date']);
        }

        $outcome = $this->withRetry(function () use ($id, $fields, $moved, $slot, $events, $settings, $adminId): array {
            if ($moved) {
                $old = $this->store->find($id);
                $days = array_unique([$old['slot_date'], $slot['date']]);
                sort($days);
                foreach ($days as $day) {
                    $this->store->lockDay($day);
                }
            }
            $current = $this->store->find($id, true);
            if (!in_array($current['status'], BookingStore::OPEN, true)) {
                return self::fail('not_editable', 'Un enlèvement effectué ou annulé ne peut plus être modifié.');
            }
            $changes = [];
            foreach ($fields as $column => $value) {
                if ((string) $current[$column] !== (string) $value) {
                    $changes[] = self::FIELD_LABELS[$column];
                }
            }
            $update = $fields;
            $details = [];
            if ($moved) {
                $rows = $this->store->activeBetween($slot['start'], $slot['end'], $id);
                if (!Availability::evaluate($slot, $rows, $events, (int) $settings['capacity_per_slot'])['available']) {
                    return self::fail('slot_unavailable', 'Ce créneau est complet. Choisissez-en un autre.');
                }
                $update += self::slotFields($slot);
                $details[] = 'Déplacé du ' . self::when($current) . ' au ' . self::when(array_merge($current, self::slotFields($slot)));
            }
            if ($changes !== []) {
                $details[] = 'Modifié : ' . implode(', ', $changes);
            }
            if ($details === []) {
                return ['ok' => true, 'booking' => $current, 'historyId' => null, 'moved' => false];
            }
            if ($this->calendar !== null) {
                $update['google_status'] = 'pending';
            }
            $this->store->update($id, $update);
            $historyId = $this->store->log($id, $moved ? 'rescheduled' : 'updated', $current['status'], $current['status'], implode('. ', $details), $adminId);
            return ['ok' => true, 'booking' => $this->store->find($id), 'historyId' => $historyId, 'moved' => $moved];
        });

        if (!$outcome['ok']) {
            return $outcome;
        }
        $booking = $outcome['booking'];
        EventCache::clear();
        $booking = $this->notifications->syncCalendar($booking);
        $notification = null;
        if ($outcome['moved'] && $notifyGarage && $booking['status'] === 'confirmed') {
            $notification = $this->notifications->send($booking, 'booking_rescheduled', $adminId, (string) $outcome['historyId'], [], [0, 0.8]);
        }
        return ['ok' => true, 'booking' => $booking, 'notification' => $notification];
    }

    /** « mardi 3 novembre, 9 h – 11 h » */
    private static function when(array $b): string
    {
        return Time::formatDayMonth($b['slot_date']) . ', ' . Time::formatSlotLabel($b['start_time'], $b['end_time'], $b['slot_mode']);
    }

    /**
     * Change le statut sous verrou : un double clic ne fait qu'un changement.
     *
     * @param list<string> $from statuts de départ acceptés
     * @param array<string, mixed> $extra colonnes à modifier en plus du statut
     * @return array<string, mixed> ['ok' => true, 'booking', 'changed' => bool] ou erreur
     */
    private function transition(int $id, array $from, string $to, array $extra, string $action, string $details, int $adminId): array
    {
        $outcome = $this->withRetry(function () use ($id, $from, $to, $extra, $action, $details, $adminId): array {
            $current = $this->store->find($id, true);
            if ($current === null) {
                return self::fail('not_found', 'Enlèvement introuvable.');
            }
            if ($current['status'] === $to) {
                return ['ok' => true, 'booking' => $current, 'changed' => false];
            }
            if (!in_array($current['status'], $from, true)) {
                return self::fail('invalid_status', match ($current['status']) {
                    'completed' => 'Cet enlèvement est déjà marqué comme enlevé.',
                    'cancelled' => 'Cet enlèvement a été annulé.',
                    default => 'Cette action n’est pas possible pour cet enlèvement.',
                });
            }
            $update = ['status' => $to] + $extra;
            if ($this->calendar !== null) {
                $update['google_status'] = 'pending';
            }
            $this->store->update($id, $update);
            $this->store->log($id, $action, $current['status'], $to, $details, $adminId);
            return ['ok' => true, 'booking' => $this->store->find($id), 'changed' => true];
        });
        if ($outcome['ok'] && $outcome['changed']) {
            EventCache::clear();
            $outcome['booking'] = $this->notifications->syncCalendar($outcome['booking']);
        }
        return $outcome;
    }

    /**
     * Jantes récupérées : statut « enlevé », date réelle et administrateur
     * enregistrés, puis un seul email de confirmation au garage.
     *
     * @return array<string, mixed> ['ok' => true, 'booking', 'changed', 'notification' => ?array] ou erreur
     */
    public function complete(int $id, int $adminId): array
    {
        $outcome = $this->transition($id, BookingStore::OPEN, 'completed', [
            'completed_at' => BookingStore::datetime(time()),
            'completed_by' => $adminId,
        ], 'completed', 'Jantes récupérées', $adminId);
        if (!$outcome['ok']) {
            return $outcome;
        }
        // Clé unique par réservation : jamais deux emails d'enlèvement.
        $outcome['notification'] = $this->notifications->send($outcome['booking'], 'pickup_completed', $adminId, '', [], [0, 0.8]);
        return $outcome;
    }

    /** @return array<string, mixed> */
    public function cancel(int $id, int $adminId, string $reason = '', bool $notifyGarage = true): array
    {
        $reason = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $reason)), 0, 255);
        $outcome = $this->transition($id, BookingStore::OPEN, 'cancelled', [
            'cancelled_at' => BookingStore::datetime(time()),
            'cancelled_by' => $adminId,
            'cancel_reason' => $reason,
        ], 'cancelled', $reason !== '' ? "Motif : $reason" : '', $adminId);
        $outcome['notification'] = null;
        if ($outcome['ok'] && $outcome['changed'] && $notifyGarage) {
            $outcome['notification'] = $this->notifications->send($outcome['booking'], 'booking_cancelled', $adminId, '', [], [0, 0.8]);
        }
        return $outcome;
    }

    /** Réservation en attente validée par VBM : le garage reçoit sa confirmation. */
    public function confirm(int $id, int $adminId, bool $notifyGarage = true): array
    {
        $outcome = $this->transition($id, ['pending'], 'confirmed', [], 'confirmed', '', $adminId);
        $outcome['notification'] = null;
        if ($outcome['ok'] && $outcome['changed'] && $notifyGarage) {
            $outcome['notification'] = $this->notifications->send($outcome['booking'], 'garage_confirmation', $adminId, '', [], [0, 0.8]);
        }
        return $outcome;
    }
}
