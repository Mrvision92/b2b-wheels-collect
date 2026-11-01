<?php
/** Action rapide d'une réservation ($b) : marquer enlevé, confirmer, ou état. */

use Bwc\Admin\View;

if ($b['status'] === 'confirmed'): ?>
<button type="button" class="btn btn-primary btn-sm" data-action="complete" data-id="<?= $b['id'] ?>" data-garage="<?= View::e($b['garage_name']) ?>" data-rims="<?= $b['rim_count'] ?>" data-email="<?= $b['email'] !== '' ? '1' : '' ?>"><?= View::icon('check', 'icon icon-sm') ?>Marquer enlevé</button>
<?php elseif ($b['status'] === 'pending'): ?>
<button type="button" class="btn btn-outline btn-sm" data-action="confirm" data-id="<?= $b['id'] ?>" data-garage="<?= View::e($b['garage_name']) ?>"><?= View::icon('check', 'icon icon-sm') ?>Confirmer</button>
<?php elseif ($b['status'] === 'completed'): ?>
<span class="done-chip"><?= View::icon('check', 'icon icon-sm') ?>Effectué</span>
<?php endif;
