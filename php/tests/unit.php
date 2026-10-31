<?php

declare(strict_types=1);

use Bwc\Availability;
use Bwc\Config;
use Bwc\Holidays;
use Bwc\Ids;
use Bwc\Mail\Mime;
use Bwc\Mail\Templates;
use Bwc\Phone;
use Bwc\Photos;
use Bwc\Schedule;
use Bwc\Time;
use Bwc\Validator;

/* Heures et créneaux ----------------------------------------------------- */

test('heure de Bruxelles en hiver et en été', function () {
    eq(gmdate('c', Time::zonedToUtc('2026-11-03', '09:00')), '2026-11-03T08:00:00+00:00');
    eq(gmdate('c', Time::zonedToUtc('2026-07-01', '09:00')), '2026-07-01T07:00:00+00:00');
});

test("l'heure sautée au passage à l'heure d'été n'existe pas", function () {
    eq(Time::zonedToUtc('2026-03-29', '02:30'), null);
    eq(gmdate('c', Time::zonedToUtc('2026-03-29', '03:00')), '2026-03-29T01:00:00+00:00');
});

test('dates invalides refusées', function () {
    ok(!Time::isValidDate('2026-02-30'), '30 février');
    ok(!Time::isValidDate('2026-2-3'), 'format court');
    ok(Time::isValidDate('2028-02-29'), 'année bissextile');
});

test('libellés français', function () {
    eq(Time::formatDateLong('2026-10-29'), 'jeudi 29 octobre 2026');
    eq(Time::formatMonthTitle('2026-08'), 'août 2026');
    eq(Time::formatSlotLabel('09:00', '11:00', 'ranges'), '9 h – 11 h');
    eq(Time::formatSlotLabel('09:30', '11:30', 'times'), '9 h 30');
    eq(Time::formatSlotPhrase('14:00', '16:00', 'ranges'), 'entre 14 h et 16 h');
    eq(Time::capitalize('élan'), 'Élan');
    eq(Time::addMonths('2026-12', 1), '2027-01');
});

test('jours fériés belges 2026', function () {
    $holidays = Holidays::forYear(2026);
    eq($holidays['2026-04-06'] ?? null, 'Lundi de Pâques');
    eq($holidays['2026-05-14'] ?? null, 'Ascension');
    eq($holidays['2026-05-25'] ?? null, 'Lundi de Pentecôte');
    eq(count($holidays), 10);
});

test('créneaux de 2 h du lundi au vendredi, fermés le week-end et les jours fériés', function () {
    $config = Config::get()->booking();
    $slots = Schedule::slotsForDate('2026-11-03', $config);
    eq(array_column($slots, 'key'), ['0900', '1100', '1300', '1500']);
    eq($slots[0]['label'], '9 h – 11 h');
    eq(Schedule::slotsForDate('2026-11-07', $config), [], 'samedi');
    eq(Schedule::slotsForDate('2026-11-11', $config), [], 'Armistice');
});

test('délai de prévenance et limite de réservation à l\'avance', function () {
    $config = Config::get()->booking();
    $mondayNine = Schedule::findSlot('2026-11-02', '0900', $config);
    $tuesdayNine = Schedule::findSlot('2026-11-03', '0900', $config);
    ok(!Schedule::isBookable($mondayNine, Tests::NOW, $config), 'le jour même');
    ok(Schedule::isBookable($tuesdayNine, Tests::NOW, $config), 'le lendemain');
    $far = Schedule::findSlot('2027-01-04', '0900', $config);
    ok(!Schedule::isBookable($far, Tests::NOW, $config), 'au-delà de 60 jours');
});

/* Téléphone, identifiants, photos -------------------------------------- */

