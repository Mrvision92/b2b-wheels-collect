<?php

declare(strict_types=1);

namespace Bwc\Calendar;

use Bwc\HttpClient;
use Bwc\Time;

/** Client minimal de l'API Google Calendar v3. */
final class GoogleCalendar implements CalendarClient
{
    private const API = 'https://www.googleapis.com/calendar/v3';
    private const LIST_FIELDS = 'items(id,status,summary,start,end,transparency,htmlLink,extendedProperties),nextPageToken';

    public function __construct(private string $calendarId, private GoogleAuth $auth)
    {
    }

    /** @param array<string, mixed>|null $value */
    private static function instant(?array $value): array
    {
        if (isset($value['dateTime'])) {
            return [(int) strtotime($value['dateTime']), false];
        }
        if (isset($value['date'])) {
            return [(int) Time::zonedToUtc($value['date'], '00:00'), true];
        }
        return [0, false];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    public static function fromJson(array $json): array
    {
        [$start, $allDay] = self::instant($json['start'] ?? null);
        [$end] = self::instant($json['end'] ?? null);
        return [
            'id' => (string) $json['id'],
            'status' => ($json['status'] ?? 'confirmed') === 'cancelled' ? 'cancelled' : 'confirmed',
            'summary' => (string) ($json['summary'] ?? ''),
            'start' => $start,
            'end' => $end,
            'allDay' => $allDay,
            'transparent' => ($json['transparency'] ?? '') === 'transparent',
            'htmlLink' => $json['htmlLink'] ?? null,
            'props' => $json['extendedProperties']['private'] ?? [],
        ];
    }

    /**
     * Une nouvelle tentative rapide en cas d'erreur réseau, 429 ou 5xx.
     * Réessayer une création est sans risque : l'identifiant est fixe, un
     * doublon revient en 409.
     *
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $body
     * @param list<int> $accept statuts qui sont des réponses attendues
     * @return array{status: int, json: array<string, mixed>}
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null, array $accept = []): array
    {
        $url = self::API . '/calendars/' . rawurlencode($this->calendarId) . $path . ($query ? '?' . http_build_query($query) : '');
        $lastError = 'erreur inconnue';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($attempt > 0) {
                usleep(400_000);
            }
            $headers = ['Authorization: Bearer ' . $this->auth->accessToken()];
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
            }
            try {
                $response = HttpClient::request($method, $url, $headers, $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null);
            } catch (\RuntimeException $error) {
                $lastError = $error->getMessage();
                continue;
            }
            if (($response['status'] >= 200 && $response['status'] < 300) || in_array($response['status'], $accept, true)) {
                return ['status' => $response['status'], 'json' => json_decode($response['body'], true) ?: []];
            }
            $lastError = "Google Agenda a répondu {$response['status']} " . substr($response['body'], 0, 300);
            if ($response['status'] !== 429 && $response['status'] < 500) {
                break;
            }
        }
        throw new CalendarUnavailable("Google Agenda indisponible : $lastError");
    }

    public function listEvents(int $timeMin, int $timeMax): array
    {
        $events = [];
        $pageToken = null;
        do {
            $query = [
                'timeMin' => gmdate('Y-m-d\TH:i:s\Z', $timeMin),
                'timeMax' => gmdate('Y-m-d\TH:i:s\Z', $timeMax),
                'singleEvents' => 'true',
                'showDeleted' => 'false',
                'maxResults' => 2500,
                'fields' => self::LIST_FIELDS,
            ];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $response = $this->request('GET', '/events', $query);
            foreach ($response['json']['items'] ?? [] as $item) {
                $events[] = self::fromJson($item);
            }
            $pageToken = $response['json']['nextPageToken'] ?? null;
        } while ($pageToken !== null);
        return $events;
    }

    public function getEvent(string $id): ?array
    {
        $response = $this->request('GET', '/events/' . rawurlencode($id), [], null, [404, 410]);
        return $response['status'] === 200 ? self::fromJson($response['json']) : null;
    }

    public function insertEvent(array $event): ?array
    {
        $response = $this->request('POST', '/events', ['sendUpdates' => 'none'], $event, [409]);
        return $response['status'] === 409 ? null : self::fromJson($response['json']);
    }
}
