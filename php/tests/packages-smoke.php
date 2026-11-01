<?php
/**
 * Vérifie les deux paquets livrés (livraison/) installés séparément, comme
 * chez l'hébergeur : deux serveurs, deux config.php, une seule base MySQL
 * et un dossier de photos partagé.
 *
 *   bash php/packaging/build.sh && php php/tests/packages-smoke.php
 *
 * Utilise la base de test bwc_test (voir README, « Tests »), qu'il vide.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$site = "$root/livraison/b2b-wheels-collect-site";
$admin = "$root/livraison/b2b-wheels-collect-admin";
if (!is_dir($site) || !is_dir($admin)) {
    fwrite(STDERR, "Construisez d'abord les paquets : bash php/packaging/build.sh\n");
    exit(1);
}

$work = sys_get_temp_dir() . '/bwc-packages-' . getmypid();
@mkdir("$work/photos", 0777, true);

/** Port libre, pour ne jamais tomber sur un serveur resté d'un essai précédent. */
$freePort = static function (): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
    fclose($socket);
    return $port;
};
$sitePort = $freePort();
$adminPort = $freePort();
$siteUrl = "http://127.0.0.1:$sitePort";
$adminUrl = "http://127.0.0.1:$adminPort";
$config = static fn (): string => var_export([
    'app_url' => $siteUrl,
    'admin_url' => $adminUrl,
    'photos_dir' => "$work/photos",
    'app_secret' => 'packages-smoke-test-secret-0123456789abcdef',
    'demo' => true,
    'db' => [
        'host' => getenv('BWC_TEST_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('BWC_TEST_DB_PORT') ?: 3307),
        'name' => getenv('BWC_TEST_DB_NAME') ?: 'bwc_test',
        'user' => getenv('BWC_TEST_DB_USER') ?: 'bwc',
        'password' => getenv('BWC_TEST_DB_PASSWORD') ?: 'bwc-local',
    ],
], true);
file_put_contents("$work/site-config.php", '<?php return ' . $config() . ';');
file_put_contents("$work/admin-config.php", '<?php return ' . $config() . ';');
$siteEnv = ['BWC_CONFIG_FILE' => "$work/site-config.php", 'BWC_STORAGE_DIR' => "$work/site-storage"];
$adminEnv = ['BWC_CONFIG_FILE' => "$work/admin-config.php", 'BWC_STORAGE_DIR' => "$work/admin-storage"];

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    $failures += $ok ? 0 : 1;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
};

/** Lance une commande PHP d'un paquet, avec son environnement. */
$cli = static function (array $env, string $command, string $input = '') use ($root): string {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env + getenv());
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($process);
    return $output;
};

// Base vide, puis installation par l'outil du paquet « site ».
$cli($siteEnv, 'php -r ' . escapeshellarg("define('X',1); require '$site/app/bootstrap.php'; Bwc\\Schema::dropAll(Bwc\\Database::pdo());"));
$check(str_contains($cli($siteEnv, "php $site/app/tools/migrate.php"), 'toutes les tables'), 'site : tables créées par app/tools/migrate.php');
$created = $cli($adminEnv, "php $admin/app/tools/admin.php create vbm@paquets.test \"Équipe VBM\"", "Paquets-admin-2026\nPaquets-admin-2026\n");
$check(str_contains($created, 'Compte créé'), 'admin : premier compte créé par app/tools/admin.php');

$servers = [];
foreach ([[$site, $sitePort, $siteEnv], [$admin, $adminPort, $adminEnv]] as [$dir, $port, $env]) {
    // Un seul processus par serveur : il s'arrête entièrement à la fin du test.
    $servers[] = proc_open("exec php -S 127.0.0.1:$port -t $dir/public_html $dir/dev-router.php", [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir, $env + getenv());
    for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(50_000);
    }
}

$request = static function (string $method, string $url, array $options = []): array {
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $options['headers'] ?? []]);
    if (isset($options['jar'])) {
        curl_setopt_array($curl, [CURLOPT_COOKIEJAR => $options['jar'], CURLOPT_COOKIEFILE => $options['jar']]);
    }
    if (isset($options['body'])) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $options['body']);
    }
    $raw = (string) curl_exec($curl);
    $size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
};

