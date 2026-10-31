<?php

declare(strict_types=1);

use Bwc\Mail\MailerException;
use Bwc\Mail\SmtpMailer;

/* Adresses publiques, via un vrai serveur PHP ---------------------------- */

const HTTP_PORT = 3302;
const SMTP_PORT = 3325;

function server(): string
{
    static $started = false;
    if (!$started) {
        $started = true;
        $root = dirname(__DIR__);
        Tests::startProcess(
            sprintf('exec php -S 127.0.0.1:%d -t public_html dev-router.php', HTTP_PORT),
            [
                'BWC_CONFIG_FILE' => __DIR__ . '/config.test.php',
                'BWC_STORAGE_DIR' => dirname($root) . '/.data/php-tests-http',
                'BWC_TEST_URL' => 'http://127.0.0.1:' . HTTP_PORT,
                'PHP_CLI_SERVER_WORKERS' => '8',
            ],
            HTTP_PORT,
        );
    }
    return 'http://127.0.0.1:' . HTTP_PORT;
}

/** @return array{status: int, json: array<string, mixed>|null, body: string} */
function http(string $method, string $path, array $options = []): array
{
    $curl = curl_init(server() . $path);
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $options['headers'] ?? []]);
    if (isset($options['form'])) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $options['form']);
    }
    $body = (string) curl_exec($curl);
    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'json' => json_decode($body, true), 'body' => $body];
}

/** Premier jour et créneau libres selon le serveur (heure réelle). */
function firstFreeSlot(): array
{
    $month = http('GET', '/api/availability.php')['json'];
    foreach ([$month, http('GET', '/api/availability.php?month=' . date('Y-m', strtotime($month['month'] . '-01 +1 month')))['json']] as $data) {
        foreach ($data['days'] as $day) {
            foreach ($day['slots'] as $slot) {
                if ($slot['available']) {
                    return [$day['date'], $slot['key']];
                }
            }
        }
    }
    throw new RuntimeException('aucun créneau libre');
}

function formFor(array $data, array $photos = []): array
{
    $form = ['data' => json_encode($data)];
    foreach ($photos as $i => [$name, $type, $content]) {
        $file = tempnam(sys_get_temp_dir(), 'bwc');
        file_put_contents($file, $content);
        $form["photos[$i]"] = new CURLFile($file, $type, $name);
    }
    return $form;
}

function pngBytes(): string
{
    $image = imagecreatetruecolor(40, 30);
    ob_start();
    imagepng($image);
    return (string) ob_get_clean();
}

test('six garages sur le même créneau au même instant : exactement deux réservations', function () {
    resetDatabase();
    [$date, $slot] = firstFreeSlot();
    $multi = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < 6; $i++) {
        $input = bookingInput(['date' => $date, 'slot' => $slot, 'phone' => '0470 12 34 56', 'rimCount' => '2']);
        $curl = curl_init(server() . '/api/bookings.php');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => formFor($input)]);
        curl_multi_add_handle($multi, $curl);
        $handles[] = $curl;
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi);
    } while ($running > 0);
    $statuses = array_map(static fn ($curl): int => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $handles);
    sort($statuses);
    eq($statuses, [201, 201, 409, 409, 409, 409]);
    eq((int) Bwc\Database::pdo()->query("SELECT COUNT(*) FROM bwc_bookings WHERE status = 'confirmed'")->fetchColumn(), 2);
});

test('réservation avec photo, référence et email VBM consultable en démonstration', function () {
    resetDatabase();
    [$date, $slot] = firstFreeSlot();
    $input = bookingInput(['date' => $date, 'slot' => $slot, 'phone' => '0470 12 34 56', 'rimCount' => '3', 'garageReference' => 'DOSSIER-2041']);
    $response = http('POST', '/api/bookings.php', ['form' => formFor($input, [['jante.png', 'image/png', pngBytes()]])]);
    eq($response['status'], 201, $response['body']);
    eq($response['json']['booking']['photoCount'], 1);
    eq($response['json']['booking']['photosLabel'], '1 photo');
    eq($response['json']['booking']['garageReference'], 'DOSSIER-2041');
    eq($response['json']['emailStatus'], 'simulated');
    ok(!isset($response['json']['booking']['phone']), 'pas de données superflues');

    $vbm = '';
    for ($i = 0; $i < 40 && !str_contains($vbm, 'Pièces jointes'); $i++) {
        usleep(100_000);
        $vbm = http('GET', '/' . $response['json']['demo']['previews']['vbm'])['body'];
    }
    contains($vbm, 'Pièces jointes (1)');
    contains($vbm, 'photo-1.png');
    contains($vbm, 'DOSSIER-2041');
    $garage = http('GET', '/' . $response['json']['demo']['previews']['garage']);
    contains($garage['body'], 'Confirmation de votre enlèvement');

    $again = http('POST', '/api/bookings.php', ['form' => formFor($input)]);
    eq([$again['status'], $again['json']['alreadyExisted']], [200, true], 'renvoi du même formulaire');
});

test('formulaire incomplet : erreurs par champ, en français', function () {
    resetDatabase();
    $response = http('POST', '/api/bookings.php', ['form' => formFor(['requestId' => '6f1f0d4e-3a4b-4c1d-9e2f-1a2b3c4d5e6f', 'email' => 'x'])]);
    eq($response['status'], 400);
    eq($response['json']['fields']['garageName'], 'Indiquez le nom du garage.');
    contains($response['json']['fields']['email'], 'Adresse email invalide');
});

