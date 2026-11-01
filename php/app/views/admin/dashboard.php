<?php
/**
 * Vue d'ensemble.
 *
 * @var array<string, mixed> $stats
 * @var list<array<string, mixed>> $todayRows
 * @var list<array<string, mixed>> $upcoming
 * @var int $now
 */

use Bwc\Admin\View;

$today = $stats['today'];
$monthName = mb_strtolower(\Bwc\Time::formatMonthTitle($stats['month']));
$monthWord = $stats['isCurrentMonth'] ? 'ce mois' : 'en ' . explode(' ', $monthName)[0];
$change = $stats['change'];
$trend = $change === null ? '' : '<span class="trend ' . ($change >= 0 ? 'up' : 'down') . '">' . ($change >= 0 ? '↗ +' : '↘ ') . View::e(str_replace('.', ',', (string) $change)) . ' %</span>';
$max = max(1, ...array_column($stats['chart'], 'completed'));
?>
<?= View::pageHeader(mb_strtoupper(View::dateLong($today)), "Bonjour, l'équipe VBM.", "Voici l'essentiel de votre activité. Tout est sous contrôle.", true, '', "Votre activité en un coup d'œil.") ?>

<?php if ($stats['overdueCount'] > 0): ?>
<a class="notice" href="enlevements.php?tab=a-enlever"><?= View::icon('clock') ?><span><strong><?= $stats['overdueCount'] ?> enlèvement<?= $stats['overdueCount'] > 1 ? 's' : '' ?> passé<?= $stats['overdueCount'] > 1 ? 's' : '' ?></strong> pas encore marqué<?= $stats['overdueCount'] > 1 ? 's' : '' ?> comme enlevé<?= $stats['overdueCount'] > 1 ? 's' : '' ?>. Vérifier</span><?= View::icon('arrow') ?></a>
<?php endif ?>

<section class="kpis" aria-label="Chiffres clés">
  <?= View::kpi("Aujourd'hui", 'calendar', View::count($stats['todayCount']), 'Enlèvements prévus', '', '', 'enlevements.php?tab=aujourdhui') ?>
  <?= View::kpi('À venir', 'clock', View::count($stats['upcomingCount']), 'Prochaines réservations', '', '', 'enlevements.php?tab=a-enlever') ?>
  <?= View::kpi($stats['isCurrentMonth'] ? 'Effectués ce mois' : 'Effectués en ' . explode(' ', $monthName)[0], 'check-circle', View::count($stats['completed']), $trend !== '' ? $trend . ' <span class="desktop-only">' . ($stats['isCurrentMonth'] ? 'vs même période' : 'vs mois précédent') . '</span>' : 'Enlèvements terminés', '', $stats['isCurrentMonth'] ? 'Ce mois-ci' : \Bwc\Time::capitalize(explode(' ', $monthName)[0]), 'enlevements.php?tab=enleves') ?>
  <?= View::kpi('Jantes récupérées', 'bars', (string) $stats['rims'], '<span class="desktop-only">Total de jantes ' . View::e($monthWord) . '</span><span class="mobile-only">' . ($stats['isCurrentMonth'] ? 'Ce mois-ci' : View::e(\Bwc\Time::capitalize($monthName))) . '</span>') ?>
</section>

<div class="dash-grid">
  <section class="panel chart-panel" aria-labelledby="chart-title">
    <div class="panel-head">
      <h2 id="chart-title">Activité des enlèvements</h2>
      <span class="panel-meta">6 derniers mois</span>
    </div>
    <div class="chart-summary">
      <p><strong><?= $stats['completed'] ?></strong> enlèvement<?= $stats['completed'] > 1 ? 's' : '' ?> <?= View::e($monthWord) ?></p>
      <?= $trend ?>
    </div>
    <div class="chart" role="list">
