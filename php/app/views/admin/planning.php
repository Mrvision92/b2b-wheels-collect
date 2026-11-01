<?php
/**
 * Planning : vues jour, semaine et mois.
 *
 * @var string $view day|week|month
 * @var string $date date de référence
 * @var list<string> $days dates affichées (jour, semaine ou grille du mois)
 * @var array<string, list<array<string, mixed>>> $byDate réservations par date, avec lane / lanes
 * @var int $firstHour
 * @var int $lastHour
 * @var string $title
 * @var string $prev
 * @var string $next
 * @var bool $withCancelled
 * @var string $hoursLabel
 * @var int $now
 * @var string $today
 */

use Bwc\Admin\View;

$link = static fn (array $changes): string => '?' . http_build_query(array_filter(array_merge(['vue' => $view, 'date' => $date, 'annules' => $withCancelled ? '1' : null], $changes)));
$all = array_merge(...array_values($byDate ?: [[]]));
$count = count($all);
$rims = array_sum(array_column($all, 'rim_count'));
$subtitle = ['day' => 'Planning du jour', 'week' => 'Planning de la semaine', 'month' => 'Planning du mois'][$view];
$period = ['day' => 'ce jour', 'week' => 'cette semaine', 'month' => 'ce mois'][$view];
$eventClass = static fn (array $b): string => 'event event-' . (View::isOverdue($b, $now) ? 'late' : $b['status']);
?>
<?= View::pageHeader('Organisation des collectes', 'Le planning des enlèvements.', 'Visualisez les créneaux et organisez vos prochaines collectes.', true, 'Planning', 'Vos enlèvements, jour par jour.') ?>

<section class="panel planning" aria-labelledby="planning-title">
  <div class="planning-bar">
    <div class="planning-heading">
      <h2 id="planning-title"><?= View::e($title) ?></h2>
      <p class="panel-sub"><?= View::e($subtitle) ?></p>
    </div>
    <nav class="segmented views" aria-label="Affichage">
<?php foreach (['day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois'] as $key => $label): ?>
      <a href="<?= View::e($link(['vue' => $key])) ?>" class="<?= $view === $key ? 'is-active' : '' ?>"<?= $view === $key ? ' aria-current="page"' : '' ?>><?= $label ?></a>
<?php endforeach ?>
    </nav>
  </div>
  <div class="planning-tools">
    <div class="planning-nav">
      <a href="<?= View::e($link(['date' => $prev])) ?>" class="btn btn-outline btn-square" aria-label="Période précédente"><?= View::icon('chevron-left') ?></a>
      <a href="<?= View::e($link(['date' => $next])) ?>" class="btn btn-outline btn-square" aria-label="Période suivante"><?= View::icon('chevron-right') ?></a>
      <a href="<?= View::e($link(['date' => $today])) ?>" class="btn btn-outline">Aujourd'hui</a>
    </div>
    <div class="legend">
      <span class="pill pill-confirmed"><span class="pill-dot"></span>À enlever</span>
      <span class="pill pill-completed"><span class="pill-dot"></span>Enlevé</span>
      <a href="<?= View::e($link(['annules' => $withCancelled ? null : '1'])) ?>" class="legend-toggle"><?= $withCancelled ? 'Masquer les annulés' : 'Afficher les annulés' ?></a>
    </div>
  </div>

<?php if ($view === 'week'): ?>
  <div class="week desktop-only" style="--hours: <?= $lastHour - $firstHour ?>; --days: <?= count($days) ?>">
    <div class="week-head">
      <span></span>
<?php foreach ($days as $day): ?>
      <a href="<?= View::e($link(['vue' => 'day', 'date' => $day])) ?>" class="week-day<?= $day === $today ? ' is-today' : '' ?>"><?= View::e(explode(' ', View::dayNumber($day))[0]) ?> <span class="day-num"><?= (int) substr($day, 8) ?></span></a>
<?php endforeach ?>
    </div>
    <div class="week-body">
      <div class="hours">
<?php for ($h = $firstHour; $h < $lastHour; $h++): ?>
        <span><?= sprintf('%02d:00', $h) ?></span>
<?php endfor ?>
      </div>
<?php foreach ($days as $day): ?>
      <div class="day-col<?= $day === $today ? ' is-today' : '' ?>">
<?php foreach ($byDate[$day] ?? [] as $b):
        $start = (\Bwc\Time::toMinutes($b['start_time']) - $firstHour * 60) / 60;
        $length = (\Bwc\Time::toMinutes($b['end_time']) - \Bwc\Time::toMinutes($b['start_time'])) / 60; ?>
        <button type="button" class="<?= $eventClass($b) ?>" data-booking="<?= $b['id'] ?>" style="--start: <?= $start ?>; --len: <?= $length ?>; --lane: <?= $b['lane'] ?>; --lanes: <?= $b['lanes'] ?>">
          <span class="event-time"><?= View::e(View::range($b)) ?></span>
          <strong><?= View::e($b['garage_name']) ?></strong>
          <span class="event-meta"><?= View::e($b['city']) ?> · <?= View::e(View::rims($b['rim_count'])) ?></span>
          <?= View::pill($b, $now) ?>
        </button>
