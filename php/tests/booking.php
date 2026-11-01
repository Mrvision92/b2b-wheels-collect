<?php

declare(strict_types=1);

use Bwc\Availability;
use Bwc\Calendar\CalendarUnavailable;
use Bwc\Config;
use Bwc\Database;
use Bwc\Mail\MailerException;
use Bwc\Stats;

/* Réservations du site (base MySQL de test) ------------------------------ */

test('une réservation est enregistrée, rattachée à un garage, copiée dans l\'agenda et confirmée', function () {
    resetDatabase();
    $calendar = new FakeCalendar();
    $mailer = new FakeMailer();
    [$service, $notifications, , $garages] = services($calendar, $mailer);

    $result = $service->create(bookingInput(['garageReference' => 'DOSSIER-1', 'comment' => 'Avec pneus']), 1);
    eq($result['ok'], true);
    eq($result['garageEmail'], 'sent');
    $b = $result['booking'];
    eq([$b['status'], $b['source'], $b['google_status']], ['confirmed', 'public', 'synced']);
    eq(gmdate('c', $b['start_ts']), '2026-11-03T08:00:00+00:00');
    eq(array_values($calendar->events)[0]['props'], ['bwc' => '1', 'bwcRef' => $b['reference']]);
    eq($garages->find($b['garage_id'])['email'], strtolower($b['email']));

    $vbm = $notifications->send($b, 'vbm_notification', null, '', [['filename' => 'photo-1.jpg', 'content_type' => 'image/jpeg', 'content' => "\xFF\xD8\xFF"]]);
    eq($vbm['status'], 'sent');
    eq(count($mailer->sent[1]['attachments']), 1, 'photo jointe');
    eq($mailer->sent[1]['reply_to'], $b['email']);
    eq(emailStates($b['id']), ['vbm_notification' => 'sent', 'garage_confirmation' => 'sent']);
    eq(Database::pdo()->query('SELECT action FROM bwc_booking_history')->fetchColumn(), 'created');
});

test('le même envoi deux fois ne crée qu\'une réservation et un email', function () {
    resetDatabase();
    $mailer = new FakeMailer();
    [$service] = services(null, $mailer);
    $input = bookingInput();
    $first = $service->create($input);
    $second = $service->create($input);
    eq([$second['ok'], $second['alreadyExisted']], [true, true]);
    eq($second['booking']['reference'], $first['booking']['reference']);
    eq((int) Database::pdo()->query('SELECT COUNT(*) FROM bwc_bookings')->fetchColumn(), 1);
    eq(count($mailer->sent), 1);
});

test('le même garage qui renvoie le formulaire sur le même créneau retrouve sa réservation', function () {
    resetDatabase();
    [$service] = services(null, new FakeMailer());
    $first = $service->create(bookingInput(['email' => 'atelier@dupont.be', 'street' => 'Rue Haute 5']));
    $again = $service->create(bookingInput(['email' => 'ATELIER@dupont.be', 'street' => 'rue haute, 5']));
    eq($again['alreadyExisted'], true);
    eq($again['booking']['reference'], $first['booking']['reference']);
});

test('troisième garage sur un créneau de deux places refusé, l\'enlevé garde sa place, l\'annulé la libère', function () {
    resetDatabase();
    [$service] = services(null, new FakeMailer());
    $a = $service->create(bookingInput());
    $service->create(bookingInput());
    eq($service->create(bookingInput())['code'], 'slot_unavailable');
    $service->complete($a['booking']['id'], 1);
    eq($service->create(bookingInput())['code'], 'slot_unavailable', 'enlevé : la place reste prise');
    $service->cancel($a['booking']['id'], 1);
    eq($service->create(bookingInput())['code'], 'slot_unavailable', 'un enlèvement effectué ne peut plus être annulé');
    $b = $service->create(bookingInput(['slot' => '1100']));
    $service->create(bookingInput(['slot' => '1100']));
    $service->cancel($b['booking']['id'], 1, 'Doublon');
    eq($service->create(bookingInput(['slot' => '1100']))['ok'], true, 'annulé : la place est libérée');
});

test('créneau passé, trop proche, fermé ou inexistant refusé au public', function () {
    resetDatabase();
    [$service] = services(null, new FakeMailer());
    eq($service->create(bookingInput(['date' => '2026-11-02']))['code'], 'slot_closed', 'le jour même');
    eq($service->create(bookingInput(['date' => '2026-11-07']))['code'], 'slot_closed', 'samedi');
    eq($service->create(bookingInput(['slot' => '1000']))['code'], 'slot_closed', 'heure hors grille');
});

