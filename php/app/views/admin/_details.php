<?php
/**
 * Détails d'un enlèvement, affichés dans le panneau latéral.
 *
 * @var array<string, mixed> $b
 * @var list<array<string, mixed>> $notifications
 * @var list<array<string, mixed>> $history
 * @var array<string, string> $adminNames
 * @var list<array<string, mixed>> $photos
 * @var bool $calendarOn
 * @var int $now
 * @var string $today
 */

use Bwc\Admin\View;
use Bwc\Notifications;

$open = in_array($b['status'], ['pending', 'confirmed'], true);
$actions = [
    'created' => 'Réservation créée', 'updated' => 'Informations modifiées', 'rescheduled' => 'Créneau modifié',
    'confirmed' => 'Confirmé', 'completed' => 'Marqué enlevé', 'cancelled' => 'Annulé',
    'reopened' => 'Remis « à enlever »', 'note' => 'Note interne', 'erased' => 'Données effacées',
];
$who = static fn ($id): string => $id !== null && isset($adminNames[(int) $id]) ? ' par ' . $adminNames[(int) $id] : '';
?>
<article class="details" data-details="<?= $b['id'] ?>">
  <header class="details-head">
    <p class="details-ref"><span class="mono"><?= View::e($b['reference']) ?></span><?= View::pill($b, $now) ?></p>
    <h3><?= View::e($b['garage_name']) ?></h3>
    <p class="muted"><?= View::e($b['contact_name']) ?> · <?= View::e($b['city']) ?></p>
  </header>

  <div class="details-contact">
    <a class="contact-btn" href="tel:<?= View::e($b['phone']) ?>"><?= View::icon('phone') ?>Appeler</a>
<?php if ($b['email'] !== ''): ?>
    <a class="contact-btn" href="mailto:<?= View::e($b['email']) ?>?subject=<?= rawurlencode('Votre enlèvement ' . $b['reference']) ?>"><?= View::icon('mail') ?>Email</a>
<?php else: ?>
    <span class="contact-btn is-disabled" title="Pas d'adresse email"><?= View::icon('mail') ?>Email</span>
<?php endif ?>
    <a class="contact-btn" href="<?= View::e(View::mapsUrl($b)) ?>" target="_blank" rel="noopener"><?= View::icon('pin') ?>Itinéraire</a>
  </div>

  <div class="details-when">
    <p class="when-date"><?= View::e(View::dateLong($b['slot_date'])) ?></p>
    <p class="when-slot"><?= View::e(View::range($b)) ?> · <?= View::e(View::rims($b['rim_count'])) ?></p>
  </div>

<?php if ($photos !== []): ?>
  <section class="details-photos" aria-labelledby="photos-<?= $b['id'] ?>">
    <h4 id="photos-<?= $b['id'] ?>"><?= View::icon('camera', 'icon icon-sm') ?>Photos du garage (<?= count($photos) ?>)</h4>
    <div class="photo-grid" data-gallery>
<?php foreach ($photos as $i => $photo): $url = 'photo.php?id=' . (int) $photo['id']; ?>
<?php if ($photo['content_type'] === 'image/heic'): ?>
      <a class="photo-thumb photo-file" href="<?= $url ?>&amp;telecharger=1" title="Format HEIC (iPhone) : s'ouvre après téléchargement"><?= View::icon('camera') ?><span>HEIC</span><small>Télécharger</small></a>
<?php else: ?>
      <a class="photo-thumb" href="<?= $url ?>" target="_blank" rel="noopener" data-photo="<?= $url ?>" data-index="<?= $i ?>"><img src="<?= $url ?>" alt="Photo <?= $i + 1 ?> envoyée par <?= View::e($b['garage_name']) ?>" loading="lazy"></a>
<?php endif ?>
<?php endforeach ?>
    </div>
  </section>
