<?php

declare(strict_types=1);

namespace Bwc\Admin;

use Bwc\BookingStore;

/**
 * Comptes administrateurs : mots de passe hachés (password_hash) et liens à
 * usage unique pour choisir un mot de passe (invitation ou oubli), dont
 * seule l'empreinte est enregistrée.
 */
final class AdminStore
{
    public const MIN_PASSWORD_LENGTH = 10;

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return $row === false ? null : ['id' => (int) $row['id']] + $row;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM bwc_admins WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->one('SELECT * FROM bwc_admins WHERE email = ?', [strtolower(trim($email))]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT id, email, name, active, password_hash IS NOT NULL AS has_password, last_login_at, created_at FROM bwc_admins ORDER BY name')->fetchAll();
    }

    public function countActive(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM bwc_admins WHERE active = 1')->fetchColumn();
    }

    /** @return string|null message d'erreur, null si le mot de passe convient */
    public static function passwordProblem(string $password, string $email = ''): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Le mot de passe doit contenir au moins ' . self::MIN_PASSWORD_LENGTH . ' caractères.';
        }
        if ($email !== '' && stripos($password, explode('@', $email)[0]) !== false) {
            return 'Le mot de passe ne doit pas reprendre votre adresse email.';
        }
        return null;
    }

    public function create(string $email, string $name, ?string $password = null): int
    {
        $now = BookingStore::datetime(time());
        $this->pdo->prepare('INSERT INTO bwc_admins (email, name, password_hash, password_changed_at, created_at) VALUES (?, ?, ?, ?, ?)')->execute([
            strtolower(trim($email)), trim($name), $password !== null ? password_hash($password, PASSWORD_DEFAULT) : null, $password !== null ? $now : null, $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function setPassword(int $id, string $password): void
    {
        $this->pdo->prepare('UPDATE bwc_admins SET password_hash = ?, password_changed_at = ?, reset_token_hash = NULL, reset_expires_at = NULL WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), self::version(), $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE bwc_admins SET active = ?, password_changed_at = ? WHERE id = ?')->execute([$active ? 1 : 0, self::version(), $id]);
    }

    /** Horodatage qui change à chaque modification, même deux fois dans la même seconde. */
    private static function version(): string
    {
        $now = microtime(true);
        return gmdate('Y-m-d H:i:s', (int) $now) . sprintf('.%06d', (int) (fmod($now, 1) * 1_000_000));
    }

    public function touchLogin(int $id, ?string $rehash = null): void
    {
        if ($rehash !== null) {
            $this->pdo->prepare('UPDATE bwc_admins SET password_hash = ? WHERE id = ?')->execute([$rehash, $id]);
        }
        $this->pdo->prepare('UPDATE bwc_admins SET last_login_at = ? WHERE id = ?')->execute([BookingStore::datetime(time()), $id]);
    }

    /** Crée un lien de choix du mot de passe et rend le jeton (à envoyer, jamais enregistré en clair). */
    public function issueToken(int $id, int $hours): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->pdo->prepare('UPDATE bwc_admins SET reset_token_hash = ?, reset_expires_at = ? WHERE id = ?')
            ->execute([hash('sha256', $token), BookingStore::datetime(time() + $hours * 3600), $id]);
        return $token;
    }

    /** @return array<string, mixed>|null administrateur dont le lien est valable */
    public function findByToken(string $token): ?array
    {
        if ($token === '' || strlen($token) > 100) {
            return null;
        }
        return $this->one('SELECT * FROM bwc_admins WHERE reset_token_hash = ? AND reset_expires_at > UTC_TIMESTAMP() AND active = 1', [hash('sha256', $token)]);
    }
}
