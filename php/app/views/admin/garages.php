<?php
/**
 * Garages clients : annuaire alimenté par les réservations.
 *
 * @var array{rows: list<array<string, mixed>>, total: int} $result
 * @var string $q
 * @var int $pageNumber
 * @var int $perPage
 */

use Bwc\Admin\View;

$pages = (int) ceil($result['total'] / $perPage);
$link = static fn (int $page): string => '?' . http_build_query(array_filter(['q' => $q, 'page' => $page > 1 ? $page : null]));
$lastDate = static fn (?string $utc): string => $utc === null ? '—' : View::dateShort(\Bwc\Time::dateOf((int) strtotime($utc . ' UTC')));
?>
<?= View::pageHeader('Annuaire des clients', 'Vos garages clients.', 'Chaque garage qui réserve un enlèvement apparaît ici automatiquement, avec son historique.', true, 'Garages', 'Vos clients et leur historique.') ?>

<section class="panel list-panel" aria-labelledby="garages-title">
  <div class="panel-head">
    <div><h2 id="garages-title">Garages</h2><p class="panel-sub"><?= $result['total'] ?> garage<?= $result['total'] > 1 ? 's' : '' ?> client<?= $result['total'] > 1 ? 's' : '' ?></p></div>
  </div>
  <div class="list-tools">
    <form class="search-field search-wide" role="search" data-live-search>
      <?= View::icon('search') ?>
      <input type="search" name="q" value="<?= View::e($q) ?>" class="input" placeholder="Rechercher un garage, une ville, un contact…" aria-label="Rechercher un garage">
    </form>
  </div>

<?php if ($result['rows'] === []): ?>
  <p class="empty"><?= $q !== '' ? 'Aucun garage ne correspond à « ' . View::e($q) . ' ».' : 'Les garages apparaîtront ici dès la première réservation.' ?></p>
<?php else: ?>
  <table class="table desktop-only">
    <thead><tr><th>Garage</th><th>Contact</th><th>Email</th><th>Effectués</th><th>Jantes</th><th>Dernier enlèvement</th></tr></thead>
    <tbody>
<?php foreach ($result['rows'] as $g): ?>
      <tr class="clickable">
        <td><a class="row-link" href="garage.php?id=<?= (int) $g['id'] ?>"><?= View::e($g['name']) ?></a><small class="cell-sub"><?= View::e($g['city']) ?></small></td>
        <td><?= View::e($g['contact_name']) ?><small class="cell-sub"><?= View::e(View::phone($g['phone'])) ?></small></td>
        <td class="muted"><?= View::e($g['email'] ?: '—') ?></td>
        <td><strong class="cell-strong"><?= (int) $g['completed_total'] ?></strong></td>
        <td><?= (int) $g['rims_total'] ?></td>
        <td><?= View::e($lastDate($g['last_completed_at'])) ?></td>
      </tr>
<?php endforeach ?>
    </tbody>
  </table>
  <div class="cards mobile-only">
<?php foreach ($result['rows'] as $g): ?>
    <a class="card card-link" href="garage.php?id=<?= (int) $g['id'] ?>">
      <div class="card-top"><div><h3><?= View::e($g['name']) ?></h3><p class="muted"><?= View::e($g['city']) ?> · <?= View::e($g['contact_name']) ?></p></div><?= View::icon('chevron-right', 'icon muted') ?></div>
      <p class="card-stats"><span><strong><?= (int) $g['completed_total'] ?></strong> enlevé<?= (int) $g['completed_total'] > 1 ? 's' : '' ?></span><span><strong><?= (int) $g['rims_total'] ?></strong> jantes</span><span>Dernier : <?= View::e($lastDate($g['last_completed_at'])) ?></span></p>
    </a>
<?php endforeach ?>
  </div>
<?php endif ?>

<?php if ($pages > 1): ?>
  <div class="panel-foot">
    <span>Page <?= $pageNumber ?> sur <?= $pages ?></span>
    <nav class="pager" aria-label="Pages">
      <a href="<?= View::e($link(max(1, $pageNumber - 1))) ?>" class="icon-btn<?= $pageNumber <= 1 ? ' is-disabled' : '' ?>" aria-label="Page précédente"><?= View::icon('chevron-left') ?></a>
      <a href="<?= View::e($link(min($pages, $pageNumber + 1))) ?>" class="icon-btn<?= $pageNumber >= $pages ? ' is-disabled' : '' ?>" aria-label="Page suivante"><?= View::icon('chevron-right') ?></a>
    </nav>
  </div>
<?php endif ?>
</section>