test('VBM peut saisir un enlèvement pour plus tard dans la journée, sans email VBM', function () {
    resetDatabase();
    $mailer = new FakeMailer();
    [$service] = services(null, $mailer);
    $result = $service->create(bookingInput(['date' => '2026-11-02', 'slot' => '1300', 'email' => '']), 0, ['source' => 'admin', 'adminId' => 1]);
    eq($result['ok'], true);
    eq([$result['booking']['source'], $result['booking']['created_by'], $result['garageEmail']], ['admin', 1, 'skipped']);
    eq($mailer->sent, [], 'pas d\'email : ni adresse du garage, ni notification à VBM');
    eq($service->create(bookingInput(['date' => '2026-10-30', 'slot' => '1500']), 0, ['source' => 'admin'])['code'], 'slot_closed', 'créneau déjà passé');
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

test('copie Google en échec : la réservation tient, la place reste prise, la copie est reprise', function () {
    resetDatabase();
    $calendar = new FakeCalendar();
    $calendar->failInsert = true;
    [$service, $notifications, $store] = services($calendar, new FakeMailer());
    $result = $service->create(bookingInput());
    eq([$result['ok'], $result['booking']['google_status']], [true, 'pending']);
    $rows = $store->activeBetween($result['booking']['start_ts'], $result['booking']['end_ts']);
    eq(Availability::evaluate(slotNov3(), $rows, $calendar->listEvents(0, PHP_INT_MAX), 2)['taken'], 1);

    $calendar->failInsert = false;
    Database::pdo()->exec('UPDATE bwc_bookings SET updated_at = updated_at - INTERVAL 10 MINUTE');
    eq($notifications->retryPending()['calendar'], 1);
    eq($store->find($result['booking']['id'])['google_status'], 'synced');
});

/* Administration : statuts et emails ------------------------------------- */

test('« Marquer enlevé » : date réelle, administrateur, historique et un seul email même après plusieurs clics', function () {
    resetDatabase();
    $calendar = new FakeCalendar();
    $mailer = new FakeMailer();
    [$service, , $store] = services($calendar, $mailer);
    $b = $service->create(bookingInput(['contactName' => 'Marie Dupont']))['booking'];

    $first = $service->complete($b['id'], 7);
    $second = $service->complete($b['id'], 7);
    eq([$first['changed'], $second['changed']], [true, false]);
    $done = $store->find($b['id']);
    eq([$done['status'], $done['completed_by']], ['completed', 7]);
    ok($done['completed_at'] !== null, 'date réelle enregistrée');
    eq(array_count_values($mailer->subjects())["Confirmation d'enlèvement de vos jantes — B2B Wheels Collect"] ?? 0, 1, 'un seul email');
    eq(emailStates($b['id'])['pickup_completed'], 'sent');
    $event = array_values($calendar->events)[0];
    ok(str_starts_with($event['summary'], '✓ ENLEVÉ — '), 'événement marqué enlevé');
    eq($event['colorId'], '10');
    $history = $store->history($b['id']);
    eq([$history[0]['action'], $history[0]['from_status'], $history[0]['to_status'], (int) $history[0]['admin_id']], ['completed', 'confirmed', 'completed', 7]);
    eq($service->cancel($b['id'], 7)['code'], 'invalid_status', 'plus annulable');
});

test('email d\'enlèvement en échec : l\'enlèvement reste enregistré, renvoi possible sans doublon', function () {
    resetDatabase();
    $mailer = new FakeMailer();
    [$service, $notifications, $store] = services(null, $mailer);
    $b = $service->create(bookingInput())['booking'];
    $mailer->failure = new MailerException('serveur SMTP indisponible (test)', false);
    $result = $service->complete($b['id'], 1);
    eq([$result['ok'], $result['notification']['status']], [true, 'pending']);
    eq($store->find($b['id'])['status'], 'completed');
    contains((string) $result['notification']['last_error'], 'indisponible');

    $mailer->failure = null;
    eq($notifications->retryNow((int) $result['notification']['id'])['status'], 'sent');
    eq($notifications->retryNow((int) $result['notification']['id']), null, 'déjà envoyé : rien à renvoyer');
    eq($notifications->retryPending()['emails'], 0);
    eq(count(array_filter($mailer->subjects(), static fn ($s) => str_starts_with($s, "Confirmation d'enlèvement"))), 1);
});

test('renvoi automatique des emails : 12 essais puis abandon', function () {
    resetDatabase();
    $mailer = new FakeMailer();
    $mailer->failure = new MailerException('boîte pleine (test)', false);
    [$service, $notifications] = services(null, $mailer);
    $b = $service->create(bookingInput())['booking'];
    eq(emailStates($b['id'])['garage_confirmation'], 'pending');
    Database::pdo()->exec("UPDATE bwc_notifications SET created_at = created_at - INTERVAL 10 MINUTE, attempts = 11");
    $notifications->retryPending();
    eq(emailStates($b['id'])['garage_confirmation'], 'failed');
});

test('changement de créneau : places vérifiées, historique, un email au garage, événement déplacé', function () {
    resetDatabase();
    $calendar = new FakeCalendar();
    $mailer = new FakeMailer();
    [$service, , $store] = services($calendar, $mailer);
    $b = $service->create(bookingInput())['booking'];
    $service->create(bookingInput(['slot' => '1100']));
    $service->create(bookingInput(['slot' => '1100']));

    $input = bookingInput(['requestId' => '00000000-0000-4000-8000-000000000000'] + array_intersect_key(
        ['garageName' => $b['garage_name'], 'contactName' => $b['contact_name'], 'phone' => $b['phone'], 'email' => $b['email'], 'street' => $b['street']],
        array_flip(['garageName', 'contactName', 'phone', 'email', 'street']),
    ));
    eq($service->update($b['id'], ['slot' => '1100'] + $input, 2)['code'], 'slot_unavailable', 'créneau complet');
    $moved = $service->update($b['id'], ['date' => '2026-11-04', 'slot' => '1300', 'rimCount' => 6] + $input, 2);
    eq($moved['ok'], true);
    $row = $store->find($b['id']);
    eq([$row['slot_date'], $row['start_time'], $row['rim_count']], ['2026-11-04', '13:00', 6]);
    contains($store->history($b['id'])[0]['details'], 'Déplacé du mardi 3 novembre, 9 h – 11 h au mercredi 4 novembre, 13 h – 15 h');
    contains($store->history($b['id'])[0]['details'], 'nombre de jantes');
    eq($moved['notification']['status'], 'sent');
    eq(gmdate('c', $calendar->events[$b['google_event_id']]['start']), '2026-11-04T12:00:00+00:00');
    eq($service->create(bookingInput())['ok'], true, 'l\'ancienne place est libérée');
});

test('annulation : motif, historique, email au garage, événement supprimé, ligne conservée', function () {
    resetDatabase();
    $calendar = new FakeCalendar();
    $mailer = new FakeMailer();
    [$service, , $store] = services($calendar, $mailer);
    $b = $service->create(bookingInput())['booking'];
    $result = $service->cancel($b['id'], 3, 'Garage fermé ce jour-là');
    eq([$result['ok'], $result['notification']['status']], [true, 'sent']);
    $row = $store->find($b['id']);
    eq([$row['status'], $row['cancel_reason'], $row['cancelled_by']], ['cancelled', 'Garage fermé ce jour-là', 3]);
    eq($calendar->events, [], 'événement supprimé');
    contains(end($mailer->sent)['text'], 'Motif : Garage fermé ce jour-là');
    eq($service->update($b['id'], bookingInput(), 3)['code'], 'not_editable');
});

test('réservation en attente : pas de confirmation ni d\'événement avant validation par VBM', function () {
    resetDatabase();
    $calendar = new FakeCalendar();
    $mailer = new FakeMailer();
    [$service, , $store] = services($calendar, $mailer);
    $b = $service->create(bookingInput(), 0, ['source' => 'admin', 'adminId' => 1, 'status' => 'pending'])['booking'];
    eq([$b['status'], $calendar->events, $mailer->sent], ['pending', [], []]);
    $confirmed = $service->confirm($b['id'], 1);
    eq([$confirmed['booking']['status'], $confirmed['notification']['status']], ['confirmed', 'sent']);
    eq(count($calendar->events), 1);
    eq($service->confirm($b['id'], 1)['changed'], false);
});

/* Annuaire des garages --------------------------------------------------- */

test('garages : même email = même garage ; nom proche mais autre email = autre garage ; fusion manuelle', function () {
    resetDatabase();
    [$service, , $store, $garages] = services(null, new FakeMailer());
    $a = $service->create(bookingInput(['garageName' => 'Garage Dupont', 'email' => 'atelier@dupont.be']))['booking'];
    $b = $service->create(bookingInput(['garageName' => 'Garage Dupont SRL', 'contactName' => 'Paul Dupont', 'email' => 'ATELIER@dupont.be', 'date' => '2026-11-05']))['booking'];
    $c = $service->create(bookingInput(['garageName' => 'Garage Dupond', 'email' => 'contact@dupond.be']))['booking'];
    eq($a['garage_id'], $b['garage_id']);
    ok($c['garage_id'] !== $a['garage_id'], 'pas de fusion sur un nom proche');
    eq($garages->find($a['garage_id'])['name'], 'Garage Dupont', 'la fiche n\'est pas écrasée par une réservation');

    $service->complete($a['id'], 1);
    $activity = $garages->activity($a['garage_id']);
    eq([(int) $activity['bookings_total'], (int) $activity['completed_total'], (int) $activity['rims_total']], [2, 1, 4]);
    ok($activity['next_starts_at'] !== null, 'prochain enlèvement connu');
    eq(count($garages->variants($garages->find($a['garage_id']))['contacts']), 1, 'l\'autre contact utilisé apparaît');

    $garages->merge($c['garage_id'], $a['garage_id']);
    eq($store->find($c['id'])['garage_id'], $a['garage_id']);
    eq($garages->find($c['garage_id']), null);
    eq($garages->search('dupon', 1)['total'], 1);
});

test('VBM choisit un garage existant et met sa fiche à jour', function () {
    resetDatabase();
    [$service, , , $garages] = services(null, new FakeMailer());
    $first = $service->create(bookingInput(['email' => 'atelier@x.be']))['booking'];
    $second = $service->create(bookingInput(['email' => '', 'phone' => '+32470999999', 'contactName' => 'Paul Martin']), 0, [
        'source' => 'admin', 'adminId' => 1, 'garageId' => $first['garage_id'], 'updateGarage' => true,
    ])['booking'];
    eq($second['garage_id'], $first['garage_id']);
    eq($garages->find($first['garage_id'])['contact_name'], 'Paul Martin');
});

/* Statistiques ----------------------------------------------------------- */

test('statistiques : enlevés comptés à leur date réelle, annulés exclus, comparaison à la même période', function () {
    resetDatabase();
    [$service] = services(null, new FakeMailer());
    $pdo = Database::pdo();
    $ids = [];
    foreach (['2026-11-03', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-09'] as $i => $date) {
        $ids[] = $service->create(bookingInput(['date' => $date, 'slot' => '1300', 'rimCount' => $i + 1]))['booking']['id'];
    }
    // Deux enlevés en novembre ; en octobre, un le 2 (même période) et un le 31 à 23 h 30 à Bruxelles ; un annulé.
    $pdo->exec("UPDATE bwc_bookings SET status = 'completed', completed_at = '2026-11-03 12:00:00' WHERE id IN ({$ids[0]}, {$ids[1]})");
    $pdo->exec("UPDATE bwc_bookings SET status = 'completed', completed_at = '2026-10-31 22:30:00' WHERE id = {$ids[2]}");
    $pdo->exec("UPDATE bwc_bookings SET status = 'completed', completed_at = '2026-10-02 09:00:00' WHERE id = {$ids[5]}");
    $pdo->exec("UPDATE bwc_bookings SET status = 'cancelled' WHERE id = {$ids[3]}");

    $now = strtotime('2026-11-03T10:00:00Z');
    $stats = (new Stats($pdo))->dashboard($now);
    eq($stats['todayCount'], 2, 'aujourd\'hui');
    eq($stats['upcomingCount'], 1, 'à venir : seul le 6 novembre reste à faire');
    eq([$stats['completed'], $stats['rims']], [2, 3], 'novembre');
    eq($stats['previousCompleted'], 1, 'du 1er au 3 octobre à la même heure');
    eq($stats['change'], 100.0);
    eq(array_column($stats['chart'], 'completed'), [0, 0, 0, 0, 2, 2], 'le 31 octobre 23 h 30 à Bruxelles compte en octobre');
    eq($stats['chart'][5]['label'], 'Nov.');
    eq($stats['received'], 6);
    $october = (new Stats($pdo))->dashboard($now, '2026-10');
    eq([$october['completed'], $october['isCurrentMonth']], [2, false], 'mois passé choisi : mois entier');
});

test('le calendrier du mois ne montre que des disponibilités', function () {
    resetDatabase();
    [$service, , $store] = services(null, new FakeMailer());
    $service->create(bookingInput());
    $service->create(bookingInput());
    $month = Availability::month('2026-11', Config::get()->booking(), $store, null, Tests::NOW);
    eq($month['days'][0]['date'], '2026-11-03');
    eq($month['days'][0]['slots'][0], ['key' => '0900', 'label' => '9 h – 11 h', 'available' => false]);
    ok(!in_array('2026-11-11', array_column($month['days'], 'date'), true), 'Armistice fermé');
    ok(!str_contains(json_encode($month), 'Garage'), 'aucune donnée de garage');
    $admin = Availability::daySlotsForAdmin('2026-11-03', Config::get()->booking(), $store, null, Tests::NOW);
    eq([$admin[0]['available'], $admin[0]['remaining'], $admin[1]['remaining']], [false, 0, 2]);
});
