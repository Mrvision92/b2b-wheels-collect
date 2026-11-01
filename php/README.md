# B2B Wheels Collect — version PHP / MySQL

Le site de réservation et son administration, en **HTML, CSS, JavaScript, PHP et MySQL**, pour un hébergement web classique comme Hostinger.

- PHP 8.2 ou plus récent (8.1 fonctionne), **sans Composer ni dépendance** : on dépose les fichiers, c'est tout.
- MySQL 5.7+, MySQL 8 ou MariaDB 10.3+.
- JavaScript sans framework ni étape de compilation.
- Aucun serveur permanent, aucun Docker : deux tâches planifiées (cron) suffisent.

**Le parcours complet :**

1. Un garage réserve sur le site public : coordonnées, adresse, nombre de jantes, date, créneau, photos.
2. La réservation est vérifiée puis enregistrée dans MySQL, la journée verrouillée pour éviter toute surréservation.
3. Le garage reçoit sa confirmation, VBM reçoit une notification avec les photos.
4. La réservation apparaît aussitôt dans l'administration (`/admin`) : vue d'ensemble, liste, planning, fiche du garage.
5. L'enlèvement est copié dans Google Agenda si l'agenda est connecté.
6. Une fois les jantes récupérées, VBM clique sur « Marquer enlevé ».
7. Le garage reçoit un email confirmant que ses jantes ont été récupérées. Les statistiques et l'historique du garage se mettent à jour.

Les garages n'ont ni compte ni mot de passe. L'administration, elle, est protégée par une connexion.

