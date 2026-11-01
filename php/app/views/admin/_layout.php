<?php
/**
 * Gabarit commun : barre latérale (ordinateur), en-tête et barre d'onglets
 * (téléphone), fenêtres de détail et de confirmation.
 *
 * @var string $content
 * @var array $page
 * @var string $csrf
 * @var \Bwc\Config $config
 * @var int $now
 * @var list<array<string, mixed>> $latest
 */

use Bwc\Admin\View;
use Bwc\Time;

$admin = $page['admin'];
$today = Time::dateOf($now);
$latestAt = $latest !== [] ? strtotime($latest[0]['created_at'] . ' UTC') : 0;
?>
<!doctype html>
<html lang="fr-BE">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?= View::e($csrf) ?>">
  <meta name="theme-color" content="#ffffff">
  <title><?= View::e($page['title']) ?> — B2B Wheels Collect</title>
  <link rel="icon" href="../favicon.svg" type="image/svg+xml">
  <link rel="preload" href="../assets/fonts/mona-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= View::asset('assets/admin.css') ?>">
  <script src="<?= View::asset('assets/admin.js') ?>" defer></script>
</head>
<body class="page-<?= View::e($page['active']) ?>" data-today="<?= View::e($today) ?>">
  <aside class="sidebar">
    <a href="index.php" class="brand" aria-label="B2B Wheels Collect, vue d'ensemble">
      <?= View::logo() ?>
      <span class="brand-text"><strong>B2B Wheels<br>Collect</strong><small>Administration</small></span>
    </a>
    <p class="nav-label">Espace de travail</p>
    <nav class="nav" aria-label="Sections">
<?php foreach (View::NAV as $key => [$href, $label, , $icon]): ?>
      <a href="<?= $href ?>" class="nav-item<?= $page['active'] === $key ? ' is-active' : '' ?>"<?= $page['active'] === $key ? ' aria-current="page"' : '' ?>><?= View::icon($icon) ?><?= View::e($label) ?></a>
<?php endforeach ?>
    </nav>
    <hr class="nav-rule">
    <p class="nav-label">Compte</p>
    <nav class="nav" aria-label="Compte">
      <a href="compte.php" class="nav-item<?= $page['active'] === 'account' ? ' is-active' : '' ?>"><?= View::icon('user') ?>Mon compte</a>
    </nav>
    <a href="compte.php" class="sidebar-user">
      <span class="avatar"><?= View::e(View::initials($admin['name'])) ?></span>
      <span><strong><?= View::e($admin['name']) ?></strong><small>Administrateur</small></span>
    </a>
  </aside>

  <div class="main">
    <header class="topbar">
      <p class="crumbs"><span>Espace professionnel</span><span aria-hidden="true">/</span><strong><?= View::e($page['crumb']) ?></strong></p>
      <div class="topbar-tools">
        <span class="date-chip"><?= View::e(View::dateLong($today)) ?></span>
        <?php require __DIR__ . '/_bell.php' ?>
      </div>
    </header>

    <header class="mobile-header">
      <a href="index.php" class="mobile-brand" aria-label="B2B Wheels Collect, accueil"><?= View::logo() ?><span>B2B Wheels<br>Collect</span></a>
      <div class="mobile-tools">
        <?php require __DIR__ . '/_bell.php' ?>
        <a href="compte.php" class="avatar avatar-button" aria-label="Mon compte"><?= View::e(View::initials($admin['name'])) ?></a>
      </div>
    </header>

<?php if ($config->demo()): ?>
    <p class="demo-strip">Mode démonstration : aucun email réel n'est envoyé<?= \Bwc\App::calendar() === null ? ", Google Agenda n'est pas connecté" : '' ?>.</p>
<?php endif ?>

    <main class="content" id="contenu">
<?= $content ?>
    </main>
  </div>

  <nav class="tabbar" aria-label="Sections">
<?php foreach (View::NAV as $key => [$href, , $short, , $mobileIcon]): ?>
    <a href="<?= $href ?>" class="tab<?= $page['active'] === $key ? ' is-active' : '' ?>"<?= $page['active'] === $key ? ' aria-current="page"' : '' ?>><span class="tab-icon"><?= View::icon($mobileIcon) ?></span><?= View::e($short) ?></a>
<?php endforeach ?>
  </nav>

  <dialog class="sheet" id="sheet" aria-labelledby="sheet-title">
    <div class="sheet-head">
      <h2 id="sheet-title">Détails</h2>
      <button type="button" class="icon-btn" data-close aria-label="Fermer"><?= View::icon('x') ?></button>
    </div>
    <div class="sheet-body" id="sheet-body"></div>
  </dialog>

  <dialog class="confirm" id="confirm" aria-labelledby="confirm-title">
    <form method="dialog" class="confirm-form">
      <h2 id="confirm-title"></h2>
      <p class="confirm-text"></p>
      <div class="confirm-extra"></div>
      <div class="confirm-actions">
        <button value="cancel" class="btn btn-outline">Retour</button>
        <button value="ok" class="btn btn-primary confirm-ok"></button>
      </div>
    </form>
  </dialog>

  <div class="toast" role="status" aria-live="polite" hidden></div>

  <template id="booking-form-template">
<?php require __DIR__ . '/_booking-form.php' ?>
  </template>
</body>
</html>
