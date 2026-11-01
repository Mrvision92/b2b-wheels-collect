<?php
/** Planning : jour, semaine, mois. */

declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

use Bwc\Admin\Auth;
use Bwc\Admin\View;
use Bwc\App;
use Bwc\Time;

$admin = Auth::requirePage();
$now = time();
$today = Time::dateOf($now);
$config = App::config()->booking();
$view = in_array($_GET['vue'] ?? '', ['day', 'week', 'month'], true) ? $_GET['vue'] : 'week';
$date = Time::isValidDate($_GET['date'] ?? null) ? $_GET['date'] : $today;
$withCancelled = ($_GET['annules'] ?? '') === '1';
$monday = Time::addDays($date, 1 - Time::isoWeekday($date));

if ($view === 'day') {
    $days = array_map(static fn (int $i): string => Time::addDays($monday, $i), range(0, 6));
    [$from, $to, $prev, $next] = [$date, $date, Time::addDays($date, -1), Time::addDays($date, 1)];
    $title = View::dateLong($date);
} elseif ($view === 'week') {
    // Samedi et dimanche seulement s'ils sont ouverts ou s'il y a un enlèvement.
    $weekend = !empty($config['weekly_schedule'][6]) || !empty($config['weekly_schedule'][7])
        || App::store()->between(Time::addDays($monday, 5), Time::addDays($monday, 6), $withCancelled) !== [];
    $days = array_map(static fn (int $i): string => Time::addDays($monday, $i), range(0, $weekend ? 6 : 4));
    [$from, $to, $prev, $next] = [$days[0], end($days), Time::addDays($monday, -7), Time::addDays($monday, 7)];
    [, $m1, $d1] = explode('-', $from);
    [$y2, $m2, $d2] = explode('-', $to);
    $title = $m1 === $m2
        ? (int) $d1 . ' – ' . (int) $d2 . ' ' . Time::formatMonthTitle("$y2-$m2")
        : View::dateShort($from) . ' – ' . View::dateShort($to);
} else {
    $first = substr($date, 0, 7) . '-01';
    $last = sprintf('%s-%02d', substr($date, 0, 7), Time::daysInMonth(substr($date, 0, 7)));
    $from = Time::addDays($first, 1 - Time::isoWeekday($first));
    $to = Time::addDays($last, 7 - Time::isoWeekday($last));
    $days = [];
    for ($d = $from; $d <= $to; $d = Time::addDays($d, 1)) {
        $days[] = $d;
    }
    [$prev, $next] = [Time::addMonths(substr($date, 0, 7), -1) . '-01', Time::addMonths(substr($date, 0, 7), 1) . '-01'];
    $title = Time::capitalize(Time::formatMonthTitle(substr($date, 0, 7)));
}

// Réservations par jour, réparties en colonnes quand elles se chevauchent.
$byDate = [];
foreach (App::store()->between($from, $to, $withCancelled) as $b) {
    $byDate[$b['slot_date']][] = $b;
}
foreach ($byDate as &$items) {
    $laneEnds = [];
    $cluster = [];
    $clusterEnd = 0;
    $close = static function () use (&$cluster, &$items): void {
        $lanes = 0;
        foreach ($cluster as $i) {
            $lanes = max($lanes, $items[$i]['lane'] + 1);
        }
        foreach ($cluster as $i) {
            $items[$i]['lanes'] = $lanes;
        }
        $cluster = [];
    };
    foreach ($items as $i => $b) {
        if ($b['start_ts'] >= $clusterEnd && $cluster !== []) {
            $close();
            $laneEnds = [];
        }
        $lane = 0;
        while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $b['start_ts']) {
            $lane++;
        }
        $laneEnds[$lane] = $b['end_ts'];
        $items[$i]['lane'] = $lane;
        $cluster[] = $i;
        $clusterEnd = max($clusterEnd, $b['end_ts']);
    }
    $close();
}
unset($items);

// Échelle des heures : les horaires d'ouverture, une heure de marge, et tout enlèvement en dehors.
$open = [];
foreach ($config['weekly_schedule'] as $windows) {
    foreach ($windows as [$start, $end]) {
        $open[] = Time::toMinutes($start);
        $open[] = Time::toMinutes($end);
    }
}
foreach (array_merge(...array_values($byDate ?: [[]])) as $b) {
    $open[] = Time::toMinutes($b['start_time']);
    $open[] = Time::toMinutes($b['end_time']);
}
$open = $open ?: [540, 1020];
$firstHour = max(0, intdiv(min($open), 60) - 1);
$lastHour = min(24, (int) ceil(max($open) / 60) + 1);
$hours = [];
foreach ($config['weekly_schedule'] as $windows) {
    foreach ($windows as [$start, $end]) {
        $hours[] = "$start – $end";
    }
}

View::render('planning', [
    'view' => $view,
    'date' => $date,
    'days' => $days,
    'byDate' => $byDate,
    'firstHour' => $firstHour,
    'lastHour' => $lastHour,
    'title' => $title,
    'prev' => $prev,
    'next' => $next,
    'withCancelled' => $withCancelled,
    'hoursLabel' => implode(', ', array_unique($hours)) ?: 'fermé',
    'now' => $now,
    'today' => $today,
], ['active' => 'planning', 'crumb' => 'Planning', 'title' => 'Planning', 'admin' => $admin]);
