<?php

declare(strict_types=1);

namespace Bwc\Mail;

/**
 * Envoi par SMTP authentifié, par exemple avec une boîte email Hostinger
 * (smtp.hostinger.com, port 465 en SSL ou 587 en STARTTLS).
 */
final class SmtpMailer implements Mailer
{
    /** @var resource|null */
    private $socket = null;

    /** @param array{host: string, port: int, encryption: string, username: string, password: string} $smtp */
    public function __construct(private array $smtp, private string $from)
    {
    }

    public function kind(): string
    {
        return 'smtp';
    }

    public function send(array $email): void
    {
        $message = Mime::build($email, $this->from);
        [, $fromAddress] = Mime::parseAddress($this->from);
        try {
            $this->connect();
            $this->command('MAIL FROM:<' . $fromAddress . '>', [250]);
            foreach ($email['to'] as $recipient) {
                [, $address] = Mime::parseAddress($recipient);
                $this->command('RCPT TO:<' . $address . '>', [250, 251]);
            }
            $this->command('DATA', [354]);
            // Les lignes commençant par un point sont doublées (RFC 5321).
            $data = preg_replace('/^\./m', '..', $message);
            $this->command(rtrim($data, "\r\n") . "\r\n.", [250], 'message');
            @fwrite($this->socket, "QUIT\r\n");
        } finally {
            if ($this->socket) {
                fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    private function connect(): void
    {
        $encryption = strtolower($this->smtp['encryption']);
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->smtp['host'] . ':' . (int) $this->smtp['port'];
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->smtp['host']]]);
        $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MailerException("Serveur SMTP injoignable ($errstr)", true);
        }
        stream_set_timeout($socket, 30);
        $this->socket = $socket;

        $this->expect([220]);
        $hostname = gethostname() ?: 'localhost';
        $capabilities = $this->command("EHLO $hostname", [250]);
        if ($encryption === 'tls') {
            $this->command('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new MailerException('Échec du chiffrement STARTTLS', true);
            }
            $capabilities = $this->command("EHLO $hostname", [250]);
        }
        if ($this->smtp['username'] === '') {
            return;
        }
        if (preg_match('/AUTH[ =][^\r\n]*\bPLAIN\b/i', $capabilities) === 1) {
            $this->command('AUTH PLAIN ' . base64_encode("\0{$this->smtp['username']}\0{$this->smtp['password']}"), [235], 'AUTH');
        } else {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->smtp['username']), [334], 'AUTH');
            $this->command(base64_encode($this->smtp['password']), [235], 'AUTH');
        }
    }

    /**
     * @param list<int> $expected
     * @param string|null $label nom de la commande dans les messages d'erreur (jamais les identifiants)
     */
    private function command(string $line, array $expected, ?string $label = null): string
    {
        if (@fwrite($this->socket, $line . "\r\n") === false) {
            throw new MailerException('Connexion SMTP interrompue', true);
        }
        return $this->expect($expected, $label ?? strtok($line, ' :'));
    }

    /** @param list<int> $expected */
    private function expect(array $expected, string $label = 'connexion'): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            $summary = trim(substr(preg_replace('/\s+/', ' ', $response) ?? '', 0, 200));
            // 4xx : erreur passagère, on réessaiera. 5xx : refus définitif.
            throw new MailerException("Le serveur SMTP a refusé « $label » : " . ($summary !== '' ? $summary : 'pas de réponse'), $code === 0 || ($code >= 400 && $code < 500));
        }
        return $response;
    }
}
