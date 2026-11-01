<?php
/**
 * Fiche d'un garage : coordonnées, activité, historique, doublons.
 *
 * @var array<string, mixed> $garage
 * @var array<string, mixed> $activity
 * @var list<array<string, mixed>> $bookings
 * @var array{contacts: list<array<string, string>>, addresses: list<array<string, string>>} $variants
 * @var int $now
 * @var string $today
 */

use Bwc\Admin\View;

$toDate = static fn (?string $utc): string => $utc === null ? '—' : View::dateShort(\Bwc\Time::dateOf((int) strtotime($utc . ' UTC')));
$prefill = json_encode([
    'garageId' => $garage['id'], 'garageName' => $garage['name'], 'contactName' => $garage['contact_name'], 'phone' => View::phone($garage['phone']),
    'email' => $garage['email'], 'street' => $garage['street'], 'postalCode' => $garage['postal_code'], 'city' => $garage['city'],
], JSON_UNESCAPED_UNICODE);
?>
<p class="back"><a href="garages.php"><?= View::icon('chevron-left', 'icon icon-sm') ?>Garages clients</a></p>
<div class="page-head">
  <div>
    <p class="eyebrow">Fiche client</p>
    <h1 class="page-title"><?= View::e($garage['name']) ?></h1>
    <p class="page-subtitle"><?= View::e(trim($garage['city'] . ($garage['contact_name'] !== '' ? ' · ' . $garage['contact_name'] : ''), ' ·')) ?></p>
  </div>
  <button type="button" class="btn btn-primary btn-new" data-new-booking="<?= View::e($prefill) ?>"><?= View::icon('plus') ?>Nouvel enlèvement</button>
</div>

<div class="quick-actions">
<?php if ($garage['phone'] !== ''): ?><a class="btn btn-outline" href="tel:<?= View::e($garage['phone']) ?>"><?= View::icon('phone', 'icon icon-sm') ?>Appeler</a><?php endif ?>
<?php if ($garage['email'] !== ''): ?><a class="btn btn-outline" href="mailto:<?= View::e($garage['email']) ?>"><?= View::icon('mail', 'icon icon-sm') ?>Email</a><?php endif ?>
<?php if ($garage['street'] !== ''): ?><a class="btn btn-outline" href="<?= View::e(View::mapsUrl($garage)) ?>" target="_blank" rel="noopener"><?= View::icon('pin', 'icon icon-sm') ?>Itinéraire</a><?php endif ?>
</div>

<section class="kpis kpis-5" aria-label="Activité">
  <?= View::kpi('Réservations', 'calendar', (string) (int) $activity['bookings_total'], 'Au total') ?>
  <?= View::kpi('Effectués', 'check-circle', (string) (int) $activity['completed_total'], 'Enlèvements terminés') ?>
  <?= View::kpi('Jantes', 'bars', (string) (int) $activity['rims_total'], 'Récupérées au total') ?>
  <?= View::kpi('Dernier', 'clock', $toDate($activity['last_completed_at']), 'Dernière collecte', 'kpi-text') ?>
  <?= View::kpi('Prochain', 'calendar', $toDate($activity['next_starts_at']), 'Prochaine collecte', 'kpi-text') ?>
</section>

<div class="dash-grid dash-grid-reverse">
  <section class="panel table-panel" aria-labelledby="history-title">
    <div class="panel-head"><h2 id="history-title">Historique</h2><span class="panel-meta"><?= count($bookings) ?> réservation<?= count($bookings) > 1 ? 's' : '' ?></span></div>
<?php if ($bookings === []): ?>
    <p class="empty">Aucune réservation pour ce garage.</p>
<?php else: ?>
    <table class="table">
      <thead><tr><th>Date</th><th class="desktop-only">Référence</th><th>Jantes</th><th>Statut</th></tr></thead>
      <tbody>
<?php foreach ($bookings as $b): ?>
        <tr><td><button type="button" class="row-link" data-booking="<?= $b['id'] ?>"><?= View::e(View::dateShort($b['slot_date'])) ?></button><?= View::photoBadge($b) ?><small class="cell-sub"><?= View::e(View::range($b)) ?></small></td>
          <td class="muted mono desktop-only"><?= View::e($b['reference']) ?></td><td><?= $b['rim_count'] ?></td><td><?= View::pill($b, $now) ?></td></tr>
<?php endforeach ?>
      </tbody>
    </table>
