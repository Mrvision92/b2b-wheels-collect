<?php
/** Connexion à l'administration. */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Bwc\Admin\Auth;
use Bwc\Admin\View;
use Bwc\Http;
use Bwc\Log;

Auth::headers();
if (Auth::current() !== null) {
    header('Location: index.php');
    exit;
}

$back = (string) ($_GET['retour'] ?? $_POST['retour'] ?? '');
$back = Auth::isLocalPath($back) ? $back : 'index.php';
$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 254);
    $password = (string) ($_POST['password'] ?? '');
    // Seuls les échecs comptent : 20 par connexion et 8 par compte en 15 minutes.
    $buckets = ['login-ip:' . Http::clientIp() => 20, 'login-email:' . strtolower($email) => 8];
    $wait = max(array_map(static fn (string $bucket, int $limit): int => Http::rateLimitWait($bucket, $limit), array_keys($buckets), $buckets));
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Votre session a expiré. Réessayez.';
    } elseif ($wait > 0) {
        $error = 'Trop de tentatives. Réessayez dans ' . (int) ceil($wait / 60) . ' minute' . ($wait > 60 ? 's' : '') . '.';
        Log::warn('admin.login_rate_limited', []);
    } else {
        $admin = Auth::attempt($email, $password);
        if ($admin !== null) {
            Auth::login($admin);
            Log::info('admin.login', ['admin' => $admin['id']]);
            header('Location: ' . $back);
            exit;
        }
        foreach ($buckets as $bucket => $limit) {
            Http::rateLimit($bucket, $limit, 900);
        }
        $error = 'Email ou mot de passe incorrect.';
        Log::warn('admin.login_failed', []);
    }
}

ob_start(); ?>
    <form method="post" class="auth-form" novalidate>
      <input type="hidden" name="csrf" value="<?= View::e(Auth::csrf()) ?>">
      <input type="hidden" name="retour" value="<?= View::e($back) ?>">
<?php if ($error): ?>      <p class="flash flash-error" role="alert"><?= View::e($error) ?></p>
<?php endif ?>
      <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" class="input" autocomplete="username" value="<?= View::e($email) ?>" required autofocus></div>
      <div class="field"><label for="password">Mot de passe</label><?= View::passwordField('password', 'password', 'current-password', 'required') ?></div>
      <button class="btn btn-primary btn-wide btn-tall">Se connecter</button>
      <p class="auth-link"><a href="mot-de-passe.php">Mot de passe oublié ?</a></p>
    </form>
<?php
$body = (string) ob_get_clean();
$title = 'Connexion';
$heading = 'Administration';
$intro = 'Accès réservé à l’équipe VBM Wheels Repair.';
require BWC_APP_DIR . '/views/admin/_auth.php';