<?php elseif ($b['photo_count'] > 0): ?>
  <p class="state-note state-info"><?= View::icon('camera', 'icon icon-sm') ?><?= $b['photo_count'] ?> photo<?= $b['photo_count'] > 1 ? 's' : '' ?> envoyée<?= $b['photo_count'] > 1 ? 's' : '' ?> par email à VBM avant l'affichage des photos dans l'administration.</p>
<?php endif ?>

  <dl class="info">
    <div><dt>Contact</dt><dd><?= View::e($b['contact_name']) ?></dd></div>
    <div><dt>Téléphone</dt><dd><a href="tel:<?= View::e($b['phone']) ?>"><?= View::e(View::phone($b['phone'])) ?></a></dd></div>
    <div><dt>Email</dt><dd><?= $b['email'] !== '' ? '<a href="mailto:' . View::e($b['email']) . '">' . View::e($b['email']) . '</a>' : '—' ?></dd></div>
    <div><dt>Adresse</dt><dd><a href="<?= View::e(View::mapsUrl($b)) ?>" target="_blank" rel="noopener"><?= View::e("{$b['street']}, {$b['postal_code']} {$b['city']}") ?></a></dd></div>
<?php if ($b['garage_reference'] !== ''): ?>
    <div><dt>Réf. du garage</dt><dd><?= View::e($b['garage_reference']) ?></dd></div>
<?php endif ?>
    <div><dt>Instructions</dt><dd class="pre"><?= View::e($b['comment'] !== '' ? $b['comment'] : '—') ?></dd></div>
    <div><dt>Reçue le</dt><dd><?= View::e(View::datetime($b['created_at'])) ?> · <?= $b['source'] === 'admin' ? 'saisie par VBM' . View::e($who($b['created_by'])) : 'site public' ?></dd></div>
<?php if ($b['garage_id'] !== null): ?>
    <div><dt>Garage</dt><dd><a href="garage.php?id=<?= (int) $b['garage_id'] ?>">Voir la fiche du garage</a></dd></div>
<?php endif ?>
  </dl>

<?php if ($open): ?>
  <div class="details-buttons">
<?php if ($b['status'] === 'confirmed'): ?>
    <button type="button" class="btn btn-primary btn-wide" data-action="complete" data-id="<?= $b['id'] ?>" data-garage="<?= View::e($b['garage_name']) ?>" data-rims="<?= $b['rim_count'] ?>" data-email="<?= $b['email'] !== '' ? '1' : '' ?>"><?= View::icon('check', 'icon icon-sm') ?>Marquer enlevé</button>
<?php else: ?>
    <button type="button" class="btn btn-primary btn-wide" data-action="confirm" data-id="<?= $b['id'] ?>" data-garage="<?= View::e($b['garage_name']) ?>" data-email="<?= $b['email'] !== '' ? '1' : '' ?>"><?= View::icon('check', 'icon icon-sm') ?>Confirmer l'enlèvement</button>
<?php endif ?>
    <div class="button-pair">
      <button type="button" class="btn btn-outline" data-edit="<?= $b['id'] ?>"><?= View::icon('edit', 'icon icon-sm') ?>Modifier</button>
      <button type="button" class="btn btn-outline btn-danger" data-action="cancel" data-id="<?= $b['id'] ?>" data-garage="<?= View::e($b['garage_name']) ?>" data-email="<?= $b['email'] !== '' ? '1' : '' ?>"><?= View::icon('x', 'icon icon-sm') ?>Annuler</button>
    </div>
  </div>
<?php elseif ($b['status'] === 'completed'): ?>
  <div class="state-block">
    <p class="state-note state-completed"><?= View::icon('check-circle', 'icon icon-sm') ?>Enlevé le <?= View::e(View::datetime($b['completed_at'])) ?><?= View::e($who($b['completed_by'])) ?>.</p>
    <button type="button" class="link-btn state-undo" data-action="reopen" data-id="<?= $b['id'] ?>" data-garage="<?= View::e($b['garage_name']) ?>">Marqué par erreur ? Remettre « à enlever »</button>
  </div>
