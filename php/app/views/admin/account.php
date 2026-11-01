<?php
/**
 * Mon compte : mot de passe, déconnexion, administrateurs.
 *
 * @var array<string, mixed> $me
 * @var list<array<string, mixed>> $admins
 * @var string|null $flash
 * @var string|null $error
 * @var string $csrf
 * @var array<string, mixed> $status
 */

use Bwc\Admin\AdminStore;
use Bwc\Admin\View;
?>
<?= View::pageHeader('Compte', 'Mon compte.', 'Votre accès, les personnes autorisées, l’état du service et vos données.', false) ?>

<?php if ($flash): ?><p class="flash flash-ok" role="status"><?= View::icon('check-circle', 'icon icon-sm') ?><?= View::e($flash) ?></p><?php endif ?>
<?php if (!empty($flashLink)): ?><p class="flash flash-demo">Mode démonstration : aucun email réel n'est envoyé. Lien de l'invitation : <a href="<?= View::e($flashLink) ?>">choisir le mot de passe</a> (à ouvrir dans une fenêtre privée).</p><?php endif ?>
<?php if ($error): ?><p class="flash flash-error" role="alert"><?= View::e($error) ?></p><?php endif ?>

<div class="dash-grid dash-grid-even">
  <section class="panel" aria-labelledby="me-title">
    <div class="panel-head"><h2 id="me-title"><?= View::e($me['name']) ?></h2><span class="panel-meta"><?= View::e($me['email']) ?></span></div>
    <form method="post" class="stack-form">
      <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
      <input type="hidden" name="action" value="password">
      <h3>Changer de mot de passe</h3>
      <div class="field"><label for="a-current">Mot de passe actuel</label><?= View::passwordField('a-current', 'current', 'current-password', 'required') ?></div>
      <div class="field"><label for="a-new">Nouveau mot de passe</label><?= View::passwordField('a-new', 'new', 'new-password', 'minlength="' . AdminStore::MIN_PASSWORD_LENGTH . '" required') ?><p class="hint"><?= AdminStore::MIN_PASSWORD_LENGTH ?> caractères minimum. Les autres appareils connectés seront déconnectés.</p></div>
      <div class="field"><label for="a-confirm">Confirmez le nouveau mot de passe</label><?= View::passwordField('a-confirm', 'confirm', 'new-password', 'required') ?></div>
      <div class="form-actions"><button class="btn btn-primary">Changer le mot de passe</button></div>
    </form>
    <form method="post" action="deconnexion.php" class="logout-form">
      <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
      <button class="btn btn-outline btn-wide"><?= View::icon('logout', 'icon icon-sm') ?>Se déconnecter</button>
    </form>
  </section>

  <section class="panel" aria-labelledby="admins-title">
    <div class="panel-head"><h2 id="admins-title">Administrateurs</h2><span class="panel-meta"><?= count($admins) ?> compte<?= count($admins) > 1 ? 's' : '' ?></span></div>
    <ul class="admin-list">
<?php foreach ($admins as $a): ?>
      <li>
        <span class="avatar"><?= View::e(View::initials($a['name'])) ?></span>
        <span class="admin-main"><strong><?= View::e($a['name']) ?></strong><small><?= View::e($a['email']) ?> · <?= !$a['active'] ? 'désactivé' : (!$a['has_password'] ? 'invitation envoyée' : ($a['last_login_at'] ? 'dernière connexion le ' . View::e(View::datetime($a['last_login_at'])) : 'jamais connecté')) ?></small></span>
<?php if ((int) $a['id'] !== (int) $me['id']): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
          <input type="hidden" name="action" value="<?= $a['active'] ? 'disable' : 'enable' ?>">
          <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
          <button class="link-btn"><?= $a['active'] ? 'Désactiver' : 'Réactiver' ?></button>
        </form>
<?php endif ?>
      </li>
<?php endforeach ?>
    </ul>
    <form method="post" class="stack-form">
      <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
      <input type="hidden" name="action" value="invite">
      <h3>Inviter une personne de VBM</h3>
      <div class="field-row">
        <div class="field"><label for="i-name">Nom</label><input id="i-name" name="name" class="input" maxlength="120" required></div>
        <div class="field"><label for="i-email">Email</label><input id="i-email" name="email" type="email" class="input" maxlength="254" required></div>
      </div>
      <p class="hint">La personne reçoit un lien valable 72 heures pour choisir son mot de passe.</p>
      <div class="form-actions"><button class="btn btn-outline">Envoyer l'invitation</button></div>
    </form>
  </section>
</div>

