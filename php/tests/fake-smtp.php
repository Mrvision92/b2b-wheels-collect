<?php
/**
 * Faux serveur SMTP pour les tests : accepte tout et enregistre chaque
 * message reçu dans le dossier donné. php fake-smtp.php <port> <dossier>
 */

[, $port, $dir] = $argv;
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    exit(1);
}
while ($client = stream_socket_accept($server, -1)) {
    $say = static fn (string $line) => fwrite($client, $line . "\r\n");
    $say('220 fake.smtp ESMTP');
    $data = null;
    while (($line = fgets($client)) !== false) {
        if ($data !== null) {
            if ($line === ".\r\n") {
                file_put_contents($dir . '/' . uniqid('mail-', true) . '.eml', $data);
                $data = null;
                $say('250 2.0.0 Ok: queued');
            } else {
                $data .= $line;
            }
            continue;
        }
        $command = strtoupper(substr(trim($line), 0, 4));
        match ($command) {
            'EHLO' => $say("250-fake.smtp\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME"),
            'AUTH' => str_contains($line, base64_encode("\0user@b2b.example\0bon-mot-de-passe")) ? $say('235 2.7.0 Authentication successful') : $say('535 5.7.8 Authentication failed'),
            'MAIL', 'RCPT' => $say('250 2.1.0 Ok'),
            'DATA' => [$data = '', $say('354 End data with <CR><LF>.<CR><LF>')],
            'QUIT' => $say('221 2.0.0 Bye'),
            default => $say('502 5.5.2 Error: command not recognized'),
        };
        if ($command === 'QUIT') {
            break;
        }
    }
    fclose($client);
}
