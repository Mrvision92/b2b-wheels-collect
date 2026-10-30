# B2B Wheels Collect

Site one-page de réservation d'enlèvements de jantes pour les garages, opéré par VBM Wheels Repair.

Le garage remplit un formulaire, choisit une date et un créneau, et confirme. Ensuite, automatiquement :

1. l'enlèvement est ajouté à l'agenda Google « B2B Wheels Collect — Enlèvements » de VBM ;
2. le garage reçoit un email de confirmation ;
3. VBM reçoit un email avec toutes les informations et les photos jointes par le garage (itinéraire Google Maps, appel, email).

Il n'y a ni compte, ni mot de passe, ni tableau de bord : VBM organise les enlèvements directement dans Google Agenda.

---

## Sommaire

1. [Lancer le projet en local](#1-lancer-le-projet-en-local)
2. [Mode démonstration](#2-mode-démonstration)
3. [Connecter Google Agenda](#3-connecter-google-agenda)
4. [Connecter l'envoi d'emails (Resend)](#4-connecter-lenvoi-demails-resend)
5. [Régler les jours, horaires et créneaux](#5-régler-les-jours-horaires-et-créneaux)
6. [Utilisation au quotidien par VBM](#6-utilisation-au-quotidien-par-vbm)
7. [Mettre en ligne (Hostinger)](#7-mettre-en-ligne-hostinger)
8. [Fiabilité : ce qui est garanti](#8-fiabilité--ce-qui-est-garanti)
9. [Sécurité et RGPD](#9-sécurité-et-rgpd)
10. [Tests](#10-tests)
11. [Structure du code](#11-structure-du-code)

---

## 1. Lancer le projet en local

Prérequis : Node.js 20.12 ou plus récent (testé avec Node 24).

```bash
npm install
cp .env.example .env.local
npm run dev
```

Le site est disponible sur http://localhost:3000. Avec `.env.local` tel quel (`DEMO_MODE=true`), tout fonctionne sans aucun compte externe.

Commandes utiles :

| Commande | Rôle |
| --- | --- |
| `npm run dev` | serveur de développement |
| `npm run build` puis `npm start` | version de production en local |
| `npm test` | tests unitaires et d'intégration |
| `npm run test:e2e` | tests navigateur (ordinateur + mobile) |
| `npm run typecheck` / `npm run lint` | vérifications TypeScript et ESLint |
| `npm run check:services` | vérifie la connexion réelle à Google Agenda et Resend |
| `npm run google:connect` | connexion Google par OAuth (option B ci-dessous) |

## 2. Mode démonstration

Tant que Google Agenda ou Resend ne sont pas configurés (ou si `DEMO_MODE=true`), le service concerné est **simulé** et le site l'indique clairement :

- un bandeau en haut de page précise ce qui est simulé ;
- l'agenda est un fichier local `.data/demo-calendar.json` (mêmes règles que Google : capacité, blocages, doublons) ;
- les emails ne partent pas : ils sont enregistrés dans `.data/demo-emails/` et consultables depuis l'écran de confirmation (« Voir l'email du garage », « Voir la notification VBM ») ;
- l'écran de confirmation n'affirme jamais qu'un email a été envoyé.

Pour repartir de zéro : supprimez le dossier `.data/`.

Chaque service bascule en réel dès que ses variables sont renseignées et que `DEMO_MODE=false`. On peut donc connecter Google d'abord, puis les emails.

## 3. Connecter Google Agenda

L'agenda Google de VBM sert d'outil de planification **et** d'enregistrement des réservations. Chaque enlèvement y devient un événement :

- titre : `ENLÈVEMENT — Garage Dupont — 4 jantes` ;
- lieu : l'adresse d'enlèvement (cliquable vers Maps) ;
- description : référence, contact, téléphone, email, nombre de jantes, commentaire, lien d'itinéraire ;
- horaires en heure de Bruxelles (changements d'heure gérés).

Préparation commune (une seule fois) :

1. Dans Google Agenda (compte professionnel de VBM), créez un agenda nommé **B2B Wheels Collect — Enlèvements**, fuseau horaire Bruxelles. *(Inutile avec l'option B, le script le crée.)*
2. Sur https://console.cloud.google.com, créez un projet (par exemple « B2B Wheels Collect ») puis activez **Google Calendar API** (API et services → Bibliothèque).

### Option A — compte de service (recommandée)

Aucun jeton n'expire, et le compte n'a accès qu'à l'agenda partagé avec lui.

1. Console Google Cloud → IAM et administration → **Comptes de service** → Créer. Aucun rôle n'est nécessaire.
2. Ouvrez le compte de service → **Clés** → Ajouter une clé → JSON. Un fichier est téléchargé : gardez-le secret.
3. Dans Google Agenda → paramètres de l'agenda « B2B Wheels Collect — Enlèvements » → **Partager avec des personnes** : ajoutez l'adresse du compte de service (`…@….iam.gserviceaccount.com`) avec le droit **« Apporter des modifications aux événements »**.
4. Dans les paramètres de l'agenda → **Intégrer l'agenda** : copiez l'**ID de l'agenda**.
5. Renseignez :

```bash
GOOGLE_CALENDAR_ID=xxxxxxxx@group.calendar.google.com
GOOGLE_SERVICE_ACCOUNT_EMAIL=b2b-wheels-collect@votre-projet.iam.gserviceaccount.com
GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nMIIE…\n-----END PRIVATE KEY-----\n"
```

La clé privée est le champ `private_key` du fichier JSON, recopié tel quel (avec les `\n`). Supprimez ensuite le fichier JSON de votre ordinateur.

Permission demandée : `calendar.events` uniquement. Le compte ne voit aucun autre agenda de VBM.

> Avec Google Workspace, la création de clés peut être bloquée par la règle d'organisation « iam.disableServiceAccountKeyCreation ». Dans ce cas, utilisez l'option B.

### Option B — OAuth avec le compte Google de VBM

1. Console Google Cloud → **Écran de consentement OAuth** : type « Interne » (Google Workspace) ou « Externe ». En « Externe », passez l'application en **production** : en mode « test », Google invalide l'accès au bout de 7 jours.
2. **Identifiants** → Créer des identifiants → ID client OAuth → type **Application de bureau**.
3. Copiez l'ID client et le secret dans `.env.local` (`GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET`).
4. Lancez `npm run google:connect`, ouvrez le lien affiché, connectez-vous avec le compte de VBM et acceptez.
5. Le script crée l'agenda « B2B Wheels Collect — Enlèvements » et affiche `GOOGLE_CALENDAR_ID` et `GOOGLE_OAUTH_REFRESH_TOKEN` à recopier.

Permission demandée : `calendar.app.created`, c'est-à-dire uniquement les agendas créés par B2B Wheels Collect.

### Vérifier

```bash
npm run check:services -- --write
```

Le script lit l'agenda, crée un événement de test dans plus d'un an, le modifie puis le supprime.

## 4. Connecter l'envoi d'emails (Resend)

1. Créez un compte sur https://resend.com.
2. **Domains** → Add domain : utilisez le futur domaine du site (par exemple `b2bwheelscollect.be`) ou un sous-domaine (`mail.b2bwheelscollect.be`).
3. Ajoutez dans la zone DNS du domaine (chez Hostinger : Domaines → DNS / Nameservers) les enregistrements affichés par Resend : DKIM, SPF et MX sur le sous-domaine `send`. Ils ne touchent pas aux boîtes mail existantes. Attendez la mention **Verified**.
4. Recommandé : ajoutez un enregistrement DMARC, par exemple `_dmarc` → `v=DMARC1; p=none; rua=mailto:dmarc@votre-domaine.be`.
5. **API Keys** → Create API key avec la permission « Sending access », restreinte à ce domaine.
6. Renseignez :

```bash
RESEND_API_KEY=re_…
EMAIL_FROM="B2B Wheels Collect <enlevements@b2bwheelscollect.be>"
VBM_NOTIFICATION_EMAIL=planning@vbm-exemple.be
EMAIL_REPLY_TO=planning@vbm-exemple.be
```

- `VBM_NOTIFICATION_EMAIL` peut contenir plusieurs adresses séparées par des virgules.
- Quand un garage répond à sa confirmation, la réponse arrive à `EMAIL_REPLY_TO` (par défaut la première adresse VBM). C'est ainsi que les garages demandent une modification ou une annulation.

Vérifier : `npm run check:services -- --email` envoie un email de test aux adresses VBM.

## 5. Régler les jours, horaires et créneaux

Tout se trouve dans **`src/config/booking.ts`** (commenté en français) :

| Réglage | Par défaut | Effet |
| --- | --- | --- |
| `mode` | `"ranges"` | `"ranges"` : plages « 9 h – 11 h » ; `"times"` : heures précises « 9 h 30 » |
| `weeklySchedule` | lun.–ven. 9 h–17 h | plages ouvertes par jour (1 = lundi … 7 = dimanche) ; pause de midi = deux plages |
| `slotMinutes` | `120` | durée d'un créneau et de l'événement Google |
| `slotStepMinutes` | = durée | écart entre deux débuts (ex. 30 pour proposer 9 h, 9 h 30…) |
| `capacityPerSlot` | `2` | nombre maximum d'enlèvements sur un même créneau |
| `minNoticeHours` | `12` | délai minimum avant le début du créneau |
| `maxAdvanceDays` | `60` | jusqu'à combien de jours à l'avance on peut réserver |
| `closeOnPublicHolidays` | `true` | ferme les 10 jours fériés légaux belges |
| `closedDates` | `[]` | fermetures exceptionnelles, ex. `["2026-12-24", "2026-12-31"]` |
| `maxRimsPerBooking` | `40` | quantité maximale dans « Autre » |

Exemple avec pause de midi et samedi matin :

```ts
weeklySchedule: {
  1: [{ from: "08:30", to: "12:30" }, { from: "13:30", to: "17:30" }],
  // …
  6: [{ from: "09:00", to: "12:00" }],
},
```

Après une modification : redéployez (un `git push` suffit si l'hébergeur est relié au dépôt).

**Coordonnées** (bouton WhatsApp, pied de page, emails) : `src/config/site.ts` contient le numéro WhatsApp, la ligne directe et l'email. Ces valeurs viennent du site VBM et sont à confirmer par VBM. Les variables `NEXT_PUBLIC_CONTACT_PHONE`, `NEXT_PUBLIC_CONTACT_EMAIL` et `NEXT_PUBLIC_CONTACT_WHATSAPP` les remplacent si besoin.

**Image** : la photo de la camionnette est dans `src/assets/images/`. Pour la changer, remplacez le fichier en gardant le même nom : les tailles adaptées à chaque écran sont générées automatiquement.

**Photos jointes par les garages** : jusqu'à 5 photos par réservation, réduites dans le navigateur (1 600 px maximum) puis jointes à l'email envoyé à VBM. Elles ne sont pas conservées sur le site. Le serveur vérifie le contenu réel de chaque fichier (JPEG, PNG, WebP ou HEIC) et le poids total (4 Mo).

## 6. Utilisation au quotidien par VBM

Tout se fait dans Google Agenda, sans redéploiement :

| Besoin | Action dans l'agenda « B2B Wheels Collect — Enlèvements » |
| --- | --- |
| Voir les enlèvements | ouvrir l'agenda (vue jour, semaine ou mois) |
| Annuler un enlèvement | supprimer l'événement : la place se libère immédiatement sur le site |
| Déplacer un enlèvement | déplacer l'événement : la capacité suit le nouvel horaire |
| Bloquer une journée | créer un événement « Journée entière » (n'importe quel titre) |
| Bloquer un créneau | créer un événement dont le titre contient **FERMÉ**, **BLOQUÉ**, **INDISPONIBLE**, **COMPLET** ou **CONGÉ** |
| Ajouter un enlèvement pris par téléphone | créer un événement normal sur le créneau : il occupe une place |
| Note sans effet sur la capacité | créer l'événement en le marquant « Disponible » |

Le garage n'est pas prévenu automatiquement d'un déplacement ou d'une annulation faits dans Google Agenda : contactez-le (son téléphone et son email sont dans l'événement).

## 7. Mettre en ligne (Hostinger)

Le site s'héberge sur l'hébergement **Node.js géré** de Hostinger (« Web Apps » dans hPanel), qui fait tourner Next.js avec sa partie serveur (réservations, emails, Google Agenda). Il faut une offre qui inclut les applications Node.js (Business ou Cloud) : un hébergement mutualisé classique en `public_html` ne suffit pas.

1. **Créer l'application** : hPanel → Sites web → Ajouter un site → application Node.js → importer depuis GitHub le dépôt `Mrvision92/b2b-wheels-collect`, branche `feature/rimcollect-v1` (ou `main` après fusion). Chaque `git push` sur cette branche redéploie automatiquement.
2. **Réglages de build** (Next.js est détecté tout seul) :

   | Champ | Valeur |
   | --- | --- |
   | Version de Node.js | 22 ou 24 |
   | Type d'application | `next` |
   | Script de build | `build` |
   | Dossier de sortie | `.next` |

   Ne modifiez pas `next.config.ts` : Hostinger y ajoute lui-même la sortie `standalone` qu'il démarre.
3. **Variables d'environnement**, avant le premier build : partez de `.env.example`, remplissez les valeurs et utilisez **Import .env** (ou ajoutez-les une à une). En production : `DEMO_MODE=false`, `APP_URL=https://votre-domaine.be`, un `APP_SECRET` long (`openssl rand -base64 48`), un `CRON_SECRET`, puis les valeurs Google, Resend, VBM et légales. La clé privée Google reste sur une seule ligne avec ses `\n`. Enregistrer redéploie le site.
4. **Domaine** : le domaine étant déjà chez Hostinger, rattachez-le à l'application dans hPanel et vérifiez que le certificat SSL (HTTPS) est actif.
5. **Renvoi automatique des emails en échec** : hPanel → Cron Jobs → toutes les 15 minutes (`*/15 * * * *`), commande :

   ```bash
   curl -fsS -H "Authorization: Bearer VOTRE_CRON_SECRET" https://votre-domaine.be/api/cron/notifications > /dev/null
   ```

   Un renvoi est aussi tenté après chaque nouvelle réservation.
6. **Vérifier** : `https://votre-domaine.be/api/health` doit indiquer `"calendar":"google"` et `"email":"resend"`. Puis, en local avec les mêmes valeurs dans `.env.local` : `npm run check:services -- --write --email`.

À savoir :

- Hostinger met l'application en veille après une période sans visite ; la première visite suivante prend quelques secondes, c'est normal.
- Le site tourne sur un seul processus : le verrou en mémoire protège pleinement contre les réservations simultanées, Upstash est inutile.
- La page est générée au moment du build : après avoir modifié une variable affichée sur le site (coordonnées, mentions légales, bandeau de démonstration), enregistrez pour redéployer.
- `vercel.json` et `.vercelignore` ne servent qu'à Vercel et sont ignorés par Hostinger.

### Alternative : Vercel

La démonstration actuelle tourne sur Vercel (https://b2b-wheels-collect.vercel.app). Pour y passer en production : importez le dépôt sur https://vercel.com, saisissez les mêmes variables dans **Settings → Environment Variables**, ajoutez le domaine dans **Settings → Domains**, et activez **Upstash Redis** (Marketplace, offre gratuite) pour partager le verrou entre les instances. `vercel.json` déclare la tâche planifiée de renvoi des emails (quotidienne en offre Hobby, `*/15 * * * *` possible en offre Pro).

Autre serveur : n'importe quel serveur Node.js (`npm run build && npm start`) derrière HTTPS convient, avec une tâche planifiée qui appelle l'adresse ci-dessus.

Option anti-spam : créez un widget Cloudflare Turnstile (mode « Managed ») et renseignez `NEXT_PUBLIC_TURNSTILE_SITE_KEY` et `TURNSTILE_SECRET_KEY`. Sans ces variables, la protection repose sur un champ piège invisible et une limite de tentatives par connexion.

## 8. Fiabilité : ce qui est garanti

- **Confirmation uniquement après enregistrement.** L'écran « Votre enlèvement est confirmé » ne s'affiche que lorsque l'événement existe dans l'agenda. Si Google ne répond pas, le garage voit : « Votre enlèvement n'a pas pu être confirmé… », et rien n'est envoyé.
- **Pas de doublon en cas de double clic ou de connexion perdue.** Chaque formulaire porte un identifiant unique ; le même envoi aboutit toujours au même événement. Une même adresse email sur le même créneau et à la même adresse est reconnue comme la réservation existante.
- **Pas de surréservation.** Les réservations d'une même journée passent l'une après l'autre (verrou), puis chaque nouvel événement est relu : s'il dépasse la capacité à cause d'une course entre serveurs, il est retiré et le garage est invité à choisir un autre créneau.
- **Un email en échec ne fait pas perdre la réservation.** L'envoi est retenté trois fois immédiatement, puis automatiquement plus tard (statut mémorisé dans l'événement Google). Resend reçoit une clé d'idempotence : un renvoi ne produit jamais deux emails identiques. Si la confirmation n'a pas pu partir, l'écran le dit et l'email à VBM le signale.
- **Disponibilités jamais devinées.** Le calendrier du site affiche uniquement « libre » ou « complet », sans aucune information sur les autres garages.

Limite connue : si Google Agenda est en panne, aucune réservation n'est possible pendant la panne (le site l'indique). C'est volontaire : sans agenda, on ne peut pas garantir la disponibilité.

## 9. Sécurité et RGPD

- Toutes les clés restent côté serveur ; aucune n'est envoyée au navigateur. `.env*` est exclu de Git.
- Validation stricte côté serveur (Zod) : longueurs, formats, caractères de contrôle, champs inconnus ignorés.
- Requêtes d'un autre site refusées, taille des requêtes limitée, nombre de tentatives limité par connexion.
- En-têtes de sécurité : Content-Security-Policy, interdiction d'intégration en iframe, HSTS en production.
- Journaux techniques sans données personnelles (références et codes d'erreur uniquement).
- Aucun cookie, aucun traceur publicitaire ni outil de statistiques.
- Politique de confidentialité intégrée à la page (lien « Confidentialité »). Les champs légaux s'affichent « à compléter » tant que les variables `LEGAL_*` ne sont pas renseignées : dénomination, adresse, numéro d'entreprise, email de contact, durée de conservation.
- Conservation : les données vivent dans l'agenda Google de VBM. Supprimez ou anonymisez les événements passés selon la durée annoncée (par exemple 24 mois).

## 10. Tests

```bash
npm test          # 115 tests : règles de créneaux, fuseau horaire, validation, réservation, photos, emails, API
npm run test:e2e  # 18 tests navigateur sur version de production (ordinateur + mobile)
```

Les scénarios couverts incluent : réservation valide, champs manquants ou invalides, date passée, jour fermé ou férié, créneau complet ou bloqué, garages simultanés sur la dernière place (avec et sans verrou partagé), double clic, renvoi après perte de connexion, panne Google (aucune confirmation, aucun email), panne d'email (réservation conservée, renvoi sans doublon), échappement HTML, absence de données personnelles dans les disponibilités, protection du point d'accès cron, affichage mobile sans défilement horizontal.

Les tests utilisent l'agenda de démonstration et des faux services : ils ne remplacent pas `npm run check:services` avec les vrais identifiants.

## 11. Structure du code

```
src/
  config/booking.ts            jours, horaires, créneaux, capacité
  config/site.ts               coordonnées de contact (WhatsApp, téléphone, email)
  app/page.tsx                 la page unique
  app/api/availability         disponibilités d'un mois (aucune donnée personnelle)
  app/api/bookings             création d'une réservation
  app/api/cron/notifications   renvoi des emails en échec (protégé par CRON_SECRET)
  app/api/health               services actifs (sans secret)
  app/demo/emails/[id]         aperçu des emails simulés (mode démonstration uniquement)
  components/booking/          formulaire, calendrier, créneaux, confirmation
  components/sections/         section « Comment se passe un enlèvement »
  assets/images/               photo de la camionnette
  lib/booking/                 logique de réservation et notifications
  lib/calendar/                client Google Agenda + agenda de démonstration
  lib/email/                   modèles d'emails + envoi Resend
  lib/schedule.ts, time.ts     génération des créneaux, heure de Bruxelles
scripts/                       connexion Google et vérification des services
tests/                         Vitest (unit, intégration) et Playwright (e2e)
```
