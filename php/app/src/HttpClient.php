<?php

declare(strict_types=1);

namespace Bwc;

/** Appels HTTPS sortants (Google, Resend, Turnstile) avec cURL. */
final class HttpClient
{
    /**
     * @param array<int, string> $headers
     * @return array{status: int, body: string}
     * @throws \RuntimeException en cas d'erreur réseau
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if ($response === false || $status === 0) {
            throw new \RuntimeException($error !== '' ? $error : 'pas de réponse');
        }
        return ['status' => $status, 'body' => (string) $response];
    }
}
