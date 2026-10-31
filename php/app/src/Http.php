<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Réponses HTTP et protections des adresses publiques : en-têtes de
 * sécurité, même origine, limite de tentatives et Turnstile.
 */
final class Http
{
    public static function securityHeaders(bool $turnstile = false): void
    {
        $cloudflare = $turnstile ? ' https://challenges.cloudflare.com' : '';
        header("Content-Security-Policy: default-src 'self'; script-src 'self'$cloudflare; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-src 'self'$cloudflare; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Envoie la réponse au navigateur tout de suite et continue le travail
     * (email à VBM) en arrière-plan quand le serveur le permet.
     */
    public static function finishResponse(): void
    {
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'local');
    }

    /** Refuse les envois venant d'un autre site. */
    public static function isSameOrigin(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        return is_string($host) && ($port ? "$host:$port" : $host) === ($_SERVER['HTTP_HOST'] ?? '');
    }

    /**
     * Limite simple par fenêtre fixe, partagée par tous les processus grâce à MySQL.
     *
     * @return array{ok: bool, retryAfter: int}
     */
    public static function rateLimit(string $bucket, int $limit, int $windowSeconds): array
    {
        $pdo = Database::pdo();
        $now = time();
        $key = substr($bucket, 0, 60) . ':' . substr(hash('sha256', Config::get()->secret() . $bucket), 0, 16);
        $pdo->prepare(
            'INSERT INTO bwc_rate_limits (bucket, hits, reset_at) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE hits = IF(reset_at <= ?, 1, hits + 1), reset_at = IF(reset_at <= ?, ?, reset_at)',
        )->execute([$key, $now + $windowSeconds, $now, $now, $now + $windowSeconds]);
        $statement = $pdo->prepare('SELECT hits, reset_at FROM bwc_rate_limits WHERE bucket = ?');
        $statement->execute([$key]);
        $row = $statement->fetch() ?: ['hits' => 1, 'reset_at' => $now];
        if (random_int(1, 200) === 1) {
            $pdo->prepare('DELETE FROM bwc_rate_limits WHERE reset_at < ?')->execute([$now - 3600]);
        }
        return ['ok' => (int) $row['hits'] <= $limit, 'retryAfter' => max(0, (int) $row['reset_at'] - $now)];
    }

    public static function verifyTurnstile(string $token, string $secret): bool
    {
        if ($token === '') {
            return false;
        }
        try {
            $response = HttpClient::request(
                'POST',
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                ['Content-Type: application/x-www-form-urlencoded'],
                http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => self::clientIp()]),
                8,
            );
            return (json_decode($response['body'], true)['success'] ?? false) === true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
