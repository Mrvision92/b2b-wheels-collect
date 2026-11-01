<?php

declare(strict_types=1);

namespace Bwc\Admin;

use Bwc\Config;
use Bwc\Database;
use Bwc\Http;

/**
 * Connexion à l'administration : session PHP côté serveur (cookie sécurisé,
 * HttpOnly, SameSite), jeton CSRF pour chaque action, déconnexion après une
 * semaine sans activité ou un mois au plus, et dès que le mot de passe
 * change ou que le compte est désactivé.
 */
final class Auth
{
    private const IDLE_SECONDS = 7 * 86400;
    private const MAX_SECONDS = 30 * 86400;
    /** Empreinte factice : même durée de vérification pour un email inconnu. */
    private const DUMMY_HASH = '$2y$12$3u5KUTi6ZGa0ay4/HK1oAuo8pqtArSGuPRPTI/I1JGXP3p6xNQYTG';

    private static ?array $admin = null;

    public static function store(): AdminStore
    {
        return new AdminStore(Database::pdo());
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) self::IDLE_SECONDS);
        session_save_path(Config::get()->storage('sessions'));
        session_name('bwc_admin');
        session_set_cookie_params([
            'lifetime' => self::MAX_SECONDS,
            'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'), '/') . '/',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Administrateur connecté, ou null. */
    public static function current(): ?array
    {
        if (self::$admin !== null) {
            return self::$admin;
        }
        self::start();
        $id = (int) ($_SESSION['admin_id'] ?? 0);
        if ($id === 0) {
            return null;
        }
        $now = time();
        $admin = self::store()->find($id);
        if (
            $admin === null || !$admin['active']
            || $now - (int) $_SESSION['last_seen'] > self::IDLE_SECONDS
            || $now - (int) $_SESSION['logged_in_at'] > self::MAX_SECONDS
            // Mot de passe changé (ou compte désactivé) depuis l'ouverture de la session.
            || (string) $admin['password_changed_at'] !== (string) ($_SESSION['password_version'] ?? '')
        ) {
            self::logout();
            return null;
        }
        if ($now - (int) $_SESSION['last_seen'] > 60) {
            $_SESSION['last_seen'] = $now;
        }
        return self::$admin = $admin;
    }

    /**
     * Vérifie email et mot de passe. La limite de tentatives est appliquée
     * par l'appelant, avant.
     *
     * @return array<string, mixed>|null
     */
    public static function attempt(string $email, string $password): ?array
    {
        $store = self::store();
        $admin = $store->findByEmail($email);
        $hash = $admin['password_hash'] ?? null;
        $valid = password_verify($password, $hash ?? self::DUMMY_HASH);
        if (!$valid || $admin === null || $hash === null || !$admin['active']) {
            return null;
        }
        $store->touchLogin($admin['id'], password_needs_rehash($hash, PASSWORD_DEFAULT) ? password_hash($password, PASSWORD_DEFAULT) : null);
        return $admin;
    }

    public static function login(array $admin): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION = [
            'admin_id' => $admin['id'],
            'logged_in_at' => time(),
            'last_seen' => time(),
            'password_version' => (string) $admin['password_changed_at'],
            'csrf' => bin2hex(random_bytes(32)),
        ];
        self::$admin = null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600] + array_intersect_key($params, array_flip(['path', 'domain', 'secure', 'httponly', 'samesite'])));
        session_destroy();
        self::$admin = null;
    }

    public static function csrf(): string
    {
        self::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        self::start();
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    /** Chemin local sûr pour revenir après la connexion (jamais une autre adresse). */
    public static function isLocalPath(string $path): bool
    {
        return preg_match('#^/(?![/\\\\])[\w/.\-]*(\?[\w=&%.\-]*)?$#', $path) === 1;
    }

    /** En-têtes communs aux pages de l'administration. */
    public static function headers(): void
    {
        Http::securityHeaders();
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, private');
    }

    /** Page protégée : renvoie vers la connexion si personne n'est connecté. */
    public static function requirePage(): array
    {
        self::headers();
        $admin = self::current();
        if ($admin === null) {
            $back = (string) ($_SERVER['REQUEST_URI'] ?? '');
            header('Location: connexion.php' . (self::isLocalPath($back) ? '?retour=' . rawurlencode($back) : ''));
            exit;
        }
        return $admin;
    }

    /** Adresse JSON protégée : 401 sans session, 403 sans jeton CSRF pour une modification. */
    public static function requireApi(): array
    {
        $admin = self::current();
        if ($admin === null) {
            Http::json(['error' => 'unauthenticated', 'message' => 'Session expirée. Reconnectez-vous.'], 401);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !self::checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null))) {
            Http::json(['error' => 'csrf', 'message' => 'Action refusée : rechargez la page puis réessayez.'], 403);
            exit;
        }
        return $admin;
    }
}