<?php endif ?>
  </section>

  <section class="panel" aria-labelledby="info-title">
    <div class="panel-head"><h2 id="info-title">Informations</h2><button type="button" class="panel-link link-btn" data-edit-garage><?= View::icon('edit', 'icon icon-sm') ?>Modifier</button></div>
    <dl class="info" data-garage-info>
      <div><dt>Contact</dt><dd><?= View::e($garage['contact_name'] ?: '—') ?></dd></div>
      <div><dt>Téléphone</dt><dd><?= $garage['phone'] !== '' ? '<a href="tel:' . View::e($garage['phone']) . '">' . View::e(View::phone($garage['phone'])) . '</a>' : '—' ?></dd></div>
      <div><dt>Email</dt><dd><?= $garage['email'] !== '' ? '<a href="mailto:' . View::e($garage['email']) . '">' . View::e($garage['email']) . '</a>' : '—' ?></dd></div>
      <div><dt>Adresse</dt><dd><?= View::e(trim($garage['street'] . ', ' . $garage['postal_code'] . ' ' . $garage['city'], ', ')) ?: '—' ?></dd></div>
      <div><dt>Notes</dt><dd class="pre"><?= View::e($garage['notes'] ?: '—') ?></dd></div>
    </dl>
    <form class="garage-form" data-garage-form="<?= (int) $garage['id'] ?>" hidden>
<?php foreach ([['name', 'Nom du garage', 120], ['contact_name', 'Contact', 120], ['phone', 'Téléphone', 40], ['email', 'Email', 254], ['street', 'Rue et numéro', 160], ['postal_code', 'Code postal', 4], ['city', 'Ville', 80]] as [$name, $label, $max]): ?>
      <div class="field"><label for="g-<?= $name ?>"><?= View::e($label) ?></label><input id="g-<?= $name ?>" name="<?= $name ?>" class="input" maxlength="<?= $max ?>" value="<?= View::e($name === 'phone' ? View::phone($garage[$name]) : $garage[$name]) ?>"><p class="field-error" data-error-for="<?= $name ?>" hidden></p></div>
<?php endforeach ?>
      <div class="field"><label for="g-notes">Notes internes</label><textarea id="g-notes" name="notes" class="input" rows="3" maxlength="2000"><?= View::e($garage['notes']) ?></textarea></div>
      <p class="form-error" role="alert" hidden></p>
      <div class="form-actions"><button type="button" class="btn btn-outline" data-cancel-garage>Annuler</button><button type="submit" class="btn btn-primary">Enregistrer</button></div>
    </form>

<?php if ($variants['contacts'] !== [] || $variants['addresses'] !== []): ?>
    <div class="variants">
      <h3>Autres coordonnées utilisées</h3>
<?php foreach ($variants['contacts'] as $c): ?>
      <p><?= View::icon('user', 'icon icon-sm') ?><?= View::e(trim($c['contact_name'] . ' · ' . View::phone($c['phone']) . ($c['email'] !== '' ? ' · ' . $c['email'] : ''), ' ·')) ?></p>
<?php endforeach ?>
<?php foreach ($variants['addresses'] as $a): ?>
      <p><?= View::icon('pin', 'icon icon-sm') ?><?= View::e("{$a['street']}, {$a['postal_code']} {$a['city']}") ?></p>
<?php endforeach ?>
    </div>
<?php endif ?>

    <div class="merge">
      <h3>Doublon ?</h3>
      <p class="muted">Si ce garage existe deux fois dans l'annuaire, rattachez l'autre fiche à celle-ci : ses réservations sont conservées.</p>
      <div class="search-field"><?= View::icon('search') ?><input type="search" class="input" placeholder="Rechercher la fiche en double…" data-merge-search="<?= (int) $garage['id'] ?>" autocomplete="off" aria-label="Rechercher la fiche en double"></div>
      <ul class="suggestions" data-merge-results hidden></ul>
    </div>

    <div class="merge erase">
      <h3>Effacer les données</h3>
      <p class="muted">À la demande du garage (RGPD) : son nom, ses contacts, ses adresses, ses commentaires et ses photos sont effacés. Les dates et nombres de jantes restent dans les statistiques. Définitif.</p>
      <button type="button" class="btn btn-outline btn-danger" data-erase-garage="<?= (int) $garage['id'] ?>" data-garage="<?= View::e($garage['name']) ?>"><?= View::icon('x', 'icon icon-sm') ?>Effacer les données de ce garage</button>
    </div>
  </section>
</div>
