<?php
/**
 * B2B Wheels Collect — page unique : présentation, formulaire de réservation
 * et fonctionnement. Le formulaire est animé par assets/js/app.js.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Bwc\App;
use Bwc\Http;
use Bwc\Phone;

$config = App::config();
$contact = $config->contact();
$legal = $config->legal();
$turnstile = $config->turnstile();
$phone = $contact['phone'] !== '' ? Phone::normalize($contact['phone']) : null;
$whatsapp = $contact['whatsapp'] !== '' ? Phone::normalize($contact['whatsapp']) : null;

$demoParts = array_values(array_filter([
    App::calendar() === null ? "l'agenda Google" : null,
    $config->mailTransport() === 'demo' ? 'les emails' : null,
]));
$demoBanner = '';
if ($config->demo() && $demoParts !== []) {
    $demoBanner = 'Mode démonstration : ' . implode(' et ', $demoParts) . (count($demoParts) > 1 ? ' sont simulés.' : ' est simulé.')
        . ($config->mailTransport() === 'demo' ? " Aucun email réel n'est envoyé." : '');
}

/** Échappement HTML. */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Valeur légale, ou mention « à compléter » tant qu'elle est vide. */
function toComplete(string $value, string $hint): string
{
    return $value !== '' ? e($value) : '<span class="to-complete">[' . e($hint) . ' — à compléter par VBM]</span>';
}

/** Ajoute la date de modification pour que les navigateurs rechargent les fichiers modifiés. */
function asset(string $path): string
{
    return e($path) . '?v=' . (@filemtime(__DIR__ . '/' . $path) ?: '1');
}

Http::securityHeaders($turnstile['site_key'] !== '');
header('Content-Type: text/html; charset=utf-8');

$steps = [
    ['Vous réservez', 'Choisissez une date, un créneau et le nombre de jantes. La confirmation arrive aussitôt par email, avec votre référence.'],
    ['Nous venons au garage', "Nous passons à l'adresse indiquée, pendant le créneau choisi. Les jantes sont emballées avec soin pour le trajet."],
    ['Examen et devis', "À l'atelier, chaque jante est examinée. Le devis repose sur ce constat, avec un délai confirmé : vous le validez avant le début des travaux."],
    ['Retour au garage', "Le travail terminé, vos jantes sont emballées avec soin puis livrées à l'adresse convenue."],
];
$maxRims = (int) $config->booking()['max_rims_per_booking'];

/** Champ texte avec libellé et emplacement d'erreur. */
function field(string $id, string $label, string $input, bool $optional = false): string
{
    return '<div class="field" data-field="' . $id . '">'
        . '<label for="' . $id . '" class="field-label"><span>' . e($label) . '</span>'
        . ($optional ? '<span class="field-optional">Facultatif</span>' : '') . '</label>'
        . $input
        . '<p class="field-error" id="' . $id . '-error" hidden></p>'
        . '</div>';
}
?>
<!doctype html>
<html lang="fr-BE">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>B2B Wheels Collect — Enlèvement professionnel de jantes</title>
  <meta name="description" content="Planifiez l'enlèvement de vos jantes directement dans votre garage, en quelques clics. Service réservé aux professionnels de l'automobile.">
  <meta name="application-name" content="B2B Wheels Collect">
  <meta name="theme-color" content="#f8f8f8">
  <meta name="color-scheme" content="light">
  <meta name="format-detection" content="telephone=no, email=no, address=no">
  <link rel="icon" href="favicon.svg" type="image/svg+xml">
  <link rel="preload" href="assets/fonts/mona-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
  <script src="<?= asset('assets/js/app.js') ?>" defer></script>
</head>
<body>
<?php if ($demoBanner !== ''): ?>
  <div class="demo-banner"><?= e($demoBanner) ?></div>