test('numéros de téléphone normalisés', function () {
    foreach ([
        ['0470 12 34 56', '+32470123456'], ['0470/12.34.56', '+32470123456'], ['+32 470 12 34 56', '+32470123456'],
        ['0032 2 123 45 67', '+3221234567'], ['02 123 45 67', '+3221234567'], ['+33 6 12 34 56 78', '+33612345678'],
    ] as [$raw, $expected]) {
        eq(Phone::normalize($raw), $expected, $raw);
    }
    foreach (['', '12345', '0470 12 34', '+32 0470 12 34 56', 'abc', '+32 470 12 34 56 78'] as $raw) {
        eq(Phone::normalize($raw), null, $raw);
    }
    eq(Phone::format('+32470123456'), '+32 470 12 34 56');
    eq(Phone::format('+3221234567'), '+32 2 123 45 67');
    eq(Phone::format('+3281234567'), '+32 81 23 45 67');
});

test('références stables, lisibles et différentes par demande', function () {
    $a = Ids::reference('6f1f0d4e-3a4b-4c1d-9e2f-1a2b3c4d5e6f', 'secret-a-32-caracteres-minimum-xx');
    ok(preg_match('/^BWC-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $a) === 1, "format $a");
    eq(Ids::reference('6f1f0d4e-3a4b-4c1d-9e2f-1a2b3c4d5e6f', 'secret-a-32-caracteres-minimum-xx'), $a);
    ok(Ids::reference('6f1f0d4e-3a4b-4c1d-9e2f-1a2b3c4d5e70', 'secret-a-32-caracteres-minimum-xx') !== $a, 'autre demande');
    ok(preg_match('/^rc[0-9a-f]{40}$/', Ids::eventId('x', 'y')) === 1, 'identifiant Google valide');
});

test('photos reconnues à leur contenu', function () {
    eq(Photos::sniff("\xFF\xD8\xFF\xE0rest")[0] ?? null, 'image/jpeg');
    eq(Photos::sniff("\x89PNG\r\n\x1A\nrest")[0] ?? null, 'image/png');
    eq(Photos::sniff('RIFF1234WEBPVP8 ')[0] ?? null, 'image/webp');
    eq(Photos::sniff("\0\0\0\x18ftypheic")[0] ?? null, 'image/heic');
    eq(Photos::sniff('%PDF-1.7'), null);
});

/* Vérification du formulaire -------------------------------------------- */

function validForm(array $overrides = []): array
{
    return $overrides + [
        'requestId' => '6f1f0d4e-3a4b-4c1d-9e2f-1a2b3c4d5e6f',
        'garageName' => 'Garage Dupont',
        'contactName' => 'Marie Dupont',
        'phone' => '0470 12 34 56',
        'email' => 'atelier@garage-dupont.be',
        'street' => 'Rue de la Station 12',
        'postalCode' => '1000',
        'city' => 'Bruxelles',
        'rimCount' => '4',
        'date' => '2026-11-03',
        'slot' => '0900',
        'comment' => '',
    ];
}

test('formulaire complet accepté et nettoyé', function () {
    [$data, $errors] = Validator::booking(validForm(['garageName' => "  Garage\u{0000}\n  Dupont ", 'comment' => '  Avec pneus  ', 'garageReference' => '  AB-12   34 ']), 40);
    eq($errors, []);
    eq($data['garageName'], 'Garage Dupont');
    eq($data['phone'], '+32470123456');
    eq($data['rimCount'], 4);
    eq($data['comment'], 'Avec pneus');
    eq($data['garageReference'], 'AB-12 34');
    ok(!isset($data['isAdmin']), 'champs inconnus ignorés');
});

test('chaque champ obligatoire manquant est signalé en français', function () {
    [$data, $errors] = Validator::booking(['requestId' => '6f1f0d4e-3a4b-4c1d-9e2f-1a2b3c4d5e6f'], 40);
    eq($data, null);
    $fields = array_keys($errors);
    sort($fields);
    eq($fields, ['city', 'contactName', 'date', 'email', 'garageName', 'phone', 'postalCode', 'rimCount', 'slot', 'street']);
    eq($errors['garageName'], 'Indiquez le nom du garage.');
    eq($errors['rimCount'], 'Indiquez le nombre de jantes.');
});

test('valeurs invalides refusées avec le bon message', function () {
    foreach ([
        ['email', 'pas-un-email', 'Adresse email invalide'], ['phone', '123', 'Numéro invalide'], ['postalCode', '75001', 'Code postal belge'],
        ['street', 'Rue de la Station', 'numéro de rue'], ['contactName', 'Dupont', 'prénom et le nom'], ['rimCount', '0', 'au moins 1 jante'],
        ['rimCount', '500', 'contactez-nous'], ['rimCount', '2.5', 'nombre entier'], ['date', '2026-02-30', 'Choisissez une date'],
        ['slot', '9h', 'Choisissez un créneau'], ['requestId', 'abc', 'Requête invalide'], ['comment', str_repeat('a', 501), 'Commentaire trop long'],
        ['garageReference', str_repeat('a', 61), 'Référence trop longue'],
    ] as [$field, $value, $message]) {
        [, $errors] = Validator::booking(validForm([$field => $value]), 40);
        contains($errors[$field] ?? '', $message, $field);
    }
});

/* Occupation des créneaux ------------------------------------------------ */

function slotNov3(): array
{
    return Schedule::findSlot('2026-11-03', '0900', Config::get()->booking());
}

function event(string $summary, array $extra = []): array
{
    return $extra + ['id' => 'e' . random_int(1, 1_000_000), 'status' => 'confirmed', 'summary' => $summary, 'start' => strtotime('2026-11-03T08:00:00Z'),
        'end' => strtotime('2026-11-03T10:00:00Z'), 'allDay' => false, 'transparent' => false, 'props' => []];
}

function row(string $reference, array $extra = []): array
{
    return $extra + ['reference' => $reference, 'start_ts' => strtotime('2026-11-03T08:00:00Z'), 'end_ts' => strtotime('2026-11-03T10:00:00Z'),
        'google_status' => 'off', 'synced_ts' => null];
}

test('deux places par créneau', function () {
    eq(Availability::evaluate(slotNov3(), [row('A')], null, null, 2)['available'], true);
    eq(Availability::evaluate(slotNov3(), [row('A'), row('B')], null, null, 2)['available'], false);
});

test("un événement « Fermé » ou sur toute la journée ferme le créneau, « Disponible » ne compte pas", function () {
    eq(Availability::evaluate(slotNov3(), [], [event('FERMÉ')], 0.0, 2)['blocked'], true);
    eq(Availability::evaluate(slotNov3(), [], [event('Congé atelier')], 0.0, 2)['blocked'], true);
    eq(Availability::evaluate(slotNov3(), [], [event('Vacances', ['allDay' => true])], 0.0, 2)['blocked'], true);
    eq(Availability::evaluate(slotNov3(), [], [event('RDV client', ['transparent' => true])], 0.0, 2)['taken'], 0);
    eq(Availability::evaluate(slotNov3(), [], [event('RDV client')], 0.0, 2)['taken'], 1);
});

test('une réservation du site ne ferme jamais le créneau, même au nom « Garage Complet »', function () {
    $own = event('ENLÈVEMENT — Garage Complet — 4 jantes', ['props' => ['bwc' => '1', 'bwcRef' => 'BWC-AAAA-AAAA']]);
    $result = Availability::evaluate(slotNov3(), [row('BWC-AAAA-AAAA', ['google_status' => 'synced', 'synced_ts' => 10.0])], [$own], 100.0, 2);
    eq($result['blocked'], false);
    eq($result['taken'], 1, 'comptée une seule fois');
});

test("une réservation déplacée ou supprimée dans l'agenda libère sa place", function () {
    $synced = row('BWC-MOVE-D000', ['google_status' => 'synced', 'synced_ts' => 10.0]);
    eq(Availability::evaluate(slotNov3(), [$synced], [], 100.0, 2)['taken'], 0, 'absente de l\'agenda lu après la copie');
    eq(Availability::evaluate(slotNov3(), [$synced], null, null, 2)['taken'], 1, 'sans agenda, la base fait foi');
    $fresh = row('BWC-FRES-H000', ['google_status' => 'synced', 'synced_ts' => 99.5]);
    eq(Availability::evaluate(slotNov3(), [$fresh], [], 100.0, 2)['taken'], 1, 'copiée pendant la lecture : on la compte');
    $pending = row('BWC-PEND-0000', ['google_status' => 'pending']);
    eq(Availability::evaluate(slotNov3(), [$pending], [], 100.0, 2)['taken'], 1, 'pas encore copiée');
    $movedHere = event('ENLÈVEMENT — Garage X — 2 jantes', ['props' => ['bwc' => '1', 'bwcRef' => 'BWC-ELSE-WHER']]);
    eq(Availability::evaluate(slotNov3(), [], [$movedHere], 100.0, 2)['taken'], 1, 'déplacée vers ce créneau');
});

/* Emails ---------------------------------------------------------------- */

function sampleBooking(array $overrides = []): array
{
    return $overrides + [
        'id' => 1, 'reference' => 'BWC-7K4M-2QX9', 'slot_date' => '2026-11-03', 'start_time' => '09:00', 'end_time' => '11:00',
        'slot_mode' => 'ranges', 'garage_name' => 'Garage <Dupont>', 'contact_name' => 'Marie Dupont', 'phone' => '+32470123456',
        'email' => 'atelier@garage-dupont.be', 'street' => 'Rue de la Station 12', 'postal_code' => '1000', 'city' => 'Bruxelles',
        'rim_count' => 4, 'garage_reference' => 'DOSSIER-2041', 'comment' => "Avec pneus\nAccès arrière", 'photo_count' => 2,
        'google_status' => 'synced', 'google_link' => 'https://calendar.google.com/x', 'garage_mail' => 'sent',
        'start_ts' => strtotime('2026-11-03T08:00:00Z'), 'end_ts' => strtotime('2026-11-03T10:00:00Z'),
    ];
}

test('email de confirmation au garage', function () {
    $email = Templates::garageConfirmation(sampleBooking(), ['appUrl' => 'https://b2b.example', 'contactPhone' => '+32 2 523 20 02', 'contactEmail' => '']);
    eq($email['subject'], 'Confirmation de votre enlèvement — BWC-7K4M-2QX9');
    contains($email['html'], 'Garage &lt;Dupont&gt;', 'texte échappé');
    contains($email['html'], 'Mardi 3 novembre 2026');
    contains($email['html'], 'DOSSIER-2041');
    contains($email['html'], "2 photos transmises à l&apos;atelier");
    contains($email['text'], 'ou appelez-nous au +32 2 523 20 02');
    contains($email['html'], 'https://b2b.example/#confidentialite');
});

test('notification VBM avec photos jointes, ou à demander après un renvoi', function () {
    $ctx = ['appUrl' => 'https://b2b.example', 'contactPhone' => '', 'contactEmail' => ''];
    $first = Templates::vbmNotification(sampleBooking(), $ctx, true);
    contains($first['html'], '2 photos jointes à cet email');
    contains($first['html'], 'Ouvrir dans Google Agenda');
    contains($first['text'], 'Réf. du garage : DOSSIER-2041');
    $retry = Templates::vbmNotification(sampleBooking(['garage_mail' => 'pending']), $ctx, false);
    contains($retry['html'], 'demandez-les au garage');
    contains($retry['html'], "n'a pas encore pu être envoyé au garage");
});

test('message SMTP : objet encodé, texte, HTML et pièce jointe', function () {
    $message = Mime::build([
        'to' => ['Atelier <atelier@garage.be>'], 'subject' => 'Nouvel enlèvement — Garage Été', 'html' => '<p>Bonjour</p>', 'text' => "Bonjour\n.ligne avec point",
        'reply_to' => 'garage@example.be', 'attachments' => [['filename' => 'photo-1.jpg', 'content_type' => 'image/jpeg', 'content' => "\xFF\xD8\xFFdata"]],
        'idempotency_key' => 'k',
    ], 'B2B Wheels Collect <reservations@b2b.example>');
    ok(preg_match('/^Subject: .*=\?UTF-8\?B\?/m', $message) === 1, 'objet encodé en UTF-8');
    contains($message, 'From: "B2B Wheels Collect" <reservations@b2b.example>');
    contains($message, 'Reply-To: garage@example.be');
    contains($message, 'Content-Type: multipart/mixed');
    contains($message, 'filename="photo-1.jpg"');
    ok(!preg_match('/(?<!\r)\n/', $message), 'fins de ligne CRLF uniquement');
});
