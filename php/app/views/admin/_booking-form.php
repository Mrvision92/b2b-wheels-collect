<?php
/**
 * Formulaire « Nouvel enlèvement » et « Modifier », rempli par admin.js.
 * Mêmes champs et mêmes contrôles que le formulaire public.
 */

use Bwc\Admin\View;

$maxRims = (int) \Bwc\App::config()->booking()['max_rims_per_booking'];

/** Champ texte avec libellé et emplacement d'erreur. */
$field = static function (string $name, string $label, string $attrs = '', bool $optional = false): string {
    return '<div class="field"><label for="f-' . $name . '">' . View::e($label) . ($optional ? ' <span class="optional">Facultatif</span>' : '') . '</label>'
        . '<input id="f-' . $name . '" name="' . $name . '" class="input" ' . $attrs . '>'
        . '<p class="field-error" data-error-for="' . $name . '" hidden></p></div>';
};
?>
<form class="booking-form" novalidate>
  <input type="hidden" name="requestId">
  <input type="hidden" name="garageId">
  <input type="hidden" name="id">

  <section class="form-section" data-mode="create">
    <h3>Garage</h3>
    <div class="garage-picker">
      <label for="f-garage-search">Garage déjà client</label>
      <div class="search-field"><?= View::icon('search') ?><input id="f-garage-search" type="search" class="input" placeholder="Nom, ville, contact ou email…" autocomplete="off"></div>
      <ul class="suggestions" hidden></ul>
      <p class="picked" hidden><span></span><button type="button" class="link-btn" data-unpick>Autre garage</button></p>
      <p class="hint">Ou remplissez les coordonnées d'un nouveau garage ci-dessous.</p>
    </div>
  </section>

  <section class="form-section">
    <h3>Coordonnées</h3>
    <?= $field('garageName', 'Nom du garage', 'type="text" maxlength="120" autocomplete="off"') ?>
    <div class="field-row">
      <?= $field('contactName', 'Nom et prénom', 'type="text" maxlength="120" autocomplete="off"') ?>
      <?= $field('phone', 'Téléphone', 'type="tel" inputmode="tel" maxlength="40" placeholder="0470 12 34 56" autocomplete="off"') ?>
    </div>
    <?= $field('email', 'Email', 'type="email" inputmode="email" maxlength="254" placeholder="atelier@garage.be" autocomplete="off"', true) ?>
    <?= $field('street', 'Rue et numéro', 'type="text" maxlength="160" autocomplete="off"') ?>
    <div class="field-row field-row-postal">
      <?= $field('postalCode', 'Code postal', 'type="text" inputmode="numeric" maxlength="4" autocomplete="off"') ?>
      <?= $field('city', 'Ville', 'type="text" maxlength="80" autocomplete="off"') ?>
    </div>
    <label class="check" data-garage-update hidden><input type="checkbox" name="updateGarage" checked> Mettre à jour la fiche du garage avec ces coordonnées</label>
  </section>

  <section class="form-section">
    <h3>Enlèvement</h3>
    <div class="field-row">
      <?= $field('rimCount', 'Nombre de jantes', 'type="number" inputmode="numeric" min="1" max="' . $maxRims . '" step="1"') ?>
      <?= $field('date', 'Date', 'type="date"') ?>
    </div>
    <div class="field">
      <span class="label">Créneau</span>
      <div class="slot-choices" role="radiogroup" aria-label="Créneau"><p class="hint">Choisissez d'abord une date.</p></div>
      <p class="field-error" data-error-for="slot" hidden></p>
    </div>
    <?= $field('garageReference', 'Référence du garage', 'type="text" maxlength="60" placeholder="N° de dossier, immatriculation…" autocomplete="off"', true) ?>
    <div class="field">
      <label for="f-comment">Instructions <span class="optional">Facultatif</span></label>
      <textarea id="f-comment" name="comment" class="input" rows="3" maxlength="500" placeholder="Jantes avec pneus, accès par l'arrière…"></textarea>
      <p class="field-error" data-error-for="comment" hidden></p>
    </div>
    <div class="field" data-mode="create">
      <label for="f-status">Statut</label>
      <select id="f-status" name="status" class="input">
        <option value="confirmed">Confirmé — à enlever</option>
        <option value="pending">En attente de confirmation</option>
      </select>
    </div>
    <label class="check" data-mode="create"><input type="checkbox" name="notifyGarage" checked> Envoyer la confirmation au garage par email</label>
    <label class="check" data-mode="edit"><input type="checkbox" name="notifyChange" checked> Prévenir le garage par email si le créneau change</label>
  </section>

  <p class="form-error" role="alert" hidden></p>
  <div class="form-actions">
    <button type="button" class="btn btn-outline" data-close>Annuler</button>
    <button type="submit" class="btn btn-primary" data-submit>Enregistrer l'enlèvement</button>
  </div>
</form>
