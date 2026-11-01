<?php
/**
 * Enlèvements : liste filtrée, recherche, tri et pagination.
 *
 * @var array<string, mixed> $stats
 * @var array{rows: list<array<string, mixed>>, total: int} $result
 * @var string $tab
 * @var string $q
 * @var string $sort
 * @var int $pageNumber
 * @var int $perPage
 * @var int $now
 * @var string $today
 */

use Bwc\Admin\View;

$tabs = ['tous' => 'Tous', 'aujourdhui' => "Aujourd'hui", 'a-enlever' => 'À enlever', 'enleves' => 'Enlevés', 'annules' => 'Annulés'];
$query = static fn (array $changes): string => '?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'q' => $q, 'tri' => $sort, 'page' => null], $changes), static fn ($v) => $v !== null && $v !== '' && $v !== 'tous'));
$pages = (int) ceil($result['total'] / $perPage);
$sortLink = static function (string $key, string $label) use ($sort, $query): string {
    $active = ltrim($sort, '-') === $key;
    $next = $active && !str_starts_with($sort, '-') ? "-$key" : $key;
    return '<a href="' . View::e($query(['tri' => $next])) . '" class="sort' . ($active ? ' is-active' : '') . '">' . $label . ($active ? (str_starts_with($sort, '-') ? ' ↓' : ' ↑') : '') . '</a>';
};
?>
<?= View::pageHeader('Gestion des collectes', 'Tous vos enlèvements.', 'Un seul endroit pour organiser, suivre et confirmer chaque collecte de jantes.', true, 'Enlèvements', 'Suivez et confirmez vos collectes.') ?>

<section class="kpis" aria-label="Chiffres clés">
  <?= View::kpi("Aujourd'hui", 'calendar', View::count($stats['todayCount']), 'Programmés ce jour', '', '', '?tab=aujourdhui') ?>
  <?= View::kpi('À venir', 'clock', View::count($stats['upcomingCount']), 'En attente de collecte', '', '', '?tab=a-enlever') ?>
  <?= View::kpi('Effectués ce mois', 'check-circle', View::count($stats['completed']), 'Enlèvements terminés', 'desktop-only', '', '?tab=enleves') ?>
  <?= View::kpi('Jantes récupérées', 'bars', (string) $stats['rims'], 'Volume mensuel', 'desktop-only') ?>
</section>

<section class="panel list-panel" aria-labelledby="list-title">
  <div class="panel-head">
    <div><h2 id="list-title">Liste des enlèvements</h2><p class="panel-sub">Réservations et suivi de récupération</p></div>
    <a href="export.php<?= View::e($query([])) ?>" class="panel-link"><?= View::icon('download', 'icon icon-sm') ?>Exporter (CSV)</a>
  </div>
  <div class="list-tools">
    <nav class="segmented" aria-label="Filtres">
<?php foreach ($tabs as $key => $label): ?>
      <a href="<?= View::e($query(['tab' => $key, 'tri' => null])) ?>" class="<?= $tab === $key ? 'is-active' : '' ?><?= in_array($key, ['aujourdhui', 'annules'], true) ? ' optional-tab' : '' ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= View::e($label) ?></a>
<?php endforeach ?>
    </nav>
    <form class="search-field" role="search" data-live-search>
      <?= View::icon('search') ?>
      <input type="search" name="q" value="<?= View::e($q) ?>" class="input" placeholder="Rechercher un garage…" aria-label="Rechercher">
<?php if ($tab !== 'tous'): ?><input type="hidden" name="tab" value="<?= View::e($tab) ?>"><?php endif ?>
    </form>
  </div>

<?php if ($result['rows'] === []): ?>
  <p class="empty"><?= $q !== '' ? 'Aucun enlèvement ne correspond à « ' . View::e($q) . ' ».' : 'Aucun enlèvement dans cette liste.' ?></p>
<?php else: ?>
  <table class="table desktop-only">
    <thead><tr><th>Référence</th><th><?= $sortLink('garage', 'Garage') ?></th><th><?= $sortLink('date', 'Date / heure') ?></th><th>Jantes</th><th>Statut</th><th>Actions</th></tr></thead>
    <tbody>
<?php foreach ($result['rows'] as $b): ?>
      <tr>
        <td class="muted mono"><?= View::e($b['reference']) ?></td>
        <td><button type="button" class="row-link" data-booking="<?= $b['id'] ?>"><?= View::e($b['garage_name']) ?></button><?= View::photoBadge($b) ?><small class="cell-sub"><?= View::e($b['city']) ?></small></td>
        <td><strong class="cell-strong"><?= View::e(View::dateShort($b['slot_date'])) ?></strong><small class="cell-sub"><?= View::e(View::range($b)) ?></small></td>
        <td><strong class="cell-strong"><?= $b['rim_count'] ?></strong> jante<?= $b['rim_count'] > 1 ? 's' : '' ?></td>
        <td><?= View::pill($b, $now) ?></td>
        <td class="actions">
          <button type="button" class="btn btn-outline btn-sm" data-booking="<?= $b['id'] ?>">Détails <?= View::icon('arrow', 'icon icon-sm') ?></button>
<?php if (in_array($b['status'], ['confirmed', 'pending'], true)): ?><?php require __DIR__ . '/_quick-action.php' ?><?php endif ?>
        </td>
      </tr>
<?php endforeach ?>
    </tbody>
  </table>

  <div class="cards mobile-only">
<?php $group = null; foreach ($result['rows'] as $b): ?>
<?php if ($group !== $b['slot_date']): $group = $b['slot_date']; ?>
    <p class="group-title"><span><?= View::e(View::relativeDay($b['slot_date'], $today) === "Aujourd'hui" ? "Aujourd'hui" : View::dayMonth($b['slot_date'])) ?></span><span class="muted"><?= View::e(View::dateShort($b['slot_date'])) ?></span></p>
<?php endif ?>
    <?php require __DIR__ . '/_booking-card.php' ?>
<?php endforeach ?>
  </div>
<?php endif ?>

  <div class="panel-foot">
    <span><?= $result['total'] ?> enlèvement<?= $result['total'] > 1 ? 's' : '' ?><?= $q !== '' ? ' trouvé' . ($result['total'] > 1 ? 's' : '') : '' ?></span>
<?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Pages">
      <a href="<?= View::e($query(['page' => max(1, $pageNumber - 1)])) ?>" class="icon-btn<?= $pageNumber <= 1 ? ' is-disabled' : '' ?>" aria-label="Page précédente"><?= View::icon('chevron-left') ?></a>
      <span>Page <?= $pageNumber ?> sur <?= $pages ?></span>
      <a href="<?= View::e($query(['page' => min($pages, $pageNumber + 1)])) ?>" class="icon-btn<?= $pageNumber >= $pages ? ' is-disabled' : '' ?>" aria-label="Page suivante"><?= View::icon('chevron-right') ?></a>
    </nav>
<?php else: ?>
    <span class="desktop-only">Liste mise à jour à chaque réservation</span>
<?php endif ?>
  </div>
</section>