<?php endif ?>

  <header class="site-header">
    <div class="site-header-inner">
      <a href="./" aria-label="B2B Wheels Collect, accueil" class="logo">
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor">
          <circle cx="12" cy="12" r="10" stroke-width="2"/>
          <circle cx="12" cy="12" r="3.1" stroke-width="1.8"/>
          <path stroke-width="1.6" stroke-linecap="round" d="M12 8.9V2.6M15 11 21 9M13.8 14.5l3.8 5.2M10.2 14.5l-3.8 5.2M9 11 3 9"/>
        </svg>
        <span>B2B Wheels Collect</span>
      </a>
      <nav aria-label="Sections" class="site-nav">
        <a href="#fonctionnement" class="site-nav-link">Comment ça marche</a>
        <a href="#reservation" class="pill-button">Réserver</a>
      </nav>
    </div>
  </header>

  <main id="contenu">
    <div class="hero-wrap">
      <div class="hero">
        <p class="hero-eyebrow">Enlèvement professionnel de jantes</p>
        <h1 class="hero-title">Vos jantes.<br><span>Notre logistique.</span></h1>
        <p class="hero-subtitle">Planifiez l&apos;enlèvement de vos jantes directement dans votre garage, en quelques clics.</p>
      </div>

      <section id="reservation" class="booking" aria-label="Réservation d'un enlèvement">
        <div class="booking-card">
          <form id="booking-form" class="booking-form" novalidate data-max-rims="<?= $maxRims ?>" data-turnstile="<?= e($turnstile['site_key']) ?>">
            <div class="booking-fields">
              <div class="group">
                <h2 class="group-title">Le garage</h2>
                <?= field('garageName', 'Nom du garage', '<input id="garageName" name="garageName" type="text" autocomplete="organization" maxlength="120" class="field-input">') ?>
                <?= field('contactName', 'Nom et prénom', '<input id="contactName" name="contactName" type="text" autocomplete="name" autocapitalize="words" maxlength="120" class="field-input">') ?>
                <div class="grid-2">
                  <?= field('phone', 'Téléphone', '<input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="0470 12 34 56" maxlength="40" class="field-input">') ?>
                  <?= field('email', 'Email', '<input id="email" name="email" type="email" inputmode="email" autocomplete="email" autocapitalize="off" spellcheck="false" placeholder="atelier@garage.be" maxlength="254" class="field-input">') ?>
                </div>
              </div>

              <div class="group">
                <h2 class="group-title">Adresse d&apos;enlèvement</h2>
                <?= field('street', 'Rue et numéro', '<input id="street" name="street" type="text" autocomplete="address-line1" maxlength="160" class="field-input">') ?>
                <div class="grid-postal">
                  <?= field('postalCode', 'Code postal', '<input id="postalCode" name="postalCode" type="text" inputmode="numeric" autocomplete="postal-code" maxlength="4" class="field-input">') ?>
                  <?= field('city', 'Ville', '<input id="city" name="city" type="text" autocomplete="address-level2" maxlength="80" class="field-input">') ?>
                </div>
              </div>

              <div class="group">
                <h2 class="group-title">Les jantes</h2>
                <fieldset class="rim-count" data-field="rimCount">
                  <legend>Nombre de jantes à enlever</legend>
                  <div class="rim-choices">
<?php foreach (['1' => '1 jante', '2' => '2 jantes', '3' => '3 jantes', '4' => '4 jantes', 'other' => 'Autre quantité'] as $value => $aria): ?>
                    <label class="choice">
                      <input type="radio" name="rimChoice" value="<?= $value ?>" aria-label="<?= e($aria) ?>" class="sr-only">
                      <span aria-hidden="true" class="pill<?= $value === 'other' ? ' pill-small' : '' ?>"><?= $value === 'other' ? 'Autre' : $value ?></span>
                    </label>
