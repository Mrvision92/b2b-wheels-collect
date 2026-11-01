<?php
/**
 * Feuille de route d'une journée : enlèvements dans l'ordre des créneaux,
 * avec adresses, téléphones et instructions, imprimable, et la tournée dans
 * Google Maps (dans l'ordre des créneaux, sans optimisation).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\Admin\Auth;
use Bwc\Admin\View;
use Bwc\App;
use Bwc\Time;

$admin = Auth::requirePage();
$now = time();
$date = Time::isValidDate($_GET['date'] ?? null) ? $_GET['date'] : Time::dateOf($now);
$rows = App::store()->between($date, $date);
$open = array_values(array_filter($rows, static fn (array $b): bool => in_array($b['status'], ['pending', 'confirmed'], true)));
$rims = array_sum(array_column($rows, 'rim_count'));

// Google Maps accepte une destination et 9 étapes au plus (3 sur certains téléphones).
$stops = array_map(static fn (array $b): string => "{$b['street']}, {$b['postal_code']} {$b['city']}, Belgique", array_slice($open, 0, 10));
$tour = $stops === [] ? null : 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' . rawurlencode((string) end($stops))
    . (count($stops) > 1 ? '&waypoints=' . rawurlencode(implode('|', array_slice($stops, 0, -1))) : '');

ob_start(); ?>
<p class="back no-print"><a href="planning.php?vue=day&amp;date=<?= View::e($date) ?>"><?= View::icon('chevron-left', 'icon icon-sm') ?>Planning</a></p>
<div class="page-head">
  <div>
    <p class="eyebrow">Feuille de route</p>
    <h1 class="page-title"><?= View::e(View::dateLong($date)) ?></h1>
    <p class="page-subtitle"><?= count($rows) ?> enlèvement<?= count($rows) > 1 ? 's' : '' ?> · <?= $rims ?> jante<?= $rims > 1 ? 's' : '' ?><?= count($open) !== count($rows) ? ' · ' . count($open) . ' encore à faire' : '' ?></p>
  </div>
  <div class="head-actions no-print">
<?php if ($tour): ?>
    <a class="btn btn-outline" href="<?= View::e($tour) ?>" target="_blank" rel="noopener"><?= View::icon('pin', 'icon icon-sm') ?>Tournée dans Google Maps</a>
<?php endif ?>
    <button type="button" class="btn btn-primary" data-print><?= View::icon('download', 'icon icon-sm') ?>Imprimer</button>
  </div>
</div>
<?php if (count($open) > 10): ?><p class="notice no-print">Google Maps n'accepte que 10 adresses : la tournée s'arrête au 10e enlèvement.</p><?php endif ?>

<?php if ($rows === []): ?>
<section class="panel"><p class="empty" style="padding-top:24px">Aucun enlèvement prévu ce jour-là.</p></section>
<?php else: ?>
<section class="panel route">
<?php foreach ($rows as $i => $b): ?>
  <article class="route-stop<?= $b['status'] === 'completed' ? ' is-done' : '' ?>">
    <div class="route-time"><span class="route-num"><?= $i + 1 ?></span><strong><?= View::e(View::range($b)) ?></strong><?= View::pill($b, $now) ?></div>
    <div class="route-main">
      <h2><?= View::e($b['garage_name']) ?><?= View::photoBadge($b) ?></h2>
      <p><a href="<?= View::e(View::mapsUrl($b)) ?>" target="_blank" rel="noopener"><?= View::e("{$b['street']}, {$b['postal_code']} {$b['city']}") ?></a></p>
      <p><?= View::e($b['contact_name']) ?> · <a href="tel:<?= View::e($b['phone']) ?>"><?= View::e(View::phone($b['phone'])) ?></a></p>
<?php if ($b['comment'] !== ''): ?><p class="route-note"><strong>Instructions :</strong> <?= View::e($b['comment']) ?></p><?php endif ?>
<?php if ($b['internal_note'] !== ''): ?><p class="route-note"><strong>Note VBM :</strong> <?= View::e($b['internal_note']) ?></p><?php endif ?>
    </div>
    <div class="route-side">
      <p class="route-rims"><strong><?= $b['rim_count'] ?></strong> jante<?= $b['rim_count'] > 1 ? 's' : '' ?></p>
      <p class="muted mono"><?= View::e($b['reference']) ?><?= $b['garage_reference'] !== '' ? '<br>Réf. ' . View::e($b['garage_reference']) : '' ?></p>
      <span class="route-check print-only">☐ Enlevé</span>
    </div>
  </article>
<?php endforeach ?>
</section>
<?php endif;
$content = (string) ob_get_clean();
View::page($content, ['active' => 'planning', 'crumb' => 'Feuille de route', 'title' => 'Feuille de route ' . $date, 'admin' => $admin]);
