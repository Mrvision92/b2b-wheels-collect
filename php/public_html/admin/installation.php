<?php
/**
 * Premier administrateur, sans accès SSH : disponible uniquement tant
 * qu'aucun compte n'existe et que « admin_setup_key » est rempli dans
 * app/config.php. Videz cette clé une fois le compte créé.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Bwc\Admin\AdminStore;
use Bwc\Admin\Auth;
use Bwc\Admin\View;
use Bwc\App;
use Bwc\Http;

Auth::headers();
$key = App::config()->adminSetupKey();
$store = Auth::store();
if ($key === '' || $store->countActive() > 0) {
    http_response_code(404);
    exit('Introuvable');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $name = trim((string) ($_POST['name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!Auth::checkCsrf($_POST['csrf'] ?? null) || !Http::rateLimit('setup:' . Http::clientIp(), 10, 3600)['ok']) {
        $error = 'Réessayez plus tard.';
    } elseif (!hash_equals($key, (string) ($_POST['key'] ?? ''))) {
        $error = 'Clé d’installation incorrecte.';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($name) < 2) {
        $error = 'Indiquez un nom et une adresse email valide.';
    } elseif (($problem = AdminStore::passwordProblem($password, $email)) !== null) {
        $error = $problem;
    } else {
        Auth::login($store->find($store->create($email, $name, $password)));
        header('Location: index.php');
        exit;
    }
}

ob_start(); ?>
    <form method="post" class="auth-form" novalidate>
      <input type="hidden" name="csrf" value="<?= View::e(Auth::csrf()) ?>">
<?php if ($error): ?>      <p class="flash flash-error" role="alert"><?= View::e($error) ?></p>
<?php endif ?>
      <div class="field"><label for="key">Clé d'installation (app/config.php)</label><input id="key" name="key" type="password" class="input" required></div>
      <div class="field"><label for="name">Nom</label><input id="name" name="name" class="input" value="Équipe VBM" required></div>
      <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" class="input" autocomplete="username" required></div>
      <div class="field"><label for="password">Mot de passe</label><?= View::passwordField('password', 'password', 'new-password', 'minlength="' . AdminStore::MIN_PASSWORD_LENGTH . '" required') ?><p class="hint"><?= AdminStore::MIN_PASSWORD_LENGTH ?> caractères minimum.</p></div>
      <button class="btn btn-primary btn-wide btn-tall">Créer le compte</button>
    </form>
<?php
$body = (string) ob_get_clean();
$title = 'Installation';
$heading = 'Premier administrateur.';
$intro = 'Créez le premier accès à l’administration. Videz ensuite la clé d’installation dans app/config.php.';
require BWC_APP_DIR . '/views/admin/_auth.php';