try {
    // 1. Un garage réserve sur le site, avec une photo.
    $month = json_decode($request('GET', "$siteUrl/api/availability.php")['body'], true);
    $day = null;
    foreach ([$month, json_decode($request('GET', "$siteUrl/api/availability.php?month=" . date('Y-m', strtotime($month['month'] . '-01 +1 month')))['body'], true)] as $data) {
        foreach ($data['days'] as $candidate) {
            foreach ($candidate['slots'] as $slot) {
                if ($slot['available'] && $day === null) {
                    $day = [$candidate['date'], $slot['key']];
                }
            }
        }
    }
    $image = imagecreatetruecolor(64, 48);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    file_put_contents("$work/jante.png", $png);
    $booking = $request('POST', "$siteUrl/api/bookings.php", ['body' => [
        'data' => json_encode([
            'requestId' => '6f1f0d4e-3a4b-4c1d-9e2f-' . sprintf('%012d', random_int(0, 999999999)), 'garageName' => 'Garage Paquets',
            'contactName' => 'Marie Dupont', 'phone' => '0470 12 34 56', 'email' => 'atelier@paquets.test', 'street' => 'Rue Haute 1',
            'postalCode' => '1000', 'city' => 'Bruxelles', 'rimCount' => '4', 'date' => $day[0], 'slot' => $day[1],
        ]),
        'photos[0]' => new CURLFile("$work/jante.png", 'image/png', 'jante.png'),
    ]]);
    $reference = json_decode($booking['body'], true)['booking']['reference'] ?? '';
    $check($booking['status'] === 201 && $reference !== '', "site : réservation $reference enregistrée");
    $check(count(glob("$work/photos/*/photo-1.png") ?: []) === 1, 'site : photo écrite dans le dossier partagé (photos_dir)');

    // 2. VBM la retrouve dans l'administration installée à part.
    $jar = "$work/cookies.txt";
    $login = $request('GET', "$adminUrl/connexion.php", ['jar' => $jar]);
    preg_match('/name="csrf" value="([^"]+)"/', $login['body'], $m);
    $signedIn = $request('POST', "$adminUrl/connexion.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => $m[1] ?? '', 'email' => 'vbm@paquets.test', 'password' => 'Paquets-admin-2026']), 'headers' => ['Content-Type: application/x-www-form-urlencoded']]);
    $check($signedIn['status'] === 302 && preg_match('/Set-Cookie: bwc_admin=[^;]+;.*path=\/;/i', $signedIn['headers']) === 1, 'admin : connexion, cookie limité à l\'administration');
    $list = $request('GET', "$adminUrl/enlevements.php", ['jar' => $jar]);
    $check($list['status'] === 200 && str_contains($list['body'], 'Garage Paquets'), 'admin : la réservation du site apparaît dans la liste');
    $check(str_contains($list['body'], 'assets/admin.css?v=') && $request('GET', "$adminUrl/assets/fonts/mona-sans-latin.woff2")['status'] === 200, 'admin : styles et police servis depuis son propre dossier');
    preg_match('/name="csrf-token" content="([^"]+)"/', $list['body'], $token);
    preg_match('/data-booking="(\d+)"/', $list['body'], $id);
    $details = json_decode($request('GET', "$adminUrl/api.php?action=booking&id={$id[1]}", ['jar' => $jar])['body'], true);
    preg_match('/photo\.php\?id=(\d+)/', $details['html'] ?? '', $photo);
    $photoResponse = $request('GET', "$adminUrl/photo.php?id=" . ($photo[1] ?? 0), ['jar' => $jar]);
    $check($photoResponse['status'] === 200 && $photoResponse['body'] === $png, 'admin : la photo envoyée sur le site s\'affiche');
    $check($request('GET', "$adminUrl/photo.php?id=" . ($photo[1] ?? 0))['status'] === 401, 'admin : photo refusée sans connexion');

    // 3. « Marquer enlevé » depuis l'administration.
    $done = json_decode($request('POST', "$adminUrl/api.php?action=complete", ['jar' => $jar, 'body' => json_encode(['id' => (int) $id[1]]), 'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($token[1] ?? '')]])['body'], true);
    $check(str_contains($done['message'] ?? '', 'Email envoyé au garage'), 'admin : enlèvement marqué, email de confirmation envoyé');

    // 4. Tâche planifiée du site, vue depuis l'administration.
    $cli($siteEnv, "php $site/app/cron/notifications.php");
    $account = $request('GET', "$adminUrl/compte.php", ['jar' => $jar])['body'];
    $check(str_contains($account, 'Identiques sur le site et ici') && str_contains($account, 'Dernier passage : à l’instant'), 'admin : état du service lu dans la base (tâche du site, réglages identiques)');

    // 5. Lien de mot de passe : il pointe vers l'adresse de l'administration.
    $reset = $request('GET', "$adminUrl/mot-de-passe.php", ['jar' => "$work/c2.txt"]);
    preg_match('/name="csrf" value="([^"]+)"/', $reset['body'], $m2);
    $request('POST', "$adminUrl/mot-de-passe.php", ['jar' => "$work/c2.txt", 'body' => http_build_query(['csrf' => $m2[1] ?? '', 'email' => 'vbm@paquets.test']), 'headers' => ['Content-Type: application/x-www-form-urlencoded']]);
    $mails = glob("$work/admin-storage/demo-emails/*.json") ?: [];
    $access = array_values(array_filter(array_map(static fn ($f) => json_decode((string) file_get_contents($f), true), $mails), static fn ($mail) => str_contains($mail['subject'] ?? '', 'mot de passe')));
    $check($access !== [] && str_contains($access[0]['text'], "$adminUrl/mot-de-passe.php?jeton="), 'admin : lien « mot de passe oublié » vers l\'adresse de l\'administration (admin_url)');
} finally {
    foreach ($servers as $server) {
        proc_terminate($server);
        proc_close($server);
    }
}

echo PHP_EOL . ($failures === 0 ? 'Les deux paquets fonctionnent ensemble.' : "$failures vérification(s) en échec.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