<?php endforeach ?>
      </div>
<?php endforeach ?>
    </div>
  </div>
  <div class="week-list mobile-only">
<?php foreach ($days as $day): ?>
    <p class="group-title"><span><?= View::e(View::dayMonth($day)) ?></span><span class="muted"><?= count($byDate[$day] ?? []) ?> enlèvement<?= count($byDate[$day] ?? []) > 1 ? 's' : '' ?></span></p>
<?php foreach ($byDate[$day] ?? [] as $b): ?>
    <button type="button" class="timeline-card <?= $eventClass($b) ?>" data-booking="<?= $b['id'] ?>"><span class="event-time"><?= View::e(View::range($b)) ?></span><strong><?= View::e($b['garage_name']) ?></strong><span class="event-meta"><?= View::e($b['city']) ?> · <?= View::e(View::rims($b['rim_count'])) ?></span><?= View::pill($b, $now) ?></button>
<?php endforeach ?>
<?php endforeach ?>
  </div>

<?php elseif ($view === 'day'): ?>
  <div class="day-strip" role="list">
<?php foreach ($days as $day): ?>
    <a role="listitem" href="<?= View::e($link(['date' => $day])) ?>" class="strip-day<?= $day === $date ? ' is-selected' : '' ?><?= $day === $today ? ' is-today' : '' ?>"<?= $day === $date ? ' aria-current="date"' : '' ?>><span><?= View::e(View::shortDay($day)) ?></span><strong><?= (int) substr($day, 8) ?></strong></a>
<?php endforeach ?>
  </div>
  <div class="day-view">
    <h3 class="day-title"><?= View::e(View::dayMonth($date)) ?></h3>
    <p class="day-meta"><?= $count ?> enlèvement<?= $count > 1 ? 's' : '' ?> · <?= $rims ?> jante<?= $rims > 1 ? 's' : '' ?></p>
<?php if (($byDate[$date] ?? []) === []): ?>
    <p class="empty">Aucun enlèvement ce jour-là.</p>
<?php else: ?>
    <ol class="timeline">
<?php foreach ($byDate[$date] as $b): ?>
      <li class="timeline-item">
        <span class="timeline-time"><?= View::e($b['start_time']) ?></span>
        <span class="timeline-dot dot-<?= View::isOverdue($b, $now) ? 'late' : $b['status'] ?>" aria-hidden="true"></span>
        <button type="button" class="timeline-card <?= $eventClass($b) ?>" data-booking="<?= $b['id'] ?>">
          <span class="event-time"><?= View::e(View::range($b)) ?></span>
          <strong><?= View::e($b['garage_name']) ?></strong>
          <span class="event-meta"><?= View::e($b['city']) ?> · <?= View::e(View::rims($b['rim_count'])) ?></span>
          <?= View::pill($b, $now) ?>
        </button>
      </li>
<?php endforeach ?>
    </ol>
<?php endif ?>
  </div>

<?php else: ?>
  <div class="month">
    <div class="month-head"><?php foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $d): ?><span><?= $d ?></span><?php endforeach ?></div>
    <div class="month-grid">
<?php foreach ($days as $day):
        $inMonth = substr($day, 0, 7) === substr($date, 0, 7);
        $items = $byDate[$day] ?? []; ?>
      <a href="<?= View::e($link(['vue' => 'day', 'date' => $day])) ?>" class="month-cell<?= $inMonth ? '' : ' is-outside' ?><?= $day === $today ? ' is-today' : '' ?>" aria-label="<?= View::e(View::dateLong($day)) ?> : <?= count($items) ?> enlèvement(s)">
        <span class="month-day"><?= (int) substr($day, 8) ?></span>
<?php foreach (array_slice($items, 0, 3) as $b): ?>
        <span class="month-item item-<?= View::isOverdue($b, $now) ? 'late' : $b['status'] ?>"><?= View::e($b['start_time']) ?> <?= View::e($b['garage_name']) ?></span>
<?php endforeach ?>
<?php if (count($items) > 3): ?><span class="month-more">+<?= count($items) - 3 ?> autre<?= count($items) > 4 ? 's' : '' ?></span><?php endif ?>
<?php if ($items !== []): ?><span class="month-count"><?= count($items) ?></span><?php endif ?>
      </a>
<?php endforeach ?>
    </div>
  </div>
<?php endif ?>

  <div class="panel-foot">
    <span><?= $count ?> enlèvement<?= $count > 1 ? 's' : '' ?> <?= $period ?></span>
    <span>Horaires : <?= View::e($hoursLabel) ?></span>
  </div>
</section>