> La démonstration en ligne sur Vercel (https://b2b-wheels-collect.vercel.app) est la version Next.js du site public, sans administration. Elle ne peut pas partager la base MySQL. **La production, c'est cette version PHP**, sur l'hébergement de VBM.

---

## Sommaire

1. [Contenu du dossier](#1-contenu-du-dossier)
2. [Mise en ligne sur l'hébergement de VBM](#2-mise-en-ligne-sur-lhébergement-de-vbm)
3. [L'administration](#3-ladministration)
4. [Emails](#4-emails)
5. [Google Agenda (facultatif)](#5-google-agenda-facultatif)
6. [Régler les jours, horaires et créneaux](#6-régler-les-jours-horaires-et-créneaux)
7. [Fiabilité et sécurité](#7-fiabilité-et-sécurité)
8. [Sauvegardes et restauration](#8-sauvegardes-et-restauration)
9. [RGPD : ce que VBM doit compléter](#9-rgpd--ce-que-vbm-doit-compléter)
10. [Coûts annuels estimés](#10-coûts-annuels-estimés)
11. [Mettre à jour une installation existante](#11-mettre-à-jour-une-installation-existante)
12. [Essayer en local et tests](#12-essayer-en-local-et-tests)

---

## 1. Contenu du dossier

```text
php/
├── public_html/                → à déposer dans public_html (seule partie visible sur Internet)
│   ├── index.php               site public : la page et le formulaire
│   ├── api/                    disponibilités, réservation, état des services (JSON)
│   ├── admin/                  administration : pages, actions (api.php), export CSV
│   ├── demo/email.php          aperçu des emails en mode démonstration
│   ├── assets/                 CSS, JavaScript, police Mona Sans, photo de la camionnette
│   ├── _bootstrap.php          emplacement du dossier app/ (à adapter seulement s'il change)
│   ├── .htaccess, .user.ini    HTTPS, en-têtes de sécurité, taille des envois
│   └── robots.txt              l'administration n'est pas indexée
├── app/                        → à déposer à côté de public_html, jamais dedans
│   ├── config.example.php      modèle de configuration secrète (MySQL, emails, Google)
│   ├── settings.php            jours, horaires, créneaux, coordonnées, mentions légales
│   ├── database/               schema.sql (installation neuve) et migrations/ (mises à jour)
│   ├── src/                    le code PHP (classes Bwc\…, dont Bwc\Admin\…)
│   ├── views/admin/            gabarits des pages de l'administration
│   ├── cron/                   notifications.php (toutes les 15 min), maintenance.php (chaque nuit)
│   ├── tools/                  check.php, migrate.php, admin.php, seed-demo.php (ligne de commande)
│   └── storage/                journaux, sessions, sauvegardes, cache (créé tout seul)
└── tests/                      tests automatiques (pas à déposer)
```

## 2. Mise en ligne sur l'hébergement de VBM

Le site s'installe sur un compte d'hébergement **au nom de VBM**, payé par VBM, par exemple Hostinger « Business Web Hosting ». Rien n'est à installer chez Hostinger en plus de PHP et MySQL, déjà inclus.

1. **PHP** : hPanel → Avancé → Configuration PHP → PHP 8.3. Les extensions nécessaires (`pdo_mysql`, `curl`, `openssl`, `mbstring`) sont actives par défaut.
2. **Base de données** : hPanel → Bases de données → Bases de données MySQL → créer une base et son utilisateur.
3. **Fichiers**, avec le gestionnaire de fichiers ou en FTP, dans `domains/votre-domaine.be/` :
   - le **contenu** de `public_html/` va dans `public_html/` (y compris les fichiers cachés `.htaccess` et `.user.ini`) ;
   - le dossier `app/` va **à côté** de `public_html/`, pas dedans.

   ```text
   domains/votre-domaine.be/
   ├── app/
   └── public_html/
   ```
4. **Configuration** : copiez `app/config.example.php` en `app/config.php` et remplissez l'adresse du site, la clé secrète, les accès MySQL, les emails et Google. Pour générer la clé secrète, lancez une fois en SSH (hPanel → Avancé → Accès SSH) `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`, puis ne la changez plus.
5. **Tables** : créez-les en SSH :

   ```bash
   cd domains/votre-domaine.be && php app/tools/migrate.php
   ```

   Sans SSH, importez `app/database/schema.sql` dans phpMyAdmin (onglet **Importer**).
6. **Premier administrateur**, en SSH (le mot de passe est demandé, il n'est écrit nulle part) :

   ```bash
   php app/tools/admin.php create planning@vbmwheels.com "Équipe VBM"
   ```

   Sans SSH : mettez une clé longue dans `admin_setup_key` (config.php), ouvrez `https://votre-domaine.be/admin/installation.php`, créez le compte, puis videz la clé. Les collègues s'ajoutent ensuite depuis **Mon compte → Inviter** : ils reçoivent un lien pour choisir leur mot de passe.
7. **HTTPS** : vérifiez que le certificat SSL est actif (hPanel → Sécurité → SSL). Le fichier `.htaccess` redirige déjà vers `https://`.
8. **Tâches planifiées** : hPanel → Avancé → Tâches Cron. Remplacez `u123456789` par votre identifiant, visible dans le gestionnaire de fichiers.

   | Fréquence | Commande | Rôle |
   | --- | --- | --- |
   | Toutes les 15 min (`*/15 * * * *`) | `/usr/bin/php /home/u123456789/domains/votre-domaine.be/app/cron/notifications.php` | renvoie les emails et les copies Google Agenda en attente |
   | Chaque nuit (`0 3 * * *`) | `/usr/bin/php /home/u123456789/domains/votre-domaine.be/app/cron/maintenance.php` | sauvegarde la base, nettoie, applique la durée de conservation |
9. **Vérification** en SSH, avec l'envoi d'un email de test :

   ```bash
   php app/tools/check.php --send-test=votre@email.be
   ```

   Tout doit être « OK ». On peut aussi ouvrir `https://votre-domaine.be/api/health.php`.
10. **Production** : dans `app/config.php`, passez `'demo' => false`. Le bandeau « Mode démonstration » disparaît et les vrais emails partent.
11. **Domaine** : si le domaine est déjà utilisé ailleurs (par exemple pour la démonstration Vercel), ne changez les DNS qu'une fois les points 1 à 10 vérifiés sur l'adresse temporaire fournie par Hostinger.

## 3. L'administration

Adresse : `https://votre-domaine.be/admin/`. Elle s'utilise sur ordinateur comme sur téléphone. Sur téléphone, la barre du bas donne accès aux quatre sections.

**Vue d'ensemble** : chiffres du jour et du mois, activité des six derniers mois, prochaines collectes, enlèvements du jour avec le bouton « Marquer enlevé ». Cliquer sur un mois du graphique affiche ses chiffres. Un bandeau signale les enlèvements passés qui n'ont pas encore été marqués.

**Enlèvements** : toutes les réservations, celles du site comme celles saisies par VBM. Filtres Tous, Aujourd'hui, À enlever, Enlevés et Annulés ; recherche par garage, référence, ville, contact, email ou téléphone ; tri, pagination et export CSV (Excel). Le panneau de détail permet d'appeler le garage, de lui écrire, d'ouvrir l'itinéraire, et de modifier, annuler, confirmer ou marquer comme enlevé. On y voit aussi l'état de chaque email, avec un bouton « Renvoyer » en cas d'échec, et l'historique complet.

**Garages clients** : l'annuaire se remplit tout seul. Une réservation est rattachée au garage choisi par VBM ; sinon au garage qui a la **même adresse email** ; sinon, faute d'email, au garage qui a le même téléphone et le même nom. Deux garages aux noms proches ne sont jamais fusionnés automatiquement : depuis la fiche du garage, VBM peut corriger les coordonnées, ajouter des notes, voir les autres contacts et adresses utilisés, fusionner un doublon ou créer un enlèvement prérempli.

**Planning** : vues jour, semaine et mois, avec les enlèvements placés à leur heure réelle. Plusieurs garages sur le même créneau s'affichent côte à côte, dans la limite des places configurées. Sur téléphone, le planning s'ouvre sur la journée.

**Nouvel enlèvement** (bouton présent sur chaque page) : choisir un garage existant ou en saisir un nouveau, le nombre de jantes, une date puis un créneau libre (les places restantes sont affichées). Ces enlèvements suivent exactement les mêmes règles que le site public, avec deux différences : VBM peut réserver plus tard dans la journée, sans le délai de 12 h, et l'email du garage est facultatif (réservation prise par téléphone).

**Statuts** :

| Statut | Libellé | Quand |
| --- | --- | --- |
| `pending` | En attente | Enlèvement saisi par VBM « en attente de confirmation ». Pas d'email ni d'événement Google tant qu'il n'est pas confirmé. |
| `confirmed` | À enlever | Toute réservation du site (confirmée immédiatement, comme avant), ou confirmée par VBM. |
| `completed` | Enlevé | Après « Marquer enlevé » : date réelle et administrateur enregistrés. |
| `cancelled` | Annulé | Créneau libéré, réservation conservée dans l'historique avec le motif. |

Chaque changement est inscrit dans l'historique de la réservation, avec sa date et l'administrateur. Rien n'est jamais supprimé.

**Règles des chiffres**, à l'heure de Bruxelles :

- **Aujourd'hui** : enlèvements prévus ce jour, annulés exclus.
- **À venir** : enlèvements en attente ou à enlever, prévus à partir d'aujourd'hui.
- **Effectués** et **Jantes récupérées** : d'après la date réelle de l'enlèvement, pas la date de réservation. Le mois en cours est comparé à la même période du mois précédent : le 15 novembre à 10 h se compare au 15 octobre à 10 h.
- **Réservations reçues** : d'après la date de création.

## 4. Emails

| Email | Destinataire | Envoyé |
| --- | --- | --- |
| Confirmation de réservation | garage | à la réservation (site), ou à la saisie / confirmation par VBM |
| Notification de nouvel enlèvement, avec les photos | VBM (`vbm_recipients`) | à chaque réservation du site |
| « Confirmation d'enlèvement de vos jantes » | garage | quand VBM marque l'enlèvement comme effectué |
| Nouveau créneau | garage | quand VBM déplace l'enlèvement (case à cocher) |
| Annulation | garage | quand VBM annule (case à cocher) |
| Lien de mot de passe | administrateur | invitation ou mot de passe oublié |

- **Jamais deux fois.** Chaque événement porte une clé unique dans la table `bwc_notifications` : un double clic, un rechargement ou un nouvel essai ne renvoient pas l'email.
- **« Envoyé » seulement une fois accepté** par le service d'envoi. Sa réponse est enregistrée.
- **En cas d'échec**, l'action reste enregistrée (par exemple l'enlèvement reste « Enlevé »), l'email passe « en attente », la tâche planifiée le renvoie (12 essais au plus), et le bouton « Renvoyer » du panneau de détail permet de relancer à la main.
- **Fournisseur** : une boîte email Hostinger en SMTP (`smtp.hostinger.com`, port 465, SSL), ou Resend (`'transport' => 'resend'`, après vérification du domaine dans leurs réglages DNS). Expéditeur, adresse de réponse et destinataires VBM se règlent dans `app/config.php`.
- Un email accepté par le fournisseur n'arrive pas forcément en boîte de réception (filtres anti-spam). Pour l'améliorer, configurez SPF, DKIM et DMARC du domaine dans hPanel.

## 5. Google Agenda (facultatif)

**La base MySQL fait foi**, Google Agenda en est une copie pour le planning de VBM. Sans Google, tout fonctionne : réservations, administration, emails.

Avec Google, la copie se fait automatiquement :

- un événement est créé quand l'enlèvement est confirmé ;
- il suit chaque changement de créneau ou d'informations ;
- il est supprimé à l'annulation ;
- une fois enlevé, il reste dans l'agenda, en vert, avec « ✓ ENLEVÉ » devant le titre.

L'identifiant de l'événement est fixe : un nouvel essai ne crée jamais de doublon. Si Google ne répond pas, la réservation est tout de même enregistrée et la copie est reprise par la tâche planifiée.

Ce que VBM ajoute **lui-même** dans l'agenda est respecté : un rendez-vous pris à la main occupe une place, et un événement « Fermé », « Bloqué », « Congé » ou « Complet », ou sur toute la journée, ferme le créneau. Les modifications des enlèvements se font dans l'administration ; celles faites directement sur leurs événements Google ne sont pas reprises.

**Mise en place, avec un compte de service** (pas d'écran de consentement, pas d'expiration) :

1. https://console.cloud.google.com → créer un projet → **API et services** → activer **Google Calendar API**.
2. **IAM et administration → Comptes de service** → créer un compte → onglet **Clés** → Ajouter une clé → JSON.
3. Déposez le fichier dans `app/storage/` et indiquez son chemin complet dans `service_account_file`.
4. Dans Google Agenda (compte de VBM), créez l'agenda « B2B Wheels Collect — Enlèvements ». Puis Paramètres de l'agenda → **Partager avec des personnes en particulier** → ajoutez l'adresse du compte de service (`…@….iam.gserviceaccount.com`) avec **Apporter des modifications aux événements**.
5. Copiez l'**ID de l'agenda** (section « Intégrer l'agenda ») dans `calendar_id`, puis vérifiez avec `php app/tools/check.php`.

Aucun mot de passe Google de VBM n'est nécessaire : VBM partage simplement l'agenda avec le compte de service.

## 6. Régler les jours, horaires et créneaux

Tout est dans `app/settings.php`, commenté en français : jours et plages d'ouverture (lundi–vendredi, 9 h–17 h), durée des créneaux (2 h), **nombre d'enlèvements par créneau (2)**, délai minimum (12 h), réservation à l'avance (60 jours), jours fériés belges, fermetures exceptionnelles, nombre maximum de jantes, coordonnées affichées, mentions légales et durée de conservation. Le site public et l'administration utilisent les mêmes réglages : leurs disponibilités sont toujours identiques.

## 7. Fiabilité et sécurité

- **Pas de surréservation** : la journée est verrouillée dans MySQL (`SELECT … FOR UPDATE`) pendant chaque réservation ou changement de créneau, au site comme dans l'administration. Six réservations simultanées sur le même créneau de deux places donnent exactement deux réservations ; c'est testé.
- **Pas de doublon** : un double clic, une connexion perdue ou un formulaire renvoyé ne créent jamais deux réservations. Un statut ne change qu'une fois, même si on clique deux fois sur « Marquer enlevé ».
- **Connexion à l'administration** : mots de passe hachés (`password_hash`), sessions côté serveur dans `app/storage/sessions`, cookie `Secure`, `HttpOnly` et `SameSite=Lax` limité à `/admin/`. Jeton CSRF sur chaque action. Limite de 8 échecs par compte et 20 par connexion en 15 minutes. Déconnexion après 7 jours sans activité ou 30 jours au plus, et sur tous les appareils dès que le mot de passe change ou que le compte est désactivé. Mot de passe oublié : lien à usage unique valable une heure.
- **Aucun mot de passe dans le code** : les accès sont dans `app/config.php`, hors de `public_html`, jamais versionnés. Aucune clé n'est envoyée au navigateur.
- Requêtes SQL préparées, textes échappés, en-têtes de sécurité stricts (CSP), refus des envois depuis un autre site, champ piège anti-robots, limite de réservations et d'actions par connexion, Cloudflare Turnstile en option.
- Journaux dans `app/storage/logs`, sans donnée personnelle (références uniquement).

## 8. Sauvegardes et restauration

- **Chaque nuit**, `app/cron/maintenance.php` sauvegarde toutes les tables dans `app/storage/backups` (fichier `.sql.gz`, les 14 dernières gardées). Une sauvegarde est aussi faite avant chaque migration.
- **Hostinger** fait en plus ses propres sauvegardes (hPanel → Fichiers → Sauvegardes ; leur fréquence dépend de l'offre).
- **Copie hors du serveur** : téléchargez de temps en temps une sauvegarde depuis le gestionnaire de fichiers.
- **Restaurer** : phpMyAdmin → la base → **Importer** → choisir le fichier `.sql.gz`. Les tables sont recréées telles qu'au moment de la sauvegarde. Faites-le de préférence site en maintenance, puis relancez `php app/tools/check.php`.

## 9. RGPD : ce que VBM doit compléter

Avant la mise en ligne, VBM doit fournir :

- **Mentions légales** dans `app/settings.php` (`legal`) : dénomination, adresse du siège, numéro d'entreprise, email de contact RGPD et durée de conservation. Tant qu'elles sont vides, la page « Confidentialité » affiche « à compléter par VBM ». Elles n'ont pas été inventées.
- **Durée de conservation** (`data_retention_months`) : après ce délai, la tâche de nuit efface le contact, le téléphone, l'email, la rue et le commentaire des enlèvements terminés ou annulés. Les chiffres restent. Par défaut rien n'est effacé, c'est à décider par VBM.
- Les coordonnées affichées (WhatsApp, téléphone, email) viennent du site de VBM et sont à confirmer.

Seules les données utiles à l'enlèvement sont demandées. Les photos ne sont jamais enregistrées sur le serveur.

## 10. Coûts annuels estimés

Estimation d'après les prix publics relevés en novembre 2026, à vérifier au moment de l'achat (prix hors TVA, régionaux, qui changent souvent) :

| Poste | Coût estimé |
| --- | --- |
| Hébergement Hostinger Business Web Hosting | environ 4 $/mois en offre de lancement sur 48 mois, environ 17 à 19 $/mois au renouvellement, soit environ 50 $ par an la première période puis 200 à 230 $ par an |
| Nom de domaine `.be` | environ 10 à 20 € par an (souvent offert la première année avec l'hébergement) |
| Emails | 0 € avec une boîte Hostinger en SMTP ; ou Resend, gratuit jusqu'à 100 emails par jour (environ 3 000 par mois), Pro à environ 20 $/mois au-delà |
| Google Agenda (API) | 0 € pour ce volume |
| Cloudflare Turnstile (option) | 0 € |
| Logiciels, serveur, Docker | 0 € : aucun abonnement, aucun service payant en plus |

**Total** : environ 60 à 80 € par an pendant la période de lancement, puis 210 à 260 € par an, hors TVA. Une réservation produit 2 à 3 emails : même 300 réservations par mois restent dans l'offre gratuite de Resend, ou sans frais avec la boîte Hostinger.

## 11. Mettre à jour une installation existante

1. Déposez les nouveaux fichiers, sans écraser `app/config.php` ni `app/storage/`.
2. Lancez `php app/tools/migrate.php`. Il sauvegarde d'abord la base, puis applique les mises à jour manquantes, dans l'ordre. Les données existantes sont conservées : par exemple, la migration `001_admin_panel` crée les garages à partir des réservations existantes et garde l'état de chaque email.
3. Lancez `php app/tools/check.php`.

## 12. Essayer en local et tests

Avec PHP 8.2+ et Docker installés, depuis la racine du dépôt :

```bash
docker run -d --name bwc-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=bwc -e MYSQL_USER=bwc -e MYSQL_PASSWORD=bwc-local -p 127.0.0.1:3307:3306 mysql:8.0
```

Une vingtaine de secondes plus tard, créez la base de test :

```bash
docker exec bwc-mysql mysql -uroot -proot -e "CREATE DATABASE bwc_test CHARACTER SET utf8mb4; GRANT ALL ON bwc_test.* TO 'bwc'@'%'"
```

Puis créez les tables, des données fictives et un compte d'administration local :

```bash
export BWC_CONFIG_FILE="$PWD/php/tests/config.test.php" BWC_TEST_DB_NAME=bwc BWC_STORAGE_DIR="$PWD/.data/php-dev"
```

```bash
php php/app/tools/migrate.php && php php/app/tools/seed-demo.php && php php/app/tools/admin.php create vous@exemple.be "Équipe VBM"
```

Lancez enfin le site, puis ouvrez http://localhost:3300 et http://localhost:3300/admin/ :

```bash
php -S localhost:3300 -t php/public_html php/dev-router.php
```

`seed-demo.php` refuse de tourner hors du mode démonstration : les statistiques de production ne sont jamais faussées.

**Tests**, sur la base `bwc_test` qu'ils vident à chaque lancement :

```bash
php php/tests/run.php
```

```bash
npx playwright test -c playwright.php.config.ts
```

- `php/tests/run.php`, 53 tests : créneaux, changements d'heure, jours fériés, formulaire, capacité, doublons, réservations simultanées, Google Agenda (création, modification, suppression, reprise après panne), statuts et historique, « Marquer enlevé » avec un seul email, email en échec puis renvoi, changement de créneau, annulation, garages (rattachement, fusion), statistiques, connexion (mauvais mot de passe, limite, cookie, CSRF, sessions fermées), export CSV, SMTP.
- Playwright, 28 tests sur ordinateur et téléphone : les tests du site public (les mêmes que pour la version Next.js) et ceux de l'administration (connexion, réservation du site visible et marquée enlevée, nouvel enlèvement, planning, déconnexion, aucune page plus large que l'écran).