<?php foreach ($stats['chart'] as $bar): ?>
      <a role="listitem" href="?mois=<?= $bar['month'] ?>" class="bar<?= $bar['month'] === $stats['month'] ? ' is-selected' : '' ?>"
         title="<?= View::e(\Bwc\Time::capitalize(\Bwc\Time::formatMonthTitle($bar['month']))) ?> : <?= $bar['completed'] ?> enlèvement(s), <?= $bar['rims'] ?> jantes, <?= $bar['received'] ?> réservation(s) reçue(s)">
        <span class="bar-value"><?= $bar['completed'] ?></span>
        <span class="bar-fill" style="height: <?= max(4, (int) round($bar['completed'] / $max * 100)) ?>%"></span>
        <span class="bar-label"><?= View::e($bar['label']) ?></span>
      </a>
<?php endforeach ?>
    </div>
    <p class="chart-note"><?= $stats['received'] ?> réservation<?= $stats['received'] > 1 ? 's' : '' ?> reçue<?= $stats['received'] > 1 ? 's' : '' ?> <?= View::e($monthWord) ?> · cliquez sur un mois pour le détail</p>
  </section>

  <section class="panel" aria-labelledby="next-title">
    <div class="panel-head">
      <h2 id="next-title">Prochaines collectes</h2>
      <a href="planning.php" class="panel-link"><span class="desktop-only">Voir planning</span><span class="mobile-only">Voir tout</span> <?= View::icon('arrow', 'icon icon-sm') ?></a>
    </div>
<?php if ($upcoming === []): ?>
    <p class="empty">Aucun enlèvement à venir pour l'instant.</p>
<?php else: ?>
    <ul class="next-list">
<?php foreach ($upcoming as $b): ?>
      <li>
        <button type="button" class="next-item" data-booking="<?= $b['id'] ?>">
          <span class="next-time"><?= View::e($b['start_time']) ?><?php if ($b['slot_date'] !== $today): ?><small><?= View::e(View::relativeDay($b['slot_date'], $today)) ?></small><?php endif ?></span>
          <span class="next-main"><strong><?= View::e($b['garage_name']) ?><?= View::photoBadge($b) ?></strong><small><?= View::e($b['city']) ?> · <?= View::e(View::rims($b['rim_count'])) ?></small></span>
          <?= View::pill($b, $now) ?>
        </button>
      </li>
<?php endforeach ?>
    </ul>
<?php endif ?>
  </section>
</div>

<section class="panel table-panel" aria-labelledby="today-title">
  <div class="panel-head">
    <h2 id="today-title">Enlèvements du jour</h2>
    <span class="panel-links">
<?php if ($todayRows !== []): ?>
      <a href="feuille-de-route.php" class="panel-link">Feuille de route <?= View::icon('arrow', 'icon icon-sm') ?></a>
<?php endif ?>
      <a href="enlevements.php?tab=aujourdhui" class="panel-link">Tout afficher <?= View::icon('arrow', 'icon icon-sm') ?></a>
    </span>
  </div>
<?php if ($todayRows === []): ?>
  <p class="empty">Aucun enlèvement prévu aujourd'hui.</p>
<?php else: ?>
  <table class="table desktop-only">
    <thead><tr><th>Heure</th><th>Garage</th><th>Localisation</th><th>Jantes</th><th>Statut</th><th>Action</th></tr></thead>
    <tbody>
<?php foreach ($todayRows as $b): ?>
      <tr>
        <td class="strong"><?= View::e($b['start_time']) ?></td>
        <td><button type="button" class="row-link" data-booking="<?= $b['id'] ?>"><?= View::e($b['garage_name']) ?></button><?= View::photoBadge($b) ?></td>
        <td><?= View::e($b['city']) ?></td>
        <td><?= View::e(View::rims($b['rim_count'])) ?></td>
        <td><?= View::pill($b, $now) ?></td>
        <td><?php require __DIR__ . '/_quick-action.php' ?></td>
      </tr>
<?php endforeach ?>
    </tbody>
  </table>
  <div class="cards mobile-only">
<?php foreach ($todayRows as $b): ?>
    <?php require __DIR__ . '/_booking-card.php' ?>
<?php endforeach ?>
  </div>
<?php endif ?>
</section>
