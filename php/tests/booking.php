<?php

declare(strict_types=1);

use Bwc\Availability;
use Bwc\Calendar\CalendarUnavailable;
use Bwc\Config;
use Bwc\Database;
use Bwc\Mail\MailerException;

/* Réservations (base MySQL de test) -------------------------------------- */

test('une réservation est enregistrée, copiée dans l\'agenda et confirmée au garage', function () {
    resetDatabase();
    $calendar = new FakeCalendar();
    $mailer = new FakeMailer();
    [$service, $notifications] = services($calendar, $mailer);

    $result = $service->create(bookingInput(['garageReference' => 'DOSSIER-1', 'comment' => 'Avec pneus']), 1);
    eq($result['ok'], true);
    eq($result['alreadyExisted'], false);
    eq($result['garageEmail'], 'sent');
    $b = $result['booking'];
    ok(preg_match('/^BWC-/', $b['reference']) === 1, 'référence');
    eq($b['google_status'], 'synced');
    eq($b['start_time'], '09:00');
    eq(gmdate('c', $b['start_ts']), '2026-11-03T08:00:00+00:00');
    eq(count($calendar->events), 1);
    $event = array_values($calendar->events)[0];
    eq($event['props'], ['bwc' => '1', 'bwcRef' => $b['reference']]);
    eq($mailer->sent[0]['to'], [$b['email']]);

    $notifications->sendVbmNotification($b, [['filename' => 'photo-1.jpg', 'content_type' => 'image/jpeg', 'content' => "\xFF\xD8\xFF"]]);
    eq(count($mailer->sent), 2);
    eq(count($mailer->sent[1]['attachments']), 1, 'photo jointe');
    eq($mailer->sent[1]['reply_to'], $b['email']);
    $row = Database::pdo()->query('SELECT garage_mail, vbm_mail, photo_count, garage_reference FROM bwc_bookings')->fetch();
    eq($row, ['garage_mail' => 'sent', 'vbm_mail' => 'sent', 'photo_count' => 1, 'garage_reference' => 'DOSSIER-1']);
});

test('le même envoi deux fois ne crée qu\'une réservation', function () {
    resetDatabase();
    $mailer = new FakeMailer();
    [$service] = services(null, $mailer);
    $input = bookingInput();
    $first = $service->create($input);
    $second = $service->create($input);
    eq($second['ok'], true);
    eq($second['alreadyExisted'], true);
    eq($second['booking']['reference'], $first['booking']['reference']);
    eq((int) Database::pdo()->query('SELECT COUNT(*) FROM bwc_bookings')->fetchColumn(), 1);
    eq(count($mailer->sent), 1, 'un seul email');
});

test('le même garage qui renvoie le formulaire sur le même créneau retrouve sa réservation', function () {
    resetDatabase();
    [$service] = services(null, new FakeMailer());
    $first = $service->create(bookingInput(['email' => 'atelier@dupont.be', 'street' => 'Rue Haute 5']));
    $again = $service->create(bookingInput(['email' => 'ATELIER@dupont.be', 'street' => 'rue haute, 5']));
    eq($again['alreadyExisted'], true);
    eq($again['booking']['reference'], $first['booking']['reference']);
});

test('troisième garage sur un créneau de deux places refusé', function () {
    resetDatabase();
    [$service] = services(null, new FakeMailer());
    eq($service->create(bookingInput())['ok'], true);
    eq($service->create(bookingInput())['ok'], true);
    $third = $service->create(bookingInput());
    eq($third['ok'], false);
    eq($third['code'], 'slot_unavailable');
    eq($service->create(bookingInput(['slot' => '1100']))['ok'], true, 'le créneau suivant reste libre');
});

test('créneau passé, trop proche, fermé ou inexistant refusé', function () {
    resetDatabase();
    [$service] = services(null, new FakeMailer());
    eq($service->create(bookingInput(['date' => '2026-11-02']))['code'], 'slot_closed', 'le jour même');
    eq($service->create(bookingInput(['date' => '2026-11-07']))['code'], 'slot_closed', 'samedi');
    eq($service->create(bookingInput(['slot' => '1000']))['code'], 'slot_closed', 'heure hors grille');
});

test('les rendez-vous et fermetures de l\'agenda Google sont respectés', function () {
    resetDatabase();
    $calendar = new FakeCalendar();
    $calendar->add('Livraison pneus', '2026-11-03T08:00:00Z', '2026-11-03T10:00:00Z');
    $calendar->add('Contrôle technique camionnette', '2026-11-03T08:30:00Z', '2026-11-03T09:30:00Z');
    $calendar->add('FERMÉ', '2026-11-03T10:00:00Z', '2026-11-03T12:00:00Z');
    [$service] = services($calendar, new FakeMailer());
    eq($service->create(bookingInput())['code'], 'slot_unavailable', 'deux rendez-vous manuels');
    eq($service->create(bookingInput(['slot' => '1100']))['code'], 'slot_unavailable', 'créneau fermé');
    eq($service->create(bookingInput(['slot' => '1300']))['ok'], true);
});

