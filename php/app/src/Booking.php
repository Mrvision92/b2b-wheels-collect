<?php

declare(strict_types=1);

namespace Bwc;

/** Libellés d'une réservation, partagés par la confirmation, les emails et l'agenda. */
final class Booking
{
    public static function rimsLabel(int $count): string
    {
        return $count . ' jante' . ($count > 1 ? 's' : '');
    }

    /** @param array<string, mixed> $b */
    public static function address(array $b): string
    {
        return "{$b['street']}, {$b['postal_code']} {$b['city']}";
    }

    /** @param array<string, mixed> $b */
    public static function mapsUrl(array $b): string
    {
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(self::address($b) . ', Belgique');
    }

    /**
     * @param array<string, mixed> $b
     * @return array<string, string>
     */
    public static function describe(array $b): array
    {
        $photos = (int) $b['photo_count'];
        return [
            'dateLong' => Time::capitalize(Time::formatDateLong($b['slot_date'])),
            'slotLabel' => Time::formatSlotLabel($b['start_time'], $b['end_time'], $b['slot_mode']),
            'slotPhrase' => Time::formatSlotPhrase($b['start_time'], $b['end_time'], $b['slot_mode']),
            'address' => self::address($b),
            'phone' => Phone::format($b['phone']),
            'rims' => self::rimsLabel((int) $b['rim_count']),
            'mapsUrl' => self::mapsUrl($b),
            'photos' => $photos > 0 ? $photos . ' photo' . ($photos > 1 ? 's' : '') : '',
        ];
    }

    /**
     * Ce que le navigateur reçoit après la réservation : les données du garage uniquement.
     *
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    public static function publicSummary(array $b): array
    {
        $d = self::describe($b);
        return [
            'reference' => $b['reference'],
            'garageName' => $b['garage_name'],
            'contactName' => $b['contact_name'],
            'email' => $b['email'],
            'address' => $d['address'],
            'date' => $b['slot_date'],
            'dateLabel' => $d['dateLong'],
            'slotLabel' => $d['slotLabel'],
            'rimCount' => (int) $b['rim_count'],
            'rimsLabel' => $d['rims'],
            'garageReference' => $b['garage_reference'],
            'photoCount' => (int) $b['photo_count'],
            'photosLabel' => $d['photos'],
        ];
    }

    /** @param array<string, mixed> $b */
    public static function eventSummary(array $b): string
    {
        return 'ENLÈVEMENT — ' . $b['garage_name'] . ' — ' . self::rimsLabel((int) $b['rim_count']);
    }

    /** @param array<string, mixed> $b */
    public static function eventDescription(array $b): string
    {
        $d = self::describe($b);
        $lines = [
            "Référence : {$b['reference']}",
            "Créneau : {$d['dateLong']}, {$d['slotLabel']}",
            '',
            "Garage : {$b['garage_name']}",
            "Contact : {$b['contact_name']}",
            "Téléphone : {$d['phone']}",
            "Email : {$b['email']}",
            "Adresse : {$d['address']}",
            "Nombre de jantes : {$b['rim_count']}",
        ];
        if ($b['garage_reference'] !== '') {
            $lines[] = "Référence du garage : {$b['garage_reference']}";
        }
        if ($b['comment'] !== '') {
            $lines[] = "Commentaire : {$b['comment']}";
        }
        if ((int) $b['photo_count'] > 0) {
            $lines[] = "Photos : {$d['photos']}, jointes à l'email de notification";
        }
        array_push($lines, '', "Itinéraire : {$d['mapsUrl']}", '', 'Réservé en ligne sur B2B Wheels Collect.');
        return implode("\n", $lines);
    }

    /**
     * Événement Google Agenda. Les propriétés privées relient l'événement à
     * la réservation, même si VBM le déplace dans l'agenda.
     *
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    public static function calendarEvent(array $b): array
    {
        return [
            'id' => $b['google_event_id'],
            'summary' => self::eventSummary($b),
            'description' => self::eventDescription($b),
            'location' => self::address($b) . ', Belgique',
            'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', $b['start_ts']), 'timeZone' => Time::ZONE],
            'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', $b['end_ts']), 'timeZone' => Time::ZONE],
            'transparency' => 'opaque',
            'extendedProperties' => ['private' => ['bwc' => '1', 'bwcRef' => $b['reference']]],
        ];
    }
}
