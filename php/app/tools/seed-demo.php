<?php
/**
 * Données fictives pour une démonstration ou des captures d'écran :
 *
 *   php app/tools/seed-demo.php            garages, enlèvements et photos fictifs
 *   php app/tools/seed-demo.php --photos   seulement des photos sur les prochains enlèvements
 *
 * Refuse de tourner hors du mode démonstration ('demo' => true dans
 * config.php) : les statistiques de production ne sont jamais faussées.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Bwc\App;
use Bwc\BookingStore;
use Bwc\Database;
use Bwc\Ids;
use Bwc\Schedule;
use Bwc\Time;

if (!App::config()->demo()) {
    fwrite(STDERR, "Refusé : réservé au mode démonstration.\n");
    exit(1);
}

/**
 * Photo de démonstration dessinée par le serveur : une jante avec une trace
 * de frottement, sous un angle différent selon $variant.
 */
function demoPhoto(int $variant): string
{
    $w = 1200;
    $h = 900;
    $image = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $shade = 196 - (int) ($y / $h * 40);
        imageline($image, 0, $y, $w, $y, imagecolorallocate($image, $shade, $shade, $shade - 4));
    }
    $cx = 600 + ($variant % 3 - 1) * 60;
    $cy = 450;
    imagefilledellipse($image, $cx, $cy, 760, 760, imagecolorallocate($image, 28, 28, 30));
    imagefilledellipse($image, $cx, $cy, 600, 600, imagecolorallocate($image, 192, 194, 198));
    imagefilledellipse($image, $cx, $cy, 520, 520, imagecolorallocate($image, 120, 122, 126));
    imagesetthickness($image, 46);
    $spoke = imagecolorallocate($image, 205, 207, 211);
    for ($i = 0; $i < 5; $i++) {
        $angle = deg2rad($i * 72 + $variant * 11);
        imageline($image, $cx, $cy, (int) ($cx + cos($angle) * 255), (int) ($cy + sin($angle) * 255), $spoke);
    }
    imagefilledellipse($image, $cx, $cy, 150, 150, imagecolorallocate($image, 210, 212, 216));
    imagefilledellipse($image, $cx, $cy, 60, 60, imagecolorallocate($image, 70, 70, 74));
    // Trace de frottement sur le bord de la jante.
    imagesetthickness($image, 5);
    $scratch = imagecolorallocate($image, 238, 238, 240);
    $start = 200 + $variant * 37;
    for ($i = 0; $i < 6; $i++) {
        imagearc($image, $cx, $cy, 584 - $i * 6, 584 - $i * 6, $start + $i * 3, $start + 40 + $i * 4, $scratch);
    }
    imagestring($image, 5, 24, $h - 36, 'Photo de demonstration - B2B Wheels Collect', imagecolorallocate($image, 60, 60, 64));
    ob_start();
    imagejpeg($image, null, 82);
    return (string) ob_get_clean();
}

/** Ajoute 1 à 3 photos à un enlèvement sur deux parmi les prochains. */
function addDemoPhotos(): int
{
    $pdo = Database::pdo();
    $rows = $pdo->query("SELECT id FROM bwc_bookings WHERE status IN ('pending', 'confirmed') AND photo_count = 0 ORDER BY starts_at LIMIT 12")->fetchAll(PDO::FETCH_COLUMN);
    $added = 0;
    foreach ($rows as $i => $id) {
        if ($i % 2 === 1) {
            continue;
        }
        $count = 1 + $i % 3;
        $photos = [];
        for ($n = 0; $n < $count; $n++) {
            $photos[] = ['filename' => 'photo-' . ($n + 1) . '.jpg', 'content_type' => 'image/jpeg', 'content' => demoPhoto((int) $id + $n)];
        }
        App::photos()->save((int) $id, $photos);
        $pdo->prepare('UPDATE bwc_bookings SET photo_count = ? WHERE id = ?')->execute([$count, $id]);
        $added += $count;
    }
    return $added;
}

if (in_array('--photos', $argv, true)) {
    echo addDemoPhotos() . " photos fictives ajoutées.\n";
    exit(0);
}

$garages = [
    ['Garage du Centre', 'Luc Peeters', '+32470112233', 'atelier@garageducentre.be', 'Rue Neuve 45', '1000', 'Bruxelles'],
    ['Auto Premium', 'Sarah Janssens', '+32471223344', 'contact@autopremium.be', 'Chaussée de Mons 210', '1070', 'Anderlecht'],
    ['Garage Horizon', 'Marc Dubois', '+32472334455', 'info@garagehorizon.be', 'Avenue de Grimbergen 12', '1780', 'Wemmel'],
    ['Meca Studio', 'Inès Lambert', '+32473445566', 'atelier@mecastudio.be', 'Rue Léopold Ier 88', '1090', 'Jette'],
    ['Brussels Motor', 'Youssef El Amrani', '+32474556677', 'service@brusselsmotor.be', 'Boulevard Industriel 9', '1070', 'Anderlecht'],
    ['Auto Service Plus', 'Tom Maes', '+32475667788', 'planning@autoserviceplus.be', 'Ninoofsesteenweg 140', '1700', 'Dilbeek'],
    ['Garage Lambert', 'Claire Lambert', '+32476778899', 'garage.lambert@outlook.be', 'Chaussée d\'Alsemberg 301', '1180', 'Uccle'],
    ['Auto Service Nord', 'Kevin Wouters', '+32477889900', 'nord@autoservice.be', 'Rue Royale Sainte-Marie 55', '1030', 'Schaerbeek'],
    ['Premium Wheels', 'Nadia Bensaïd', '+32478990011', 'hello@premiumwheels.be', 'Avenue Brugmann 400', '1180', 'Uccle'],
    ['Garage Central', 'Pierre Lemaire', '+32479001122', 'central@garage-lemaire.be', 'Rue de la Loi 120', '1040', 'Etterbeek'],
];