test('agenda injoignable : rien n\'est réservé ni confirmé', function () {
    resetDatabase();
    $calendar = new FakeCalendar();
    $calendar->failList = true;
    $mailer = new FakeMailer();
    [$service] = services($calendar, $mailer);
    try {
        $service->create(bookingInput());
        throw new AssertionError('une erreur était attendue');
    } catch (CalendarUnavailable) {
    }
    eq((int) Database::pdo()->query('SELECT COUNT(*) FROM bwc_bookings')->fetchColumn(), 0);
    eq($mailer->sent, []);
});

test('copie dans l\'agenda en échec : la réservation tient et sera reprise', function () {
    resetDatabase();
    $calendar = new FakeCalendar();
    $calendar->failInsert = true;
    [$service, $notifications, $store] = services($calendar, new FakeMailer());
    $result = $service->create(bookingInput());
    eq($result['ok'], true);
    eq($result['booking']['google_status'], 'pending');

    // Encore pending : la place reste prise pour les autres garages.
    $events = $calendar->listEvents(0, PHP_INT_MAX);
    $rows = $store->activeBetween($result['booking']['start_ts'], $result['booking']['end_ts']);
    eq(Availability::evaluate(slotNov3(), $rows, $events, microtime(true), 2)['taken'], 1);

    $calendar->failInsert = false;
    Database::pdo()->exec('UPDATE bwc_bookings SET created_at = created_at - INTERVAL 10 MINUTE');
    $summary = $notifications->retryPending();
    eq($summary['calendar'], 1);
    eq($store->findByReference($result['booking']['reference'])['google_status'], 'synced');
    eq(count($calendar->events), 1);
});

test('email en échec : réservation gardée, renvoi automatique sans doublon puis abandon après 12 essais', function () {
    resetDatabase();
    $mailer = new FakeMailer();
    $mailer->failure = new MailerException('boîte pleine (test)', false);
    [$service, $notifications, $store] = services(null, $mailer);
    $result = $service->create(bookingInput());
    eq($result['ok'], true);
    eq($result['garageEmail'], 'pending');

    Database::pdo()->exec('UPDATE bwc_bookings SET created_at = created_at - INTERVAL 10 MINUTE');
    $notifications->retryPending();
    $row = $store->findByReference($result['booking']['reference']);
    eq([$row['garage_mail'], $row['garage_mail_tries']], ['pending', 1]);

    $mailer->failure = null;
    $notifications->retryPending();
    $row = $store->findByReference($result['booking']['reference']);
    eq([$row['garage_mail'], $row['vbm_mail']], ['sent', 'sent']);
    eq(count($mailer->sent), 2, 'garage + VBM');
    $notifications->retryPending();
    eq(count($mailer->sent), 2, 'rien de plus au passage suivant');

    $mailer->failure = new MailerException('refus (test)', false);
    Database::pdo()->exec("UPDATE bwc_bookings SET garage_mail = 'pending', garage_mail_tries = 11");
    $notifications->retryPending();
    eq($store->findByReference($result['booking']['reference'])['garage_mail'], 'failed');
});

test('une réservation annulée dans la base libère sa place', function () {
    resetDatabase();
    [$service] = services(null, new FakeMailer());
    $a = $service->create(bookingInput());
    $service->create(bookingInput());
    eq($service->create(bookingInput())['ok'], false);
    Database::pdo()->prepare("UPDATE bwc_bookings SET status = 'cancelled' WHERE reference = ?")->execute([$a['booking']['reference']]);
    eq($service->create(bookingInput())['ok'], true);
});

test('le calendrier du mois ne montre que des disponibilités', function () {
    resetDatabase();
    [$service, , $store] = services(null, new FakeMailer());
    $service->create(bookingInput());
    $service->create(bookingInput());
    $month = Availability::month('2026-11', Config::get()->booking(), $store, null, Tests::NOW);
    eq($month['firstMonth'], '2026-11');
    eq($month['lastMonth'], '2027-01');
    eq($month['days'][0]['date'], '2026-11-03', 'premier jour réservable');
    eq($month['days'][0]['slots'][0], ['key' => '0900', 'label' => '9 h – 11 h', 'available' => false]);
    eq($month['days'][0]['slots'][1]['available'], true);
    ok(!in_array('2026-11-11', array_column($month['days'], 'date'), true), 'Armistice fermé');
    ok(!str_contains(json_encode($month), 'Garage'), 'aucune donnée de garage');
});