<?php
$since = static function (?int $ts): string {
    if ($ts === null) {
        return 'jamais';
    }
    $minutes = (int) round((time() - $ts) / 60);
    return $minutes < 2 ? 'à l’instant' : ($minutes < 120 ? "il y a $minutes min" : 'le ' . View::datetime(gmdate('Y-m-d H:i:s', $ts)));
};
$cronLate = $status['cronNotifications'] === null || time() - $status['cronNotifications'] > 3600;
$checks = [
    ['Emails', match ($status['mail']) { 'smtp' => 'Envoi par SMTP', 'resend' => 'Envoi par Resend', default => 'Simulés (démonstration)' },
        $status['mailFailed'] > 0 ? 'error' : ($status['mailPending'] > 0 || $status['mail'] === 'demo' ? 'warn' : 'ok'),
        ($status['mailPending'] > 0 ? $status['mailPending'] . ' en attente' : 'aucun en attente') . ($status['mailFailed'] > 0 ? ', ' . $status['mailFailed'] . ' en échec' : '')],
    ['Google Agenda', $status['calendar'] ? 'Connecté' : 'Non connecté (facultatif)',
        $status['calendarFailed'] > 0 ? 'error' : ($status['calendar'] ? ($status['calendarPending'] > 0 ? 'warn' : 'ok') : 'muted'),
        $status['calendar'] ? ($status['calendarPending'] + $status['calendarFailed'] > 0 ? $status['calendarPending'] . ' copie(s) en attente, ' . $status['calendarFailed'] . ' en échec' : 'tout est copié') : 'les enlèvements restent dans la base du site'],
    ['Tâche planifiée (emails)', 'Dernier passage : ' . $since($status['cronNotifications']), $cronLate ? ($status['demo'] ? 'muted' : 'error') : 'ok',
        $cronLate ? ($status['demo'] ? 'normal en démonstration locale' : 'à configurer dans hPanel → Tâches Cron (toutes les 15 min)') : 'renvoie les emails en attente'],
    ['Sauvegarde', $status['lastBackup'] ? 'Dernière : ' . $since($status['lastBackup']) : 'Aucune sauvegarde', $status['lastBackup'] && time() - $status['lastBackup'] < 2 * 86400 ? 'ok' : ($status['demo'] ? 'muted' : 'error'),
        $status['backupCount'] . ' sauvegarde(s) gardée(s) sur le serveur'],
    ['Base de données', $status['migrationsPending'] === [] ? 'À jour' : 'Mise à jour à faire', $status['migrationsPending'] === [] ? 'ok' : 'error',
        $status['migrationsPending'] === [] ? '' : 'php app/tools/migrate.php'],
    ['Mentions légales', $status['legalComplete'] ? 'Complètes' : 'À compléter par VBM', $status['legalComplete'] ? 'ok' : 'warn', 'app/settings.php, section legal'],
];
?>
<div class="dash-grid dash-grid-even">
  <section class="panel" aria-labelledby="status-title">
    <div class="panel-head"><h2 id="status-title">État du service</h2></div>
    <ul class="status-list">
<?php foreach ($checks as [$label, $value, $level, $detail]): ?>
      <li><span class="status-dot status-<?= $level ?>" aria-hidden="true"></span><span><strong><?= View::e($label) ?></strong><small><?= View::e($value) ?><?= $detail !== '' ? ' · ' . View::e($detail) : '' ?></small></span></li>
<?php endforeach ?>
    </ul>
<?php if ($status['mailProblems'] !== []): ?>
    <div class="status-problems">
      <h3>Emails à surveiller</h3>
<?php foreach ($status['mailProblems'] as $n): ?>
      <button type="button" class="status-problem" data-booking="<?= (int) $n['booking_id'] ?>"><strong><?= View::e($n['garage_name']) ?></strong> · <?= View::e(\Bwc\Notifications::LABELS[$n['kind']] ?? $n['kind']) ?><small><?= $n['status'] === 'failed' ? 'Échec' : 'Nouvel essai en cours' ?> — <?= View::e((string) $n['last_error']) ?></small></button>
<?php endforeach ?>
    </div>
<?php endif ?>
  </section>

  <section class="panel" aria-labelledby="data-title">
    <div class="panel-head"><h2 id="data-title">Vos données</h2></div>
    <div class="data-actions">
      <a class="btn btn-outline" href="export.php?quoi=garages"><?= View::icon('download', 'icon icon-sm') ?>Exporter tous les garages (CSV)</a>
      <a class="btn btn-outline" href="export.php"><?= View::icon('download', 'icon icon-sm') ?>Exporter tous les enlèvements (CSV)</a>
      <form method="post" action="sauvegarde.php">
        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
        <button class="btn btn-outline btn-wide"><?= View::icon('download', 'icon icon-sm') ?>Télécharger une sauvegarde complète</button>
      </form>
      <p class="hint">Les fichiers CSV s'ouvrent dans Excel. La sauvegarde (.sql.gz) contient toute la base et se réimporte dans phpMyAdmin. Une sauvegarde est faite chaque nuit sur le serveur ; gardez-en aussi une copie chez vous de temps en temps.</p>
      <p class="hint">Conservation des données : <?= $status['retention'] ? 'effacement automatique après ' . (int) $status['retention'] . ' mois' : 'aucun effacement automatique (durée à fixer par VBM dans settings.php)' ?>.</p>
    </div>
  </section>
</div>
