<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Réglages (settings.php, versionné) et configuration secrète (config.php,
 * jamais versionné). Chaque service externe est « réel » quand ses accès sont
 * renseignés, sinon simulé et signalé sur le site.
 */
final class Config
{
    private const DEMO_SECRET = 'b2b-wheels-collect-demo-secret-not-for-production';

    private static ?Config $instance = null;

    /** @param array<string, mixed> $settings @param array<string, mixed> $secrets */
    public function __construct(private array $settings, private array $secrets, private bool $configured)
    {
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            $settings = require BWC_APP_DIR . '/settings.php';
            // BWC_CONFIG_FILE permet aux tests d'utiliser leur propre configuration.
            $file = getenv('BWC_CONFIG_FILE') ?: BWC_APP_DIR . '/config.php';
            $configured = is_file($file);
            $secrets = $configured ? require $file : [];
            self::$instance = new self($settings, is_array($secrets) ? $secrets : [], $configured);
        }
        return self::$instance;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /** @return array<string, mixed> */
    public function booking(): array
    {
        return $this->settings['booking'];
    }

    /** @return array{whatsapp: string, phone: string, email: string} */
    public function contact(): array
    {
        return $this->settings['contact'] + ['whatsapp' => '', 'phone' => '', 'email' => ''];
    }

    /** @return array{entity: string, address: string, vat: string, privacy_email: string, retention: string} */
    public function legal(): array
    {
        $legal = ($this->settings['legal'] ?? []) + ['entity' => '', 'address' => '', 'vat' => '', 'privacy_email' => '', 'retention' => ''];
        if ($legal['privacy_email'] === '') {
            $legal['privacy_email'] = $this->contact()['email'];
        }
        return $legal;
    }

    public function demo(): bool
    {
        return ($this->secrets['demo'] ?? true) !== false;
    }

    public function appUrl(): string
    {
        return rtrim((string) ($this->secrets['app_url'] ?? 'http://localhost:8080'), '/');
    }

    public function secret(): string
    {
        $secret = trim((string) ($this->secrets['app_secret'] ?? ''));
        if (strlen($secret) >= 32) {
            return $secret;
        }
        if ($this->demo()) {
            return self::DEMO_SECRET;
        }
        throw new \RuntimeException('app_secret doit contenir au moins 32 caractères dès que le mode démonstration est désactivé.');
    }

    /** @return array{host: string, port: int, name: string, user: string, password: string} */
    public function db(): array
    {
        $db = ($this->secrets['db'] ?? []) + ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'password' => ''];
        $db['port'] = (int) $db['port'];
        return $db;
    }

    /** "smtp", "resend" ou "demo" (emails enregistrés localement, rien n'est envoyé). */
    public function mailTransport(): string
    {
        return $this->mail()['mode'];
    }

    /** @return array<string, mixed> */
    public function mail(): array
    {
        $mail = ($this->secrets['mail'] ?? []) + [
            'transport' => 'smtp',
            'from' => 'B2B Wheels Collect <demo@b2bwheelscollect.local>',
            'reply_to' => '',
            'vbm_recipients' => [],
            'smtp' => [],
            'resend_api_key' => '',
        ];
        $mail['smtp'] = (array) $mail['smtp'] + ['host' => '', 'port' => 465, 'encryption' => 'ssl', 'username' => '', 'password' => ''];
        $recipients = is_array($mail['vbm_recipients']) ? $mail['vbm_recipients'] : preg_split('/[,;]/', (string) $mail['vbm_recipients']);
        $mail['vbm_recipients'] = array_values(array_filter(array_map('trim', $recipients ?: [])));

        $mail['mode'] = 'demo';
        if (!$this->demo() && $mail['transport'] === 'resend' && $mail['resend_api_key'] !== '') {
            $mail['mode'] = 'resend';
        } elseif (!$this->demo() && $mail['transport'] === 'smtp' && $mail['smtp']['host'] !== '' && $mail['smtp']['username'] !== '') {
            $mail['mode'] = 'smtp';
        }
        if ($mail['mode'] === 'demo' && $mail['vbm_recipients'] === []) {
            $mail['vbm_recipients'] = ['vbm-demo@b2bwheelscollect.local'];
        }
        return $mail;
    }

    /** @return array{calendar_id: string, service_account_file: string, oauth_client_id: string, oauth_client_secret: string, oauth_refresh_token: string} */
    public function google(): array
    {
        return ($this->secrets['google'] ?? []) + [
            'calendar_id' => '',
            'service_account_file' => '',
            'oauth_client_id' => '',
            'oauth_client_secret' => '',
            'oauth_refresh_token' => '',
        ];
    }

    public function googleEnabled(): bool
    {
        $g = $this->google();
        if ($this->demo() || $g['calendar_id'] === '') {
            return false;
        }
        return $g['service_account_file'] !== ''
            || ($g['oauth_client_id'] !== '' && $g['oauth_client_secret'] !== '' && $g['oauth_refresh_token'] !== '');
    }

    /** @return array{site_key: string, secret_key: string} */
    public function turnstile(): array
    {
        $t = ($this->secrets['turnstile'] ?? []) + ['site_key' => '', 'secret_key' => ''];
        return $t['site_key'] !== '' && $t['secret_key'] !== '' ? $t : ['site_key' => '', 'secret_key' => ''];
    }

    /** Dossier d'écriture (créé au besoin) sous app/storage/. */
    public function storage(string $name): string
    {
        $base = getenv('BWC_STORAGE_DIR') ?: BWC_APP_DIR . '/storage';
        $dir = $base . '/' . $name;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }
}
