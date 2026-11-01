<?php
/**
 * Mon compte : mot de passe, déconnexion, administrateurs.
 *
 * @var array<string, mixed> $me
 * @var list<array<string, mixed>> $admins
 * @var string|null $flash
 * @var string|null $error
 * @var string $csrf
 */

use Bwc\Admin\AdminStore;
use Bwc\Admin\View;
?>
<?= View::pageHeader('Compte', 'Mon compte.', 'Votre accès à l’administration et les personnes autorisées.', false) ?>

<?php if ($flash): ?><p class="flash flash-ok" role="status"><?= View::icon('check-circle', 'icon icon-sm') ?><?= View::e($flash) ?></p><?php endif ?>
<?php if ($error): ?><p class="flash flash-error" role="alert"><?= View::e($error) ?></p><?php endif ?>

<div class="dash-grid dash-grid-even">
  <section class="panel" aria-labelledby="me-title">
    <div class="panel-head"><h2 id="me-title"><?= View::e($me['name']) ?></h2><span class="panel-meta"><?= View::e($me['email']) ?></span></div>
    <form method="post" class="stack-form">
      <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
      <input type="hidden" name="action" value="password">
      <h3>Changer de mot de passe</h3>
      <div class="field"><label for="a-current">Mot de passe actuel</label><input id="a-current" name="current" type="password" class="input" autocomplete="current-password" required></div>
      <div class="field"><label for="a-new">Nouveau mot de passe</label><input id="a-new" name="new" type="password" class="input" autocomplete="new-password" minlength="<?= AdminStore::MIN_PASSWORD_LENGTH ?>" required><p class="hint"><?= AdminStore::MIN_PASSWORD_LENGTH ?> caractères minimum. Les autres appareils connectés seront déconnectés.</p></div>
      <div class="field"><label for="a-confirm">Confirmez le nouveau mot de passe</label><input id="a-confirm" name="confirm" type="password" class="input" autocomplete="new-password" required></div>
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
