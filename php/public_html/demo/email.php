<?php
/**
 * Mode démonstration uniquement : affiche un email simulé tel qu'il aurait été
 * envoyé. Répond 404 dès qu'un vrai service d'envoi est configuré.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\App;
use Bwc\Mail\DemoMailer;
use Bwc\Mail\Templates;

$mailer = App::mailer();
$email = $mailer instanceof DemoMailer ? $mailer->read((string) ($_GET['id'] ?? '')) : null;

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('X-Content-Type-Options: nosniff');

if (!$mailer instanceof DemoMailer) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Introuvable');
}
if ($email === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Cet email n'a pas encore été généré. Rechargez la page dans un instant.");
}

header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src data: https:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
header('Content-Type: text/html; charset=utf-8');
$e = [Templates::class, 'e'];
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($email['subject']) ?></title>
<style>
  body{margin:0;font:14px/1.45 system-ui,-apple-system,sans-serif;color:#1d1d1f;background:#f2f2f0}
  header{padding:14px 20px;background:#1d1d1f;color:#fff}
  header strong{font-weight:600}
  dl{margin:10px 0 0;display:grid;grid-template-columns:auto 1fr;gap:2px 12px;color:#d2d2d0}
  dd{margin:0;color:#fff;overflow-wrap:anywhere}
  iframe{display:block;width:100%;height:calc(100vh - 120px);border:0;background:#fff}
  .files{padding:14px 20px;background:#fff;border-bottom:1px solid #e3e3e1}
  .files div{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px}
  .files figure{margin:0;width:132px}
  .files img{width:132px;height:100px;object-fit:cover;display:block;border:1px solid #e3e3e1}
  .files figcaption{font-size:12px;color:#6e6e73;margin-top:4px}
</style></head>
<body>
<header>
  <strong>Email simulé — mode démonstration, aucun envoi réel</strong>
  <dl><dt>À</dt><dd><?= $e(implode(', ', $email['to'])) ?></dd><dt>Objet</dt><dd><?= $e($email['subject']) ?></dd></dl>
</header>
<?php if (!empty($email['attachments'])): ?>
<section class="files"><strong>Pièces jointes (<?= count($email['attachments']) ?>)</strong><div>
<?php foreach ($email['attachments'] as $file): ?>
  <figure><img src="data:<?= $e($file['content_type']) ?>;base64,<?= $file['base64'] ?>" alt="<?= $e($file['filename']) ?>"><figcaption><?= $e($file['filename']) ?> · <?= (int) round(strlen($file['base64']) * 3 / 4 / 1024) ?> Ko</figcaption></figure>
<?php endforeach ?>
</div></section>
<?php endif ?>
<iframe sandbox="allow-popups allow-popups-to-escape-sandbox" title="Aperçu de l'email" srcdoc="<?= $e($email['html']) ?>"></iframe>
</body></html>