<?php endforeach ?>
                  </div>
                  <div class="rim-other" hidden>
                    <label for="rimCount">Quantité exacte</label>
                    <input id="rimCount" name="rimCount" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="3" placeholder="1 à <?= $maxRims ?>" class="field-input">
                  </div>
                  <p class="field-error" id="rimCount-error" hidden></p>
                </fieldset>
                <?= field('garageReference', 'Votre référence', '<input id="garageReference" name="garageReference" type="text" autocomplete="off" maxlength="60" placeholder="N° de dossier, immatriculation…" class="field-input">', true) ?>
                <?= field('comment', 'Commentaire', '<textarea id="comment" name="comment" rows="3" maxlength="500" placeholder="Exemple : jantes avec pneus, accès par l\'arrière de l\'atelier…" class="field-input"></textarea>', true) ?>

                <div class="photos" data-field="photos">
                  <div class="field-label"><span id="photos-label">Photos des jantes</span><span class="field-optional">Facultatif</span></div>
                  <p class="photos-help">Une vue d&apos;ensemble et un gros plan des dégâts aident l&apos;atelier à préparer l&apos;intervention. 5 photos maximum.</p>
                  <ul class="photos-list" aria-labelledby="photos-label">
                    <li class="photos-add-item">
                      <label for="photos" class="photos-add">
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8.5h3l1.5-2.5h7L17 8.5h3a1 1 0 0 1 1 1V18a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.3" r="3.3"/></svg>
                        Ajouter
                        <input id="photos" type="file" accept="image/*,.heic,.heif" multiple class="sr-only">
                      </label>
                    </li>
                  </ul>
                  <p class="field-error" id="photos-error" hidden></p>
                </div>
              </div>

              <!-- Champ piège : invisible pour les personnes et les lecteurs d'écran. -->
              <div aria-hidden="true" class="honeypot">
                <label for="website">Site web</label>
                <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
              </div>
            </div>

            <div class="booking-side">
              <div class="booking-side-inner">
                <fieldset class="date-picker" data-field="date">
                  <legend class="group-title">Date d&apos;enlèvement</legend>
                  <div class="month-bar">
                    <p class="month-title" aria-live="polite"><span class="muted">Chargement…</span></p>
                    <div class="month-nav">
                      <button type="button" class="icon-button" data-month="-1" aria-label="Mois précédent" disabled>
                        <svg viewBox="0 0 20 20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5 6.5 10l5.5 5.5"/></svg>
                      </button>
                      <button type="button" class="icon-button" data-month="1" aria-label="Mois suivant" disabled>
                        <svg viewBox="0 0 20 20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 4.5l5.5 5.5L8 15.5"/></svg>
                      </button>
                    </div>
                  </div>
                  <div class="weekdays" aria-hidden="true">
                    <span title="lundi">L</span><span title="mardi">M</span><span title="mercredi">M</span><span title="jeudi">J</span><span title="vendredi">V</span><span title="samedi">S</span><span title="dimanche">D</span>
                  </div>
                  <div class="calendar"></div>
                  <p class="calendar-status" role="status" hidden></p>
                  <p class="field-error" id="date-error" hidden></p>
                </fieldset>

                <fieldset class="slot-picker" data-field="slot">
                  <legend class="group-title">Plage d&apos;enlèvement</legend>
                  <div class="slots"><p class="slots-empty">Choisissez d&apos;abord une date.</p></div>
                  <p class="field-error" id="slot-error" hidden></p>
                </fieldset>

                <div class="submit-area">
                  <p class="summary" aria-live="polite">Choisissez une date et un créneau pour confirmer.</p>
                  <div class="form-error" role="alert" hidden></div>
                  <div class="turnstile" hidden></div>
                  <button type="submit" class="submit-button">Confirmer mon enlèvement</button>
                  <p class="privacy-note">Vos coordonnées servent uniquement à organiser l&apos;enlèvement. <a href="#confidentialite">Confidentialité</a></p>
                </div>
              </div>
            </div>
          </form>
          <div class="confirmation" hidden></div>
        </div>
      </section>
    </div>

    <section id="fonctionnement" class="process" aria-labelledby="fonctionnement-title">
      <div class="process-inner">
        <div class="process-head">
          <h2 id="fonctionnement-title" class="section-title">Comment se passe un enlèvement.<br><span>Vous restez au garage.</span></h2>
          <p class="process-intro">Nous venons chercher les jantes à votre garage, à Bruxelles comme partout en Belgique, et vous les rapportons une fois le travail terminé. Hors de Belgique, sur demande.</p>
        </div>
        <figure class="process-photo">
          <img src="assets/img/vbm-camionnette-bruxelles.jpg"
               srcset="assets/img/vbm-camionnette-bruxelles-960.jpg 960w, assets/img/vbm-camionnette-bruxelles.jpg 1600w"
               sizes="(min-width: 1120px) 1072px, 100vw" width="1600" height="900" loading="lazy" decoding="async"
               alt="Camionnette VBM Wheels aux couleurs de l'atelier, sur la route à Bruxelles, l'Atomium en arrière-plan">
        </figure>
        <ol class="steps">
<?php foreach ($steps as $index => [$title, $text]): ?>
          <li>
            <span class="step-number"><?= sprintf('%02d', $index + 1) ?></span>
            <h3><?= e($title) ?></h3>
            <p><?= e($text) ?></p>
          </li>
<?php endforeach ?>
        </ol>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="site-footer-inner">
      <p>B2B Wheels Collect, le service d&apos;enlèvement de jantes de VBM Wheels Repair.</p>
      <nav aria-label="Informations">
<?php if ($phone !== null): ?>
        <a href="tel:<?= e($phone) ?>"><?= e(Phone::format($phone)) ?></a>
<?php endif ?>
<?php if ($contact['email'] !== ''): ?>
        <a href="mailto:<?= e($contact['email']) ?>"><?= e($contact['email']) ?></a>
<?php endif ?>
        <a href="#confidentialite">Confidentialité</a>
      </nav>
    </div>
  </footer>

