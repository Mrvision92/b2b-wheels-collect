<?php

declare(strict_types=1);

use Bwc\BookingService;
use Bwc\BookingStore;
use Bwc\Calendar\CalendarClient;
use Bwc\Calendar\CalendarUnavailable;
use Bwc\Calendar\GoogleCalendar;
use Bwc\Config;
use Bwc\Database;
use Bwc\GarageStore;
use Bwc\Mail\Mailer;
use Bwc\Mail\MailerException;
use Bwc\Notifications;

/** Registre des tests et petits outils d'assertion. */
final class Tests
{
    /** @var list<array{0: string, 1: callable}> */
    public static array $all = [];
    /** @var list<resource> */
    private static array $servers = [];

    /** Lundi 2 novembre 2026, 8 h à Bruxelles : premier créneau réservable le mardi 3 à 9 h. */
    public const NOW = 1793602800;

    public static function stopServers(): void
    {
        foreach (self::$servers as $process) {
            // Le serveur PHP lance des processus enfants (PHP_CLI_SERVER_WORKERS) : on les arrête aussi.
            $pid = (int) (proc_get_status($process)['pid'] ?? 0);
            if ($pid > 0) {
                exec('pkill -TERM -P ' . $pid);
            }
            proc_terminate($process);
            proc_close($process);
        }
        self::$servers = [];
    }

    /** Démarre un processus en arrière-plan (serveur PHP, faux serveur SMTP). */
    public static function startProcess(string $command, array $env, int $port): void
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__), $env + getenv());
        self::$servers[] = $process;
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
        throw new RuntimeException("Le processus n'a pas démarré sur le port $port");
    }
}

function test(string $name, callable $fn): void
{
    Tests::$all[] = [$name, $fn];
}

function eq(mixed $actual, mixed $expected, string $label = ''): void
{
    if ($actual !== $expected) {
        throw new AssertionError(($label !== '' ? "$label : " : '') . 'obtenu ' . var_export($actual, true) . ', attendu ' . var_export($expected, true));
    }
}

function ok(bool $condition, string $label): void
{
    if (!$condition) {
        throw new AssertionError($label);
    }
}

function contains(string $haystack, string $needle, string $label = ''): void
{
    ok(str_contains($haystack, $needle), ($label !== '' ? "$label : " : '') . "« $needle » introuvable");
}

/** Recrée toutes les tables de la base de test, vides. */
function resetDatabase(): void
{
    $pdo = Database::pdo();
    \Bwc\Schema::dropAll($pdo);
    \Bwc\Schema::install($pdo);
}

/** Réservation valide, telle que sortie du Validator. */
function bookingInput(array $overrides = []): array
{
    static $n = 0;
    $n++;
    return $overrides + [
        'requestId' => sprintf('6f1f0d4e-3a4b-4c1d-9e2f-%012d', $n + random_int(0, 999999) * 1000),
        'garageName' => "Garage Test $n",
        'contactName' => 'Marie Dupont',
        'phone' => '+32470123456',
        'email' => "atelier$n@garage-test.be",
        'street' => "Rue de la Station $n",
        'postalCode' => '1000',
        'city' => 'Bruxelles',
        'rimCount' => 4,
        'comment' => '',
        'garageReference' => '',
        'date' => '2026-11-03',
        'slot' => '0900',
        'turnstileToken' => '',
    ];
}

/** Agenda en mémoire qui se comporte comme Google (409 sur un identifiant existant). */
final class FakeCalendar implements CalendarClient
{
    /** @var array<string, array<string, mixed>> */
    public array $events = [];
    public bool $failList = false;
    public bool $failInsert = false;
    public int $lists = 0;

    public function add(string $summary, string $startUtc, string $endUtc, array $extra = []): void
    {
        $id = 'manual' . count($this->events);
        $this->events[$id] = $extra + [
            'id' => $id, 'status' => 'confirmed', 'summary' => $summary, 'start' => strtotime($startUtc), 'end' => strtotime($endUtc),
            'allDay' => false, 'transparent' => false, 'htmlLink' => null, 'props' => [],
        ];
    }

    public function listEvents(int $timeMin, int $timeMax): array
    {
        $this->lists++;
        if ($this->failList) {
            throw new CalendarUnavailable('agenda injoignable (test)');
        }
        return array_values(array_filter($this->events, static fn (array $e): bool => $e['start'] < $timeMax && $e['end'] > $timeMin && $e['status'] !== 'cancelled'));
    }

    public function getEvent(string $id): ?array
    {
        return $this->events[$id] ?? null;
    }

    public function insertEvent(array $event): ?array
    {
        if ($this->failInsert) {
            throw new CalendarUnavailable('création refusée (test)');
        }
        if (isset($this->events[$event['id']]) || isset($this->deleted[$event['id']])) {
            return null;
        }
        $created = GoogleCalendar::fromJson($event + ['htmlLink' => 'https://calendar.google.com/event?eid=' . $event['id']]);
        $this->events[$event['id']] = $created + ['colorId' => $event['colorId'] ?? null];
        return $created;
    }

    /** @var array<string, true> identifiants supprimés (Google les garde : une nouvelle création répond 409) */
    public array $deleted = [];
    public int $patches = 0;

    public function patchEvent(string $id, array $event): ?array
    {
        if ($this->failInsert) {
            throw new CalendarUnavailable('modification refusée (test)');
        }
        $this->patches++;
        unset($this->deleted[$id]);
        $updated = GoogleCalendar::fromJson(['id' => $id, 'htmlLink' => 'https://calendar.google.com/event?eid=' . $id] + $event);
        $this->events[$id] = $updated + ['colorId' => $event['colorId'] ?? null];
        return $updated;
    }

    public function deleteEvent(string $id): void
    {
        if ($this->failInsert) {
            throw new CalendarUnavailable('suppression refusée (test)');
        }
        if (isset($this->events[$id])) {
            $this->deleted[$id] = true;
        }
        unset($this->events[$id]);
    }
}

/** Service d'envoi en mémoire, qui peut échouer à la demande. */
final class FakeMailer implements Mailer
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];
    public ?MailerException $failure = null;
    public ?string $failFor = null;

    public function kind(): string
    {
        return 'demo';
    }

    public function send(array $email): string
    {
        if ($this->failure !== null && ($this->failFor === null || in_array($this->failFor, $email['to'], true))) {
            throw $this->failure;
        }
        $this->sent[] = $email;
        return 'fake:' . count($this->sent);
    }

    /** @return list<string> objets des emails envoyés */
    public function subjects(): array
    {
        return array_column($this->sent, 'subject');
    }
}

/** @return array{0: BookingService, 1: Notifications, 2: BookingStore, 3: GarageStore} */
function services(?CalendarClient $calendar, Mailer $mailer, int $now = Tests::NOW): array
{
    $store = new BookingStore(Database::pdo());
    $garages = new GarageStore(Database::pdo());
    $notifications = new Notifications($store, $mailer, $calendar, Config::get());
    return [new BookingService(Config::get(), $store, $garages, $calendar, $notifications, static fn (): int => $now), $notifications, $store, $garages];
}

/** Statut des emails d'une réservation, par type. @return array<string, string> */
function emailStates(int $bookingId): array
{
    $statement = Database::pdo()->prepare('SELECT kind, status FROM bwc_notifications WHERE booking_id = ? ORDER BY id');
    $statement->execute([$bookingId]);
    return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
}
