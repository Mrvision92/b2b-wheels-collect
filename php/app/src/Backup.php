<?php

declare(strict_types=1);

namespace Bwc;

/**
 * Sauvegarde des tables du site en un fichier SQL compressé, réimportable
 * dans phpMyAdmin. Écrite en PHP pur : elle fonctionne même quand
 * l'hébergement interdit mysqldump.
 */
final class Backup
{
    public const KEEP = 14;

    public static function dir(): string
    {
        return Config::get()->storage('backups');
    }

    /** @return string chemin du fichier créé */
    public static function run(\PDO $pdo, string $label = 'auto'): string
    {
        $file = self::dir() . '/bwc-' . gmdate('Y-m-d-His') . '-' . preg_replace('/[^a-z0-9-]/', '', $label) . '.sql.gz';
        $out = gzopen($file, 'wb6');
        if ($out === false) {
            throw new \RuntimeException("Impossible d'écrire la sauvegarde dans " . self::dir());
        }
        gzwrite($out, "-- B2B Wheels Collect — sauvegarde du " . gmdate('Y-m-d H:i:s') . " UTC\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        foreach ($pdo->query("SHOW TABLES LIKE 'bwc\\_%'")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(\PDO::FETCH_NUM)[1];
            gzwrite($out, "DROP TABLE IF EXISTS `$table`;\n$create;\n");
            $rows = $pdo->query("SELECT * FROM `$table`", \PDO::FETCH_ASSOC);
            $batch = [];
            foreach ($rows as $row) {
                $batch[] = '(' . implode(', ', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                if (count($batch) === 200) {
                    gzwrite($out, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                gzwrite($out, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            gzwrite($out, "\n");
        }
        gzwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($out);
        @chmod($file, 0600);

        $files = glob(self::dir() . '/bwc-*.sql.gz') ?: [];
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - self::KEEP)) as $old) {
            @unlink($old);
        }
        Meta::set('backup.last', (string) time());
        Meta::set('backup.count', (string) min(count($files), self::KEEP));
        return $file;
    }
}
