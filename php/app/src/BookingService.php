<?php

declare(strict_types=1);

namespace Bwc;

use Bwc\Calendar\CalendarClient;

/**
 * Enregistrement d'un enlèvement. La confirmation n'est renvoyée qu'une fois
 * la réservation inscrite dans MySQL ; si l'agenda Google ne répond pas pour
 * vérifier le créneau, rien n'est confirmé au garage.
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

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private Config $config,
        private BookingStore $store,
        private ?CalendarClient $calendar,
        private Notifications $notifications,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? 'time';
    }

    private static function sameAddress(string $a, string $b): bool
    {
        $norm = static fn (string $s): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower($s));
        return $norm($a) === $norm($b);
    }

    /** @param array<string, mixed> $row */
    private static function existing(array $row): array
    {
        if ($row['status'] !== 'confirmed') {
            return self::REQUEST_CLOSED;
        }
        return ['ok' => true, 'booking' => $row, 'alreadyExisted' => true, 'garageEmail' => $row['garage_mail'] === 'sent' ? 'sent' : 'pending'];
    }

    /**
     * @param array<string, mixed> $input données validées (Validator::booking)
     * @return array<string, mixed> ['ok' => true, 'booking', 'alreadyExisted', 'garageEmail'] ou ['ok' => false, 'code', 'message']
     * @throws Calendar\CalendarUnavailable quand l'agenda ne peut pas être lu
     */
    public function create(array $input, int $photoCount = 0): array
    {
        $settings = $this->config->booking();
        $now = ($this->clock)();
        $slot = Schedule::findSlot($input['date'], $input['slot'], $settings);
        if ($slot === null || !Schedule::isBookable($slot, $now, $settings)) {
            return self::SLOT_CLOSED;
        }

        $secret = $this->config->secret();
        $hash = Ids::requestHash($input['requestId'], $secret);

        // Même demande déjà reçue (double clic, nouvel essai après une coupure).
        $previous = $this->store->findByRequestHash($hash);
        if ($previous !== null) {
            return self::existing($previous);
        }

        // Ce que VBM a mis dans l'agenda sur ce créneau : rendez-vous ajoutés à
        // la main, fermetures, réservations déplacées.
        $events = null;
        $fetchedAt = null;
        if ($this->calendar !== null) {
            $fetchedAt = microtime(true);
            $events = $this->calendar->listEvents($slot['start'], $slot['end']);
        }

        $booking = [
            'reference' => Ids::reference($input['requestId'], $secret),
            'request_hash' => $hash,
            'status' => 'confirmed',
            'slot_date' => $slot['date'],
            'slot_key' => $slot['key'],
            'start_time' => $slot['startTime'],
            'end_time' => $slot['endTime'],
            'starts_at' => BookingStore::datetime($slot['start']),
            'ends_at' => BookingStore::datetime($slot['end']),
            'slot_mode' => $settings['mode'],
            'garage_name' => $input['garageName'],
            'contact_name' => $input['contactName'],
            'phone' => $input['phone'],
            'email' => $input['email'],
            'street' => $input['street'],
            'postal_code' => $input['postalCode'],
            'city' => $input['city'],
            'rim_count' => $input['rimCount'],
            'garage_reference' => $input['garageReference'],
            'comment' => $input['comment'],
            'photo_count' => $photoCount,
            'google_event_id' => Ids::eventId($input['requestId'], $secret),
            'google_status' => $this->calendar !== null ? 'pending' : 'off',
            'garage_mail' => 'pending',
            'vbm_mail' => 'pending',
            'created_at' => BookingStore::datetime(time()),
        ];

        $this->store->ensureDay($slot['date']);
        for ($attempt = 1; ; $attempt++) {
            try {
                $outcome = $this->reserve($slot, $settings, $hash, $events, $fetchedAt, $booking, $input);
                break;
            } catch (\PDOException $error) {
                // Même demande envoyée au même instant pour un autre jour : la base a gardé la première.
                $previous = Database::isDuplicateKey($error) ? $this->store->findByRequestHash($hash) : null;
                if ($previous !== null) {
                    return self::existing($previous);
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

        if (!$outcome['ok'] || $outcome['alreadyExisted']) {
            return $outcome;
        }

        $booking = $outcome['booking'];
        Log::info('booking.created', ['reference' => $booking['reference'], 'date' => $booking['slot_date'], 'slot' => $booking['slot_key']]);
        EventCache::clear();

        // La place est réservée : la copie dans l'agenda et l'email ne peuvent plus l'enlever.
        $booking = $this->notifications->syncCalendar($booking);
        $booking = $this->notifications->sendGarageConfirmation($booking);
        return ['ok' => true, 'booking' => $booking, 'alreadyExisted' => false, 'garageEmail' => $booking['garage_mail'] === 'sent' ? 'sent' : 'pending'];
    }

    /**
     * Vérifie la place et enregistre la réservation, la journée verrouillée.
     *
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $settings
     * @param list<array<string, mixed>>|null $events
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function reserve(array $slot, array $settings, string $hash, ?array $events, ?float $fetchedAt, array $booking, array $input): array
    {
        return Database::transaction(function () use ($slot, $settings, $hash, $events, $fetchedAt, $booking, $input): array {
            $this->store->lockDay($slot['date']);

            $previous = $this->store->findByRequestHash($hash);
            if ($previous !== null) {
                return self::existing($previous);
            }

            $rows = $this->store->activeBetween($slot['start'], $slot['end']);
            // Même garage déjà inscrit sur ce créneau (page rechargée après une
            // confirmation perdue) : on lui montre cette réservation au lieu d'en créer une autre.
            foreach ($rows as $row) {
                if (strtolower($row['email']) === strtolower($input['email']) && self::sameAddress($row['street'], $input['street'])) {
                    return self::existing($row);
                }
            }

            if (!Availability::evaluate($slot, $rows, $events, $fetchedAt, (int) $settings['capacity_per_slot'])['available']) {
                return self::SLOT_TAKEN;
            }

            $this->store->insert($booking);
            return ['ok' => true, 'booking' => $this->store->findByRequestHash($hash), 'alreadyExisted' => false];
        });
    }
}

