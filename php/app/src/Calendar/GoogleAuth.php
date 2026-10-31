<?php

declare(strict_types=1);

namespace Bwc\Calendar;

use Bwc\Config;
use Bwc\HttpClient;

/**
 * Jeton d'accès Google, par compte de service (recommandé) ou par OAuth.
 * Le jeton est gardé en cache dans app/storage/cache jusqu'à peu avant son
 * expiration.
 */
final class GoogleAuth
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    /** Droits minimaux : les événements des agendas partagés avec le compte. */
    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    /** @param array<string, string> $settings section « google » de config.php */
    public function __construct(private array $settings)
    {
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @return array{client_email: string, private_key: string} */
    private function serviceAccount(): array
    {
        $json = json_decode((string) @file_get_contents($this->settings['service_account_file']), true);
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new CalendarUnavailable('Fichier du compte de service Google illisible : ' . basename($this->settings['service_account_file']));
        }
        return $json;
    }

    public function accessToken(): string
    {
        $useServiceAccount = $this->settings['service_account_file'] !== '';
        $account = $useServiceAccount ? $this->serviceAccount() : null;
        $key = $account['client_email'] ?? $this->settings['oauth_client_id'];
        $cacheFile = Config::get()->storage('cache') . '/google-token-' . md5($key) . '.json';

        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60) {
            return $cached['token'];
        }

        if ($account !== null) {
            $now = time();
            $header = self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = self::base64url(json_encode([
                'iss' => $account['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            if (!openssl_sign("$header.$claims", $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new CalendarUnavailable('Clé privée du compte de service Google invalide.');
            }
            $form = [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "$header.$claims." . self::base64url($signature),
            ];
        } else {
            $form = [
                'grant_type' => 'refresh_token',
                'client_id' => $this->settings['oauth_client_id'],
                'client_secret' => $this->settings['oauth_client_secret'],
                'refresh_token' => $this->settings['oauth_refresh_token'],
            ];
        }

        try {
            $response = HttpClient::request('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], http_build_query($form));
        } catch (\RuntimeException $error) {
            throw new CalendarUnavailable('Google OAuth injoignable : ' . $error->getMessage(), 0, $error);
        }
        $json = json_decode($response['body'], true) ?: [];
        if ($response['status'] !== 200 || empty($json['access_token'])) {
            // Seul le code d'erreur de Google est journalisé, jamais les accès.
            $detail = ($json['error'] ?? $response['status']) . (isset($json['error_description']) ? ' : ' . $json['error_description'] : '');
            throw new CalendarUnavailable("Authentification Google refusée ($detail)");
        }
        @file_put_contents($cacheFile, json_encode(['token' => $json['access_token'], 'expires_at' => time() + (int) ($json['expires_in'] ?? 3600)]), LOCK_EX);
        @chmod($cacheFile, 0600);
        return $json['access_token'];
    }
}
