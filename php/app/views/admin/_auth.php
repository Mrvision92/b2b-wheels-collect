<?php
/**
 * Page de connexion, de mot de passe ou d'installation : sans menu.
 *
 * @var string $title
 * @var string $heading
 * @var string $intro
 * @var string $body formulaire déjà échappé
 */

use Bwc\Admin\View;
?>
<!doctype html>
<html lang="fr-BE">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <title><?= View::e($title) ?> — B2B Wheels Collect</title>
  <link rel="icon" href="../favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= View::asset('assets/admin.css') ?>">
  <script src="<?= View::asset('assets/password.js') ?>" defer></script>
</head>
<body class="auth-page">
  <main class="auth">
    <p class="auth-brand"><?= View::logo() ?><span><strong>B2B Wheels<br>Collect</strong><small>Administration</small></span></p>
    <h1><?= View::e($heading) ?></h1>
    <p class="auth-intro"><?= View::e($intro) ?></p>
<?= $body ?>
  </main>
</body>
</html>
