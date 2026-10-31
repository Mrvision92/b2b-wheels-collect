<?php
/**
 * Réglages B2B Wheels Collect : disponibilités, coordonnées et mentions légales.
 *
 * C'est le seul fichier à modifier pour changer les jours, horaires et
 * créneaux proposés aux garages. Les mots de passe et clés ne vont jamais
 * ici : ils sont dans config.php.
 *
 * Pour fermer ponctuellement un créneau ou une journée, VBM peut aussi créer
 * un événement dans l'agenda Google (voir README, « Bloquer un créneau »).
 */

return [
    'booking' => [
        // "ranges" : plages affichées « 9 h – 11 h ». "times" : heures précises « 9 h 30 ».
        'mode' => 'ranges',
        // Durée d'un créneau (et de l'événement Google Agenda), en minutes.
        'slot_minutes' => 120,
        // Intervalle entre deux débuts de créneau, en minutes (null = égal à slot_minutes).
        'slot_step_minutes' => null,
        // Nombre maximum d'enlèvements acceptés sur un même créneau.
        'capacity_per_slot' => 2,
        // Délai minimum entre la réservation et le début du créneau, en heures.
        'min_notice_hours' => 12,
        // Nombre de jours à l'avance pendant lesquels on peut réserver.
        'max_advance_days' => 60,
        // Ferme automatiquement les jours fériés légaux belges.
        'close_on_public_holidays' => true,
        // Jours de fermeture exceptionnels, format « AAAA-MM-JJ ».
        'closed_dates' => [],
        // Plages ouvertes par jour (1 = lundi … 7 = dimanche), heure de Bruxelles.
        // Un jour absent ou vide est fermé.
        'weekly_schedule' => [
            1 => [['09:00', '17:00']],
            2 => [['09:00', '17:00']],
            3 => [['09:00', '17:00']],
            4 => [['09:00', '17:00']],
            5 => [['09:00', '17:00']],
        ],
        // Nombre maximum de jantes pour une même réservation.
        'max_rims_per_booking' => 40,
    ],

    // Coordonnées affichées sur le site et dans les emails.
    'contact' => [
        'whatsapp' => '+32 476 21 82 11',
        'phone' => '+32 2 523 20 02',
        'email' => 'info@vbmwheels.com',
    ],

    // Mentions de la page « Confidentialité ». Laissées vides, elles
    // s'affichent « à compléter par VBM ».
    'legal' => [
        'entity' => '',        // Dénomination légale, ex. « VBM Wheels Repair SRL »
        'address' => '',       // Adresse du siège
        'vat' => '',           // Numéro d'entreprise (BCE / TVA)
        'privacy_email' => '', // Vide = email de contact ci-dessus
        'retention' => '',     // Durée de conservation, ex. « 24 mois après l'enlèvement »
    ],
];
