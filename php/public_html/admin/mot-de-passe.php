<?php
/**
 * Mot de passe : demande d'un lien (oubli), puis choix du mot de passe avec
 * le lien reçu par email (oubli ou invitation).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\Admin\Access;
use Bwc\Admin\AdminStore;
use Bwc\Admin\Auth;
use Bwc\Admin\View;
use Bwc\App;
use Bwc\Http;
use Bwc\Log;

Auth::headers();
$store = Auth::store();
$token = (string) ($_GET['jeton'] ?? $_POST['jeton'] ?? '');
$admin = $token !== '' ? $store->findByToken($token) : null;
$error = null;
$done = null;
$demoLink = null;
$demo = App::mailer()->kind() === 'demo';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Votre session a expiré. Réessayez.';
    } elseif ($token !== '') {
        $password = (string) ($_POST['password'] ?? '');
        if ($admin === null) {
            $error = 'Ce lien n’est plus valable. Demandez-en un nouveau.';
        } elseif (($problem = AdminStore::passwordProblem($password, $admin['email'])) !== null) {
            $error = $problem;
        } elseif ($password !== (string) ($_POST['confirm'] ?? '')) {
            $error = 'Les deux mots de passe ne correspondent pas.';
        } else {
            $store->setPassword($admin['id'], $password);
            Auth::login($store->find($admin['id']));
            Log::info('admin.password_set', ['admin' => $admin['id']]);
            header('Location: index.php');
            exit;
        }
    } else {
        $limit = Http::rateLimit('reset-ip:' . Http::clientIp(), 5, 3600);
        if (!$limit['ok']) {
            $error = 'Trop de demandes. Réessayez dans une heure.';
        } else {
            $found = $store->findByEmail((string) ($_POST['email'] ?? ''));
            if ($found !== null && $found['active']) {
                try {
                    $sentToken = Access::send($found, false);
                    // Mode démonstration : aucun email ne part, le lien est montré ici pour essayer.
                    $demoLink = $demo ? 'mot-de-passe.php?jeton=' . rawurlencode($sentToken) : null;
                } catch (\Throwable $exception) {
                    Log::error('admin.reset_mail_failed', ['admin' => $found['id'], 'error' => $exception->getMessage()]);
                }
            }
            // Même réponse que l'adresse existe ou non.
            $done = 'Si cette adresse correspond à un compte, un lien vient d’être envoyé. Il est valable une heure.';
        }
    }
}

ob_start();
if ($token !== '' && $admin === null && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
    <p class="flash flash-error">Ce lien n'est plus valable : il a déjà servi ou il a expiré.</p>
    <p class="auth-link"><a href="mot-de-passe.php">Demander un nouveau lien</a></p>
<?php elseif ($token !== ''): ?>
    <form method="post" class="auth-form" novalidate>
      <input type="hidden" name="csrf" value="<?= View::e(Auth::csrf()) ?>">
      <input type="hidden" name="jeton" value="<?= View::e($token) ?>">
<?php if ($error): ?>      <p class="flash flash-error" role="alert"><?= View::e($error) ?></p>
<?php endif ?>
      <div class="field"><label for="password">Nouveau mot de passe</label><?= View::passwordField('password', 'password', 'new-password', 'minlength="' . AdminStore::MIN_PASSWORD_LENGTH . '" required autofocus') ?><p class="hint"><?= AdminStore::MIN_PASSWORD_LENGTH ?> caractères minimum.</p></div>
      <div class="field"><label for="confirm">Confirmez le mot de passe</label><?= View::passwordField('confirm', 'confirm', 'new-password', 'required') ?></div>
      <button class="btn btn-primary btn-wide btn-tall">Enregistrer et me connecter</button>
    </form>
<?php elseif ($done): ?>
    <p class="flash flash-ok" role="status"><?= View::e($done) ?></p>
<?php if ($demoLink): ?>
    <p class="flash flash-demo">Mode démonstration : aucun email réel n'est envoyé. <a href="<?= View::e($demoLink) ?>">Ouvrir le lien de l'email</a></p>
<?php endif ?>
    <p class="auth-link"><a href="connexion.php">Retour à la connexion</a></p>
<?php else: ?>
    <form method="post" class="auth-form" novalidate>
      <input type="hidden" name="csrf" value="<?= View::e(Auth::csrf()) ?>">
<?php if ($error): ?>      <p class="flash flash-error" role="alert"><?= View::e($error) ?></p>
<?php endif ?>
      <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" class="input" autocomplete="username" required autofocus></div>
      <button class="btn btn-primary btn-wide btn-tall">Recevoir un lien</button>
      <p class="auth-link"><a href="connexion.php">Retour à la connexion</a></p>
    </form>
<?php endif;
$body = (string) ob_get_clean();
$title = 'Mot de passe';
$heading = $token !== '' ? 'Choisissez votre mot de passe.' : 'Mot de passe oublié.';
$intro = $token !== '' ? 'Il vous servira à vous connecter à l’administration.' : 'Indiquez votre adresse : vous recevrez un lien pour choisir un nouveau mot de passe.';
require BWC_APP_DIR . '/views/admin/_auth.php';
