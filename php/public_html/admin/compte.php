<?php
/** Mon compte : mot de passe, administrateurs, invitations. */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\Admin\Access;
use Bwc\Admin\AdminStore;
use Bwc\Admin\Auth;
use Bwc\Admin\View;
use Bwc\Http;
use Bwc\Log;

$me = Auth::requirePage();
$store = Auth::store();
Auth::start();
$flash = $_SESSION['flash'] ?? null;
$flashLink = $_SESSION['flash_link'] ?? null;
unset($_SESSION['flash'], $_SESSION['flash_link']);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Votre session a expiré. Réessayez.';
    } elseif (!Http::rateLimit('admin-account:' . $me['id'], 20, 600)['ok']) {
        $error = 'Trop de tentatives. Réessayez dans quelques minutes.';
    } elseif ($action === 'password') {
        $new = (string) ($_POST['new'] ?? '');
        if (Auth::attempt($me['email'], (string) ($_POST['current'] ?? '')) === null) {
            $error = 'Le mot de passe actuel est incorrect.';
        } elseif (($problem = AdminStore::passwordProblem($new, $me['email'])) !== null) {
            $error = $problem;
        } elseif ($new !== (string) ($_POST['confirm'] ?? '')) {
            $error = 'Les deux mots de passe ne correspondent pas.';
        } else {
            $store->setPassword($me['id'], $new);
            Auth::login($store->find($me['id']));
            Log::info('admin.password_changed', ['admin' => $me['id']]);
            $_SESSION['flash'] = 'Mot de passe changé. Les autres appareils ont été déconnectés.';
        }
    } elseif ($action === 'invite') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $error = 'Indiquez un nom et une adresse email valide.';
        } elseif ($store->findByEmail($email) !== null) {
            $error = 'Cette adresse a déjà un accès.';
        } else {
            $invited = $store->find($store->create($email, $name));
            try {
                $sentToken = Access::send($invited, true);
                $_SESSION['flash'] = "Invitation envoyée à $email.";
                if (\Bwc\App::mailer()->kind() === 'demo') {
                    $_SESSION['flash_link'] = 'mot-de-passe.php?jeton=' . rawurlencode($sentToken);
                }
            } catch (\Throwable $exception) {
                Log::error('admin.invite_mail_failed', ['admin' => $invited['id'], 'error' => $exception->getMessage()]);
                $error = "Le compte est créé mais l'email n'a pas pu partir. Vérifiez la configuration des emails (php app/tools/check.php).";
            }
        }
    } elseif ($action === 'disable' || $action === 'enable') {
        $target = (int) ($_POST['id'] ?? 0);
        if ($target !== $me['id'] && $store->find($target) !== null) {
            $store->setActive($target, $action === 'enable');
            Log::info('admin.' . $action . 'd', ['admin' => $target, 'by' => $me['id']]);
            $_SESSION['flash'] = $action === 'enable' ? 'Compte réactivé.' : 'Compte désactivé : il ne peut plus se connecter.';
        }
    }
    if ($error === null) {
        header('Location: compte.php');
        exit;
    }
}

View::render('account', [
    'me' => $me,
    'admins' => $store->all(),
    'flash' => $flash,
    'flashLink' => $flashLink,
    'error' => $error,
    'csrf' => Auth::csrf(),
    'status' => \Bwc\Admin\ServiceStatus::collect(),
], ['active' => 'account', 'crumb' => 'Mon compte', 'title' => 'Mon compte', 'admin' => $me]);
