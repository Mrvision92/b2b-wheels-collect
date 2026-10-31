# B2B Wheels Collect — version PHP / MySQL

Le même site que la version Next.js (même page, même formulaire, mêmes emails), écrit en **HTML, CSS, JavaScript, PHP et MySQL**, pour un hébergement web classique comme Hostinger.

- PHP 8.1 ou plus récent, **sans Composer ni dépendance** : on dépose les fichiers, c'est tout.
- MySQL 5.7+, MySQL 8 ou MariaDB 10.3+.
- JavaScript sans framework ni étape de compilation.

Le garage remplit le formulaire, choisit une date et un créneau, puis confirme. Ensuite, automatiquement :

1. la réservation est enregistrée dans MySQL (table `bwc_bookings`) ;
2. elle est ajoutée à l'agenda Google de VBM (facultatif) ;
3. le garage reçoit un email de confirmation ;
4. VBM reçoit un email avec toutes les informations, et les photos du garage en pièces jointes.

Il n'y a ni compte, ni mot de passe pour les garages, ni tableau de bord.

---

## Sommaire

1. [Contenu du dossier](#1-contenu-du-dossier)
2. [Mise en ligne sur Hostinger](#2-mise-en-ligne-sur-hostinger)
3. [Emails](#3-emails)
4. [Google Agenda (facultatif)](#4-google-agenda-facultatif)
5. [Régler les jours, horaires et créneaux](#5-régler-les-jours-horaires-et-créneaux)
6. [Utilisation au quotidien par VBM](#6-utilisation-au-quotidien-par-vbm)
7. [Fiabilité et sécurité](#7-fiabilité-et-sécurité)
8. [Essayer en local et tests](#8-essayer-en-local-et-tests)

---

## 1. Contenu du dossier

```text
php/
├── public_html/              → à déposer dans public_html (seule partie visible sur Internet)
│   ├── index.php             la page
│   ├── api/availability.php  créneaux libres d'un mois (JSON)
│   ├── api/bookings.php      enregistrement d'une réservation (JSON)
│   ├── api/health.php        état des services, sans donnée sensible
│   ├── demo/email.php        aperçu des emails en mode démonstration
│   ├── assets/               CSS, JavaScript, police Mona Sans, photo de la camionnette
│   ├── _bootstrap.php        emplacement du dossier app/ (à adapter seulement s'il change)
│   ├── .htaccess             HTTPS, en-têtes de sécurité, cache
│   └── .user.ini             taille maximale des envois (photos)
├── app/                      → à déposer à côté de public_html, jamais dedans
│   ├── config.example.php    modèle de configuration (accès MySQL, emails, Google)
│   ├── settings.php          jours, horaires, créneaux, coordonnées, mentions légales
│   ├── src/                  le code PHP (classes Bwc\…)
│   ├── cron/notifications.php  tâche planifiée : renvoi des emails en attente
│   ├── tools/check.php       vérification de la configuration (SSH)
│   └── storage/              journaux, cache, emails de démonstration (créé tout seul)
├── database/schema.sql       tables MySQL, à importer une fois
└── tests/                    tests automatiques
```

## 2. Mise en ligne sur Hostinger

Un hébergement web Hostinger (Premium, Business ou Cloud) suffit : PHP et MySQL sont inclus.

1. **Version de PHP** : hPanel → Avancé → Configuration PHP → PHP 8.3 (8.1 minimum). Les extensions nécessaires (`pdo_mysql`, `curl`, `openssl`, `mbstring`) sont actives par défaut.
2. **Base de données** : hPanel → Bases de données → Bases de données MySQL → créer une base et son utilisateur. Puis phpMyAdmin → sélectionner la base → onglet **Importer** → `database/schema.sql`.
3. **Fichiers**, avec le gestionnaire de fichiers ou en FTP, dans `domains/votre-domaine.be/` :
   - le **contenu** de `public_html/` va dans `public_html/` (y compris les fichiers cachés `.htaccess` et `.user.ini`) ;
   - le dossier `app/` va **à côté** de `public_html/`, pas dedans.

   ```text
   domains/votre-domaine.be/
   ├── app/
   └── public_html/
   ```

   Si `app/` doit être placé ailleurs, modifiez le chemin dans `public_html/_bootstrap.php`.
4. **Configuration** : copiez `app/config.example.php` en `app/config.php` et remplissez-le : adresse du site, clé secrète, accès MySQL, emails, Google. Pour la clé secrète, lancez une fois en SSH `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`, puis ne la changez plus.
5. **HTTPS** : vérifiez que le certificat SSL est actif (hPanel → Sécurité → SSL). Le fichier `.htaccess` redirige déjà vers `https://`.
6. **Tâche planifiée** : hPanel → Avancé → Tâches Cron → toutes les 15 minutes (`*/15 * * * *`), commande :

   ```bash
   /usr/bin/php /home/u123456789/domains/votre-domaine.be/app/cron/notifications.php
   ```

   Remplacez `u123456789` par votre identifiant (visible dans le gestionnaire de fichiers). Cette tâche renvoie les emails et les copies Google Agenda qui auraient échoué.
7. **Vérification** en SSH (hPanel → Avancé → Accès SSH) :

   ```bash
   cd domains/votre-domaine.be
   php app/tools/check.php --send-test=votre@email.be
   ```

   Tout doit être « OK ». On peut aussi ouvrir `https://votre-domaine.be/api/health.php`.
8. **Production** : dans `app/config.php`, passez `'demo' => false`. Le bandeau « Mode démonstration » disparaît et les vrais emails partent.

## 3. Emails

Deux possibilités, au choix dans `app/config.php` (section `mail`).

**Boîte email Hostinger (SMTP)** : c'est le plus simple quand le domaine est chez Hostinger.

1. hPanel → Emails → créer une adresse, par exemple `reservations@votre-domaine.be`.
2. Dans `config.php` : `'transport' => 'smtp'`, `'from' => 'B2B Wheels Collect <reservations@votre-domaine.be>'`, puis dans `smtp` : `smtp.hostinger.com`, port `465`, `'encryption' => 'ssl'`, l'adresse et son mot de passe.
3. `vbm_recipients` : la ou les adresses de VBM qui reçoivent chaque nouvel enlèvement.

**Resend** (https://resend.com) : `'transport' => 'resend'` et la clé API. Il faut d'abord vérifier le domaine chez Resend, en ajoutant leurs enregistrements DNS dans hPanel.

Dans les deux cas, le garage reçoit sa confirmation tout de suite. L'email à VBM part juste après la réponse au garage, avec les photos. Si un envoi échoue, la réservation est gardée : la tâche planifiée renvoie l'email (12 essais au maximum).

## 4. Google Agenda (facultatif)

Sans Google, les réservations sont dans MySQL et dans les emails : le site fonctionne déjà. Avec Google, chaque enlèvement apparaît aussi dans l'agenda de VBM. Le plus simple est un **compte de service**, sans écran de consentement ni expiration :

1. https://console.cloud.google.com → créer un projet → **API et services** → activer **Google Calendar API**.
2. **IAM et administration → Comptes de service** → créer un compte, puis onglet **Clés** → Ajouter une clé → JSON. Un fichier `.json` se télécharge.
3. Déposez ce fichier dans `app/storage/` sur l'hébergement, jamais dans `public_html`. Dans `config.php`, indiquez son chemin complet dans `service_account_file`, par exemple `/home/u123456789/domains/votre-domaine.be/app/storage/google.json`.
4. Dans Google Agenda (compte de VBM), créez un agenda dédié, par exemple « B2B Wheels Collect — Enlèvements ». Puis Paramètres de l'agenda → **Partager avec des personnes en particulier** → ajoutez l'adresse du compte de service (`…@….iam.gserviceaccount.com`) avec le droit **Apporter des modifications aux événements**.
5. Même page, section **Intégrer l'agenda** : copiez l'**ID de l'agenda** dans `calendar_id`.
6. Vérifiez avec `php app/tools/check.php`.

Vous n'avez besoin d'aucun mot de passe du compte Google de VBM : il suffit que VBM partage l'agenda avec l'adresse du compte de service. OAuth, avec un jeton d'actualisation, est aussi accepté (champs `oauth_*`), mais il est plus long à mettre en place.

## 5. Régler les jours, horaires et créneaux

Tout est dans `app/settings.php`, commenté en français :

- les jours et plages d'ouverture (par défaut du lundi au vendredi, de 9 h à 17 h) ;
- la durée des créneaux (2 h) et le nombre d'enlèvements par créneau (2) ;
- le délai minimum avant un enlèvement (12 h) et la réservation à l'avance (60 jours) ;
- les jours fériés belges (fermés automatiquement) et les fermetures exceptionnelles ;
- le nombre maximum de jantes (40) ;
- les coordonnées affichées (WhatsApp, téléphone, email) et les mentions légales de la page « Confidentialité ».

Le fichier se modifie directement sur l'hébergement : le changement est immédiat.

## 6. Utilisation au quotidien par VBM

**Avec Google Agenda** :

- Chaque enlèvement apparaît dans l'agenda, avec toutes les informations dans la description.
- **Bloquer un créneau ou une journée** : créez un événement « Fermé », « Bloqué », « Congé » ou « Complet », ou un événement sur toute la journée.
- **Un rendez-vous ajouté à la main** (pris par téléphone, par exemple) occupe une place du créneau.
- **Déplacer un enlèvement** : déplacez l'événement, sa place suit.
- **Annuler** : supprimez l'événement, le créneau est libéré. Prévenez le garage.

**Sans Google Agenda** : les enlèvements arrivent par email. Pour annuler, mettez le champ `status` de la réservation à `cancelled` dans phpMyAdmin : la place est libérée.

Dans tous les cas, la table `bwc_bookings` garde l'historique, avec l'état de chaque email (`garage_mail`, `vbm_mail`) et de la copie dans l'agenda (`google_status`).

## 7. Fiabilité et sécurité

**Ce qui est garanti** :

- **Pas de surréservation.** La journée est verrouillée dans MySQL (`SELECT … FOR UPDATE`) pendant l'enregistrement : quand deux garages veulent la dernière place au même instant, un seul l'obtient. L'autre voit « Ce créneau vient d'être complété » et garde ses informations.
- **Pas de doublon.** Un double clic, un renvoi après une coupure réseau ou un formulaire rechargé ne créent jamais deux réservations.
- **Confirmation honnête.** Le garage ne voit « confirmé » qu'une fois la réservation enregistrée. Si l'agenda Google ne répond pas, rien n'est confirmé et le garage est invité à réessayer.
- **Emails rattrapés.** Un email en échec est renvoyé par la tâche planifiée, sans doublon.
- **Photos** : 5 au maximum, réduites dans le navigateur et vérifiées sur le serveur d'après leur contenu. Elles sont jointes à l'email de VBM et ne sont jamais enregistrées sur le serveur.

**Sécurité** :

- Les mots de passe et clés sont dans `app/config.php`, hors de `public_html`, et ne sont jamais envoyés sur GitHub.
- Requêtes SQL préparées et textes échappés partout. Seuls des en-têtes de sécurité stricts (CSP) sont envoyés au navigateur.
- Les envois depuis un autre site sont refusés. Un champ piège arrête les robots, et le nombre de tentatives est limité par connexion (dans MySQL). Cloudflare Turnstile est disponible en option (section `turnstile`).
- Les journaux (`app/storage/logs`) ne contiennent que des références, jamais de données personnelles. Les erreurs PHP n'y sont jamais affichées aux visiteurs.

## 8. Essayer en local et tests

Avec PHP 8.1+ et Docker installés, depuis la racine du dépôt :

```bash
docker run -d --name bwc-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=bwc -e MYSQL_USER=bwc -e MYSQL_PASSWORD=bwc-local -p 127.0.0.1:3307:3306 mysql:8.0
```

Une fois MySQL démarré (une vingtaine de secondes), créez les tables et la base de test :

```bash
docker exec -i bwc-mysql mysql -ubwc -pbwc-local bwc < php/database/schema.sql
```

```bash
docker exec bwc-mysql mysql -uroot -proot -e "CREATE DATABASE bwc_test CHARACTER SET utf8mb4; GRANT ALL ON bwc_test.* TO 'bwc'@'%'"
```

Puis lancez le site en mode démonstration sur http://localhost:3300 :

```bash
BWC_CONFIG_FILE="$PWD/php/tests/config.test.php" BWC_TEST_DB_NAME=bwc BWC_STORAGE_DIR="$PWD/.data/php-dev" php -S localhost:3300 -t php/public_html php/dev-router.php
```

Les tests utilisent la base `bwc_test`, qu'ils vident à chaque lancement :

```bash
php php/tests/run.php
```

```bash
npx playwright test -c playwright.php.config.ts
```

- `php/tests/run.php` : 41 tests. Ils couvrent les créneaux, les changements d'heure, les jours fériés, la vérification du formulaire, la capacité, les doublons, six réservations simultanées sur le même créneau, les règles Google Agenda, le renvoi des emails, les photos et l'envoi SMTP.
- Playwright : les 18 tests navigateur de la version Next.js, joués contre la version PHP, sur ordinateur et sur mobile.