<?php else: ?>
  <p class="state-note state-cancelled">Annulé le <?= View::e(View::datetime($b['cancelled_at'])) ?><?= View::e($who($b['cancelled_by'])) ?><?= $b['cancel_reason'] !== '' ? '. Motif : ' . View::e($b['cancel_reason']) : '' ?>.</p>
<?php endif ?>

  <section class="details-section">
    <h4>Note interne</h4>
    <form class="note-form" data-note="<?= $b['id'] ?>">
      <textarea name="note" class="input" rows="2" maxlength="1000" placeholder="Visible uniquement par l'équipe VBM : chauffeur, accès, remarque…" aria-label="Note interne"><?= View::e($b['internal_note']) ?></textarea>
      <div class="note-actions"><button type="submit" class="btn btn-outline btn-sm">Enregistrer la note</button></div>
    </form>
  </section>

  <section class="details-section">
    <h4>Emails</h4>
<?php if ($notifications === []): ?>
    <p class="muted">Aucun email pour cet enlèvement.</p>
<?php else: ?>
    <ul class="mail-list">
<?php foreach ($notifications as $n):
        $state = match ($n['status']) {
            'sent' => ['ok', 'Envoyé le ' . View::datetime($n['sent_at'])],
            'skipped' => ['muted', $n['recipients'] === '' ? 'Non envoyé : pas d’adresse email' : 'Remplacé par un message plus récent'],
            'failed' => ['error', 'Échec après ' . (int) $n['attempts'] . ' essais'],
            default => ['wait', (int) $n['attempts'] > 0 ? 'En attente · nouvel essai automatique' : 'En attente d’envoi'],
        }; ?>
      <li>
        <span class="mail-main"><strong><?= View::e(Notifications::LABELS[$n['kind']] ?? $n['kind']) ?></strong><small><?= View::e($n['recipients'] ?: '—') ?></small></span>
        <span class="mail-state mail-<?= $state[0] ?>"><?= View::e($state[1]) ?></span>
<?php if (in_array($n['status'], ['pending', 'failed'], true)): ?>
        <button type="button" class="link-btn" data-action="resend" data-id="<?= (int) $n['id'] ?>" data-booking-id="<?= $b['id'] ?>"><?= View::icon('refresh', 'icon icon-sm') ?>Renvoyer</button>
<?php endif ?>
<?php if ($n['last_error'] && $n['status'] !== 'sent' && $n['status'] !== 'skipped'): ?>
        <small class="mail-error"><?= View::e($n['last_error']) ?></small>
<?php endif ?>
      </li>
<?php endforeach ?>
    </ul>
<?php endif ?>
  </section>

  <section class="details-section">
    <h4>Google Agenda</h4>
    <p class="muted"><?= match (true) {
        !$calendarOn || $b['google_status'] === 'off' => 'Non connecté : l’enlèvement est enregistré dans la base du site.',
        $b['google_status'] === 'synced' => 'À jour' . ($b['google_link'] && in_array($b['status'], ['confirmed', 'completed'], true) ? ' · <a href="' . View::e($b['google_link']) . '" target="_blank" rel="noopener">Ouvrir dans Google Agenda</a>' : ''),
        $b['google_status'] === 'failed' => 'Échec de la copie après plusieurs essais : vérifiez l’accès à l’agenda (php app/tools/check.php).',
        default => 'Mise à jour en attente : nouvel essai automatique.',
    } ?></p>
  </section>

  <section class="details-section">
    <h4>Historique</h4>
    <ol class="history">
<?php foreach ($history as $h): ?>
      <li><span class="history-when"><?= View::e(View::datetime($h['created_at'])) ?></span><strong><?= View::e($actions[$h['action']] ?? $h['action']) ?></strong><?= $h['admin_name'] ? ' par ' . View::e($h['admin_name']) : '' ?><?= $h['details'] !== '' ? '<small>' . View::e($h['details']) . '</small>' : '' ?></li>
<?php endforeach ?>
    </ol>
  </section>
</article>