test('faux fichier image et trop de photos refusés', function () {
    resetDatabase();
    $input = bookingInput(['phone' => '0470 12 34 56', 'date' => '2026-11-03', 'slot' => '0900']);
    $pdf = http('POST', '/api/bookings.php', ['form' => formFor($input, [['jante.jpg', 'image/jpeg', '%PDF-1.7 pas une photo']])]);
    eq($pdf['status'], 400);
    contains($pdf['json']['fields']['photos'], 'Seules les photos');
    $six = array_fill(0, 6, ['p.png', 'image/png', pngBytes()]);
    $many = http('POST', '/api/bookings.php', ['form' => formFor($input, $six)]);
    eq($many['status'], 400);
    contains($many['json']['fields']['photos'], '5 photos maximum');
});

test('envoi depuis un autre site, champ piège et données illisibles refusés', function () {
    resetDatabase();
    eq(http('POST', '/api/bookings.php', ['form' => formFor(bookingInput()), 'headers' => ['Origin: https://autre-site.example']])['status'], 403);
    eq(http('POST', '/api/bookings.php', ['form' => formFor(bookingInput(['website' => 'http://spam.example']))])['json']['error'], 'rejected');
    eq(http('POST', '/api/bookings.php', ['form' => ['data' => '{pas du json']])['status'], 400);
    eq(http('GET', '/api/bookings.php')['status'], 405);
});

test('limite de tentatives par connexion', function () {
    resetDatabase();
    $statuses = [];
    for ($i = 0; $i < 13; $i++) {
        $statuses[] = http('POST', '/api/bookings.php', ['form' => ['data' => '{}']])['status'];
    }
    eq(array_count_values($statuses), [400 => 12, 429 => 1]);
    resetDatabase();
});

test('disponibilités : mois borné à la période réservable', function () {
    $current = http('GET', '/api/availability.php');
    eq($current['status'], 200);
    $far = http('GET', '/api/availability.php?month=2099-01')['json'];
    eq($far['month'], $current['json']['lastMonth']);
    eq(http('GET', '/api/availability.php?month=nimporte')['json']['month'], $current['json']['firstMonth']);
    eq(http('GET', '/api/health.php')['json']['database'], 'ok');
});

/* SMTP ------------------------------------------------------------------- */

function smtpInbox(): string
{
    static $dir = null;
    if ($dir === null) {
        $dir = dirname(__DIR__, 2) . '/.data/php-tests-smtp';
        @mkdir($dir, 0777, true);
        array_map('unlink', glob("$dir/*.eml") ?: []);
        Tests::startProcess(sprintf('exec php tests/fake-smtp.php %d %s', SMTP_PORT, escapeshellarg($dir)), [], SMTP_PORT);
    }
    return $dir;
}

test('envoi SMTP authentifié avec pièce jointe', function () {
    $inbox = smtpInbox();
    $mailer = new SmtpMailer(['host' => '127.0.0.1', 'port' => SMTP_PORT, 'encryption' => 'none', 'username' => 'user@b2b.example', 'password' => 'bon-mot-de-passe'], 'B2B Wheels Collect <user@b2b.example>');
    $mailer->send([
        'to' => ['vbm@example.be'], 'subject' => 'Nouvel enlèvement — Garage Été', 'html' => '<p>Bonjour</p>', 'text' => "Bonjour\n.point en debut de ligne",
        'reply_to' => 'garage@example.be', 'attachments' => [['filename' => 'photo-1.jpg', 'content_type' => 'image/jpeg', 'content' => "\xFF\xD8\xFFabc"]],
        'idempotency_key' => 'test',
    ]);
    $files = glob("$inbox/*.eml");
    eq(count($files), 1);
    $message = (string) file_get_contents($files[0]);
    contains($message, 'To: vbm@example.be');
    contains($message, 'filename="photo-1.jpg"');
    contains($message, "\r\n..point en debut de ligne", 'point doublé pendant le transfert');
});

test('mot de passe SMTP refusé : erreur définitive, pas de nouvel essai', function () {
    smtpInbox();
    $mailer = new SmtpMailer(['host' => '127.0.0.1', 'port' => SMTP_PORT, 'encryption' => 'none', 'username' => 'user@b2b.example', 'password' => 'faux'], 'user@b2b.example');
    try {
        $mailer->send(['to' => ['vbm@example.be'], 'subject' => 'x', 'html' => 'x', 'text' => 'x', 'attachments' => [], 'idempotency_key' => 'k']);
        throw new AssertionError('une erreur était attendue');
    } catch (MailerException $error) {
        eq($error->retryable, false);
        contains($error->getMessage(), 'AUTH');
        ok(!str_contains($error->getMessage(), 'faux'), 'le mot de passe n\'apparaît pas');
    }
});

test('serveur SMTP injoignable : erreur passagère, nouvel essai plus tard', function () {
    $mailer = new SmtpMailer(['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'username' => 'u', 'password' => 'p'], 'u@b2b.example');
    try {
        $mailer->send(['to' => ['vbm@example.be'], 'subject' => 'x', 'html' => 'x', 'text' => 'x', 'attachments' => [], 'idempotency_key' => 'k']);
        throw new AssertionError('une erreur était attendue');
    } catch (MailerException $error) {
        eq($error->retryable, true);
    }
});
