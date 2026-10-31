<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Journal applicatif (app/storage/logs/app-AAAA-MM.log), une ligne JSON par
 * événement. Seules les références y figurent, jamais de données personnelles.
 */
final class Log
{
    /** @param array<string, mixed> $context */
    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warn(string $event, array $context = []): void
    {
        self::write('warn', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $event, array $context): void
    {
        $line = json_encode(['at' => gmdate('c'), 'level' => $level, 'event' => $event] + $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $file = Config::get()->storage('logs') . '/app-' . gmdate('Y-m') . '.log';
        if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log((string) $line);
        }
    }
}