$config = App::config()->booking();
$pdo = Database::pdo();
$store = App::store();
$secret = App::config()->secret();
$now = time();
$today = Time::dateOf($now);
$taken = [];

/** Prochain créneau libre à partir d'une date (jours ouvrés seulement). */
$slotFrom = static function (string $date, int $preferred) use ($config, &$taken): ?array {
    for ($i = 0; $i < 30; $i++, $date = Time::addDays($date, 1)) {
        $slots = Schedule::slotsForDate($date, $config);
        for ($j = 0; $j < count($slots); $j++) {
            $slot = $slots[($preferred + $j) % count($slots)];
            $key = $slot['date'] . $slot['key'];
            if (($taken[$key] ?? 0) < (int) $config['capacity_per_slot']) {
                $taken[$key] = ($taken[$key] ?? 0) + 1;
                return $slot;
            }
        }
    }
    return null;
};

$garageIds = [];
foreach ($garages as [$name, $contact, $phone, $email, $street, $zip, $city]) {
    $created = BookingStore::datetime($now - random_int(150, 200) * 86400);
    $pdo->prepare('INSERT INTO bwc_garages (name, contact_name, phone, email, street, postal_code, city, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$name, $contact, $phone, $email, $street, $zip, $city, $created, $created]);
    $garageIds[] = [(int) $pdo->lastInsertId(), $name, $contact, $phone, $email, $street, $zip, $city];
}

$insert = static function (array $g, array $slot, string $status, ?int $completedAt, int $createdAt) use ($store, $secret, $config): void {
    $request = sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
    $id = $store->insert([
        'reference' => Ids::reference($request, $secret), 'request_hash' => Ids::requestHash($request, $secret), 'garage_id' => $g[0],
        'source' => random_int(0, 5) === 0 ? 'admin' : 'public', 'status' => $status,
        'slot_date' => $slot['date'], 'slot_key' => $slot['key'], 'start_time' => $slot['startTime'], 'end_time' => $slot['endTime'],
        'starts_at' => BookingStore::datetime($slot['start']), 'ends_at' => BookingStore::datetime($slot['end']), 'slot_mode' => $config['mode'],
        'garage_name' => $g[1], 'contact_name' => $g[2], 'phone' => $g[3], 'email' => $g[4], 'street' => $g[5], 'postal_code' => $g[6], 'city' => $g[7],
        'rim_count' => [2, 3, 4, 4, 4, 5, 6][random_int(0, 6)], 'comment' => random_int(0, 3) === 0 ? 'Jantes avec pneus.' : '',
        'google_event_id' => Ids::eventId($request, $secret), 'google_status' => 'off',
        'completed_at' => $completedAt !== null ? BookingStore::datetime($completedAt) : null,
        'cancelled_at' => $status === 'cancelled' ? BookingStore::datetime($createdAt + 86400) : null,
        'cancel_reason' => $status === 'cancelled' ? 'Garage fermé ce jour-là' : '',
        'created_at' => BookingStore::datetime($createdAt),
    ]);
    $store->log($id, 'created', null, $status === 'cancelled' ? 'confirmed' : ($status === 'completed' ? 'confirmed' : $status), 'Données de démonstration');
};

// Six derniers mois d'enlèvements effectués, puis la semaine en cours et les suivantes.
$count = 0;
foreach ([5 => 13, 4 => 17, 3 => 15, 2 => 20, 1 => 18, 0 => 22] as $monthsAgo => $perMonth) {
    $month = Time::addMonths(substr($today, 0, 7), -$monthsAgo);
    for ($i = 0; $i < $perMonth; $i++) {
        $day = sprintf('%s-%02d', $month, random_int(1, Time::daysInMonth($month)));
        $slot = $slotFrom($day, random_int(0, 3));
        if ($slot === null || $slot['end'] > $now - 3600) {
            continue;
        }
        $insert($garageIds[array_rand($garageIds)], $slot, random_int(0, 14) === 0 ? 'cancelled' : 'completed', $slot['start'] + random_int(600, 5400), $slot['start'] - random_int(2, 9) * 86400);
        $count++;
    }
}
$monday = Time::addDays($today, 1 - Time::isoWeekday($today));
for ($i = 0; $i < 14; $i++) {
    $slot = $slotFrom(Time::addDays($monday, intdiv($i, 2)), $i % 4);
    if ($slot === null) {
        continue;
    }
    $past = $slot['end'] < $now;
    $insert($garageIds[$i % count($garageIds)], $slot, $past ? 'completed' : 'confirmed', $past ? $slot['start'] + 1800 : null, $now - random_int(1, 6) * 86400);
    $count++;
}
for ($i = 0; $i < 10; $i++) {
    $slot = $slotFrom(Time::addDays($today, 2 + $i), $i % 4);
    if ($slot !== null) {
        $insert($garageIds[($i + 3) % count($garageIds)], $slot, $i === 6 ? 'pending' : 'confirmed', null, $now - random_int(0, 3) * 86400);
        $count++;
    }
}
echo "$count enlèvements fictifs, " . count($garageIds) . ' garages et ' . addDemoPhotos() . " photos créés.\n";
