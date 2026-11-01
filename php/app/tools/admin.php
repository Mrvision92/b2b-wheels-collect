<?php
/**
 * Comptes de l'administration, en ligne de commande (terminal SSH) :
 *
 *   php app/tools/admin.php create vous@vbmwheels.com "Votre nom"   crée un compte (mot de passe demandé)
 *   php app/tools/admin.php invite vous@vbmwheels.com "Votre nom"   crée un compte et envoie un lien par email
 *   php app/tools/admin.php password vous@vbmwheels.com             change le mot de passe
 *   php app/tools/admin.php list                                    liste les comptes
 *   php app/tools/admin.php disable vous@vbmwheels.com              désactive un compte (enable pour le réactiver)
 *
 * Aucun mot de passe n'est écrit dans le code ni dans la configuration.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Bwc\Admin\Access;
use Bwc\Admin\AdminStore;
use Bwc\Admin\Auth;

$store = Auth::store();
[$command, $email, $name] = array_pad(array_slice($argv, 1), 3, '');

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function askPassword(string $email): string
{
    for ($try = 0; $try < 3; $try++) {
        $read = static function (string $prompt): string {
            echo $prompt;
            $tty = stream_isatty(STDIN);
            if ($tty) {
                shell_exec('stty -echo');
            }
            $value = rtrim((string) fgets(STDIN), "\r\n");
            if ($tty) {
                shell_exec('stty echo');
                echo PHP_EOL;
            }
            return $value;
        };
        $password = $read('Mot de passe (' . AdminStore::MIN_PASSWORD_LENGTH . ' caractères minimum) : ');
        $problem = AdminStore::passwordProblem($password, $email);
        if ($problem !== null) {
            echo $problem . PHP_EOL;
            continue;
        }
        if ($read('Confirmez le mot de passe : ') !== $password) {
            echo 'Les deux mots de passe ne correspondent pas.' . PHP_EOL;
            continue;
        }
        return $password;
    }
    fail('Abandon.');
}

switch ($command) {
    case 'create':
    case 'invite':
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || trim($name) === '') {
            fail("Usage : php app/tools/admin.php $command vous@vbmwheels.com \"Votre nom\"");
        }
        if ($store->findByEmail($email) !== null) {
            fail('Un compte existe déjà avec cette adresse.');
        }
        if ($command === 'create') {
            $store->create($email, $name, askPassword($email));
            echo "Compte créé. Connexion : " . Bwc\App::config()->appUrl() . "/admin/\n";
        } else {
            $admin = $store->find($store->create($email, $name));
            $link = Access::send($admin, true);
            echo "Compte créé, lien envoyé à $email (valable " . Access::INVITATION_HOURS . " h).\n";
            if (Bwc\App::mailer()->kind() === 'demo') {
                echo "Mode démonstration, lien : $link\n";
            }
        }
        break;

    case 'password':
        $admin = $store->findByEmail($email) ?? fail('Compte introuvable.');
        $store->setPassword($admin['id'], askPassword($email));
        echo "Mot de passe changé : les autres sessions de ce compte sont déconnectées.\n";
        break;

    case 'disable':
    case 'enable':
        $admin = $store->findByEmail($email) ?? fail('Compte introuvable.');
        $store->setActive($admin['id'], $command === 'enable');
        echo $command === 'enable' ? "Compte réactivé.\n" : "Compte désactivé : il ne peut plus se connecter.\n";
        break;

    case 'list':
        foreach ($store->all() as $admin) {
            printf("%-32s %-24s %s%s\n", $admin['email'], $admin['name'], $admin['active'] ? 'actif' : 'désactivé', $admin['has_password'] ? '' : ' (mot de passe à choisir)');
        }
        break;

    default:
        echo "Commandes : create, invite, password, list, disable, enable (voir l'en-tête de ce fichier).\n";
}
