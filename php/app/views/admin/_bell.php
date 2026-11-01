<?php
/**
 * Cloche : dernières réservations reçues sur le site. Un point s'affiche
 * quand une réservation est arrivée depuis la dernière ouverture.
 *
 * @var list<array<string, mixed>> $latest
 * @var int $latestAt
 * @var string $today
 */

use Bwc\Admin\View;
?>
<div class="bell" data-bell data-latest="<?= (int) $latestAt ?>">
  <button type="button" class="icon-btn bell-button" aria-expanded="false" aria-label="Dernières réservations reçues"><?= View::icon('bell') ?><span class="bell-dot" hidden></span></button>
  <div class="bell-panel" hidden>
    <p class="bell-title">Dernières réservations reçues</p>
<?php if ($latest === []): ?>
    <p class="bell-empty">Aucune réservation reçue sur le site pour l'instant.</p>
<?php else: ?>
    <ul>
<?php foreach ($latest as $b): ?>
      <li><button type="button" data-booking="<?= $b['id'] ?>">
        <strong><?= View::e($b['garage_name']) ?></strong>
        <span><?= View::e(View::relativeDay($b['slot_date'], $today)) ?> · <?= View::e($b['start_time']) ?> · <?= View::e(View::rims($b['rim_count'])) ?></span>
        <small>Reçue le <?= View::e(View::datetime($b['created_at'])) ?></small>
      </button></li>
<?php endforeach ?>
    </ul>
<?php endif ?>
  </div>
</div>
