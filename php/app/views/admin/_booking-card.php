<?php
/**
 * Carte d'enlèvement pour téléphone.
 *
 * @var array<string, mixed> $b
 * @var int $now
 * @var string $today
 */

use Bwc\Admin\View;
?>
<article class="card">
  <div class="card-top">
    <div>
      <h3><?= View::e($b['garage_name']) ?><?= View::photoBadge($b) ?></h3>
      <p class="muted"><?= View::e($b['city']) ?> · <?= View::e(View::rims($b['rim_count'])) ?></p>
    </div>
    <?= View::pill($b, $now) ?>
  </div>
  <p class="card-when"><?= View::icon('calendar', 'icon icon-sm') ?><?= View::e(View::relativeDay($b['slot_date'], $today)) ?> · <?= View::e(View::range($b)) ?></p>
  <div class="card-actions">
<?php if (in_array($b['status'], ['confirmed', 'pending'], true)): ?>
    <button type="button" class="btn btn-outline" data-booking="<?= $b['id'] ?>">Détails <?= View::icon('arrow', 'icon icon-sm') ?></button>
    <?php require __DIR__ . '/_quick-action.php' ?>
<?php else: ?>
    <button type="button" class="btn btn-outline btn-wide" data-booking="<?= $b['id'] ?>">Voir les détails <?= View::icon('arrow', 'icon icon-sm') ?></button>
<?php endif ?>
  </div>
</article>