<?php if ($whatsapp !== null): ?>
  <a href="https://wa.me/<?= e(substr($whatsapp, 1)) ?>?text=<?= rawurlencode("Bonjour, j'ai une question au sujet d'un enlèvement de jantes.") ?>"
     target="_blank" rel="noopener noreferrer" aria-label="Nous écrire sur WhatsApp" title="Nous écrire sur WhatsApp" class="whatsapp">
    <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M12.04 2.5a9.45 9.45 0 0 0-8.1 14.3L2.6 21.5l4.83-1.27A9.45 9.45 0 1 0 12.04 2.5Zm0 17.27a7.82 7.82 0 0 1-3.99-1.09l-.29-.17-2.87.75.77-2.8-.19-.29a7.83 7.83 0 1 1 6.57 3.6Zm4.29-5.86c-.24-.12-1.4-.69-1.61-.77-.22-.08-.38-.12-.53.12-.16.23-.61.77-.75.93-.14.16-.28.18-.51.06-.24-.12-1-.37-1.9-1.17a7.1 7.1 0 0 1-1.32-1.64c-.14-.24-.02-.37.1-.48.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.53-1.28-.73-1.75-.19-.46-.39-.4-.53-.4h-.45a.87.87 0 0 0-.63.29 2.65 2.65 0 0 0-.83 1.97 4.6 4.6 0 0 0 .97 2.45c.12.16 1.67 2.55 4.05 3.58.57.24 1.01.39 1.35.5.57.18 1.09.15 1.5.09.46-.07 1.4-.57 1.6-1.12.2-.55.2-1.02.14-1.12-.06-.1-.22-.16-.45-.28Z"/></svg>
  </a>
<?php endif ?>

  <dialog id="confidentialite" class="privacy" aria-labelledby="privacy-title">
    <div class="privacy-head">
      <h2 id="privacy-title">Confidentialité</h2>
      <button type="button" class="icon-button privacy-close" aria-label="Fermer">
        <svg viewBox="0 0 20 20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 5l10 10M15 5 5 15" stroke-linecap="round"/></svg>
      </button>
    </div>
    <div class="privacy-body">
      <section>
        <h3>Responsable du traitement</h3>
        <div>
          <p><?= toComplete($legal['entity'], 'Dénomination légale') ?>, <?= toComplete($legal['address'], 'adresse du siège') ?>, numéro d&apos;entreprise <?= toComplete($legal['vat'], 'BCE / TVA') ?>.</p>
          <p>Contact pour toute question relative à vos données&nbsp;: <?= toComplete($legal['privacy_email'], 'adresse email') ?>.</p>
        </div>
      </section>
      <section>
        <h3>Données collectées</h3>
        <div><p>Nom du garage, nom et prénom de la personne de contact, téléphone, adresse email, adresse d&apos;enlèvement, nombre de jantes, date et créneau choisis, ainsi que la référence, le commentaire et les photos éventuels. Aucune autre donnée n&apos;est demandée. Les photos sont transmises par email à l&apos;atelier et ne sont pas conservées sur le site.</p></div>
      </section>
      <section>
        <h3>Utilisation</h3>
        <div>
          <p>Ces données servent uniquement à planifier et réaliser l&apos;enlèvement de vos jantes, à vous envoyer la confirmation et à vous contacter au sujet de cet enlèvement. Elles ne sont ni vendues, ni utilisées à des fins publicitaires.</p>
          <p>Base légale&nbsp;: l&apos;exécution de mesures précontractuelles et du contrat de service (article 6.1.b du RGPD).</p>
        </div>
      </section>
      <section>
        <h3>Destinataires</h3>
        <div><p>Les données sont accessibles à l&apos;équipe de VBM Wheels Repair chargée des enlèvements. Elles sont enregistrées chez l&apos;hébergeur du site<?= App::calendar() !== null ? ', copiées dans l&apos;agenda de planification de Google' : '' ?><?= $config->mailTransport() === 'resend' ? ' et envoyées par Resend (service d&apos;envoi des emails)' : '' ?><?= $turnstile['site_key'] !== '' ? ', ainsi que vérifiées par Cloudflare pour la protection anti-spam du formulaire' : '' ?>. Lorsque des prestataires traitent des données hors de l&apos;Union européenne, c&apos;est dans le cadre des garanties prévues par le RGPD (clauses contractuelles types ou Data Privacy Framework).</p></div>
      </section>
      <section>
        <h3>Durée de conservation</h3>
        <div><p>Les données sont conservées <?= toComplete($legal['retention'], "durée, par exemple 24 mois après l'enlèvement") ?>, puis supprimées.</p></div>
      </section>
      <section>
        <h3>Vos droits</h3>
        <div><p>Vous pouvez demander l&apos;accès à vos données, leur rectification, leur effacement, la limitation du traitement ou vous y opposer, ainsi que leur portabilité, en écrivant à l&apos;adresse de contact ci-dessus. Vous pouvez aussi introduire une réclamation auprès de l&apos;Autorité de protection des données (autoriteprotectiondonnees.be).</p></div>
      </section>
      <section>
        <h3>Cookies</h3>
        <div><p>Ce site n&apos;utilise ni cookie publicitaire, ni outil de mesure d&apos;audience.<?= $turnstile['site_key'] !== '' ? ' La protection anti-spam peut utiliser des données techniques strictement nécessaires.' : '' ?></p></div>
      </section>
    </div>
  </dialog>
</body>
</html>
