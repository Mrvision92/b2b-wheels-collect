# B2B Wheels Collect — developer handover

This delivery contains two packages that together form the B2B Wheels Collect system for VBM Wheels Repair (Belgium):

| Package | What it is | Who uses it |
| --- | --- | --- |
| `b2b-wheels-collect-site/` | The public one-page website where professional garages book a rim pickup | Garages (no account) |
| `b2b-wheels-collect-admin/` | The administration panel used to manage pickups, garages and the planning | VBM staff (login required) |

Both are plain **PHP 8.2+ / MySQL (or MariaDB) / HTML / CSS / vanilla JavaScript**. There is no framework, no Composer, no Node.js and no build step: you upload the files and configure them. They run on standard shared hosting such as **Hostinger Business Web Hosting**, with two cron jobs and no long-running process.

The source code lives in the Git repository https://github.com/Mrvision92/b2b-wheels-collect (branch `feature/rimcollect-v1`), folder `php/`. The two packages are generated from that single source with `bash php/packaging/build.sh`, so they can never drift apart.

> The Vercel URL (b2b-wheels-collect.vercel.app) is an older Next.js demo of the public page only. It is **not** part of this delivery and does not share the database. Production is these two PHP packages.

---

## 1. What was built

### Public booking site (`b2b-wheels-collect-site`)

- One page in French, with a premium design: a booking form (garage, contact, phone, email, pickup address, number of rims, optional reference, comment and up to 5 photos), an interactive calendar of available days, time slots, a "how it works" section, a WhatsApp button and a privacy notice.
- Availability follows `app/settings.php`:
  - opening days and hours, 2-hour slots, **2 pickups per slot**;
  - 12 h minimum notice, booking up to 60 days ahead;
  - Belgian public holidays and closed dates.
- **No double booking.** The day is locked in MySQL (`SELECT … FOR UPDATE`) while a booking is saved. Six simultaneous requests for the last places of a slot give exactly two bookings; this is tested.
- **No duplicates.** A double click, a lost connection or a resubmitted form never creates two bookings: each request is idempotent through a hash of the browser's request id.
- The garage only sees "confirmed" once the booking is stored. The garage then receives a confirmation email, and VBM receives a notification email with the photos attached.
- Photos are resized in the browser (max 1600 px), checked on the server by their content, and stored outside the web root.
- Optional Google Calendar copy and optional Cloudflare Turnstile.

### Administration (`b2b-wheels-collect-admin`)

It has the same look on desktop and mobile, following the reference mockups: a left sidebar on desktop and a bottom tab bar on mobile.

- **Login:**
  - email + password, with a show-password eye;
  - "forgot password" sends a single-use link, valid 1 hour;
  - colleagues are invited by email (link valid 72 h);
  - accounts can be disabled.
- **Overview ("Vue d'ensemble"):**
  - today's pickups, upcoming pickups, pickups completed this month and rims collected;
  - comparison with the same period of the previous month;
  - a clickable 6-month chart, the next collections, and today's list with "Marquer enlevé" (mark as collected).
- **Pickups ("Enlèvements"):**
  - filters (all, today, to collect, collected, cancelled), search (garage, reference, city, contact, email, phone), sorting, pagination and CSV export;
  - a details panel with call / email / Google Maps buttons, the photos (large view) and an internal note;
  - edit, reschedule (capacity checked), cancel (with reason), confirm a pending pickup, mark as collected, and undo a collection marked by mistake;
  - email status with "resend", and the full history (who did what, when).
- **Marking as collected** asks for confirmation. It stores the real date and the admin, sends **one** confirmation email to the garage (never twice, even on double click or retry), and updates the statistics, the garage history and Google Calendar.
- **Garages ("Garages clients"):**
  - a directory filled automatically from bookings, matched by email and never by similar names;
  - each profile shows activity, history, other contacts and addresses used, and notes;
  - a profile can be edited, merged with a duplicate, or used to create a prefilled pickup;
  - CSV export of all garages, and GDPR erasure of a garage's personal data.
- **Planning:** day / week / month views, with bookings placed at their real time and side by side when they share a slot. It includes a printable daily route sheet with a Google Maps tour link.
- **New pickup:** existing or new garage, a date, then a free slot (remaining places shown). It follows the same rules as the public site, except that VBM can book later the same day and the email is optional for phone bookings.
- **Account ("Mon compte"):**
  - password change and administrators;
  - **service status** (emails pending or failed, Google Calendar, last cron run, last backup, database migrations, legal information, identical settings on both sides);
  - CSV exports and a full backup download.

### Emails

Each email event has a unique key in `bwc_notifications`, so the same event is never sent twice. An email is marked "sent" only once the provider has accepted it, and the provider's response is stored. Failures are retried by the cron job (up to 12 attempts) or by hand.

| Email | Recipient | When |
| --- | --- | --- |
| Booking confirmation | garage | public booking, or admin booking / confirmation |
| New pickup notification (with photos) | VBM | every public booking |
| "Confirmation d'enlèvement de vos jantes — B2B Wheels Collect" | garage | when VBM marks the pickup as collected |
| New time slot | garage | when VBM reschedules (optional) |
| Cancellation | garage | when VBM cancels (optional) |
| Password link | admin | invitation or forgotten password |

Two providers are supported: SMTP (for example a Hostinger mailbox, `smtp.hostinger.com:465` SSL), or the Resend API.

### Google Calendar (optional)

**MySQL is the source of truth.** Google Calendar is a copy:

- an event is created on confirmation, updated on change and deleted on cancellation;
- once the pickup is collected, the event turns green with "✓ ENLEVÉ" in front of the title;
- event IDs are fixed, so retries never create duplicates, and failures are retried by the cron job.

Events that VBM adds by hand in Google Calendar take a place, and events titled "Fermé", "Bloqué"… (or all-day events) close the slot. The connection uses a service account: VBM shares the calendar with the account's email, and no Google password is needed.

---

## 2. How the two packages are connected

```text
                 garages                                 VBM staff
                    │                                        │
                    ▼                                        ▼
   ┌──────────────────────────────┐        ┌──────────────────────────────┐
   │  b2b-wheels-collect-site     │        │  b2b-wheels-collect-admin    │
   │  www.domain.be               │        │  admin.domain.be             │
   │  booking form, availability  │        │  login, pickups, garages,    │
   │  public API, cron jobs,      │        │  planning, exports, status   │
   │  database migrations         │        │                              │
   └──────────────┬───────────────┘        └───────────────┬──────────────┘
                  │       same MySQL database (bwc_* tables)│
                  └─────────────────►  ◄────────────────────┘
                  │       same photos folder (photos_dir)   │
                  └─────────────────►  ◄────────────────────┘
```

They are separate folders with separate `config.php` files, but they are **one system**. Four things must be identical on both sides:

1. **The database**: same host, database name, user and password in both `app/config.php`.
2. **`app_secret`**: the same value in both `app/config.php`. It is used to compute booking references and request hashes.
3. **`photos_dir`**: the same absolute path in both `app/config.php` (for example the site's `app/storage/photos`). The site writes the photos and the admin displays them, so both must run on the **same hosting account**.
4. **`app/settings.php`**: the same file on both sides (slots, capacity, opening hours). The admin's service status warns if they differ.

In addition, in **both** `config.php` files, `app_url` is the public site address, used in emails to garages, and `admin_url` is the admin address, used in password links.

**Who does what with the database:**

- The **site** owns the schema: `php app/tools/migrate.php` lives in the site package. The site also runs the two cron jobs: email/calendar retries and the nightly backup with retention.
- The **admin** reads and writes the same tables. It creates admin accounts with `php app/tools/admin.php` (admin package).

Tables: `bwc_bookings`, `bwc_garages`, `bwc_booking_photos`, `bwc_booking_history`, `bwc_notifications`, `bwc_admins`, `bwc_booking_days` (locks), `bwc_rate_limits`, `bwc_meta` (cron/backup/settings status), `bwc_migrations`.

---

## 3. Deployment on VBM's Hostinger account

Deploy on an account **owned by VBM** (not a personal account). The recommended layout puts the site on the main domain and the admin on a subdomain of the same account:

```text
/home/uXXXXXXXXX/domains/
├── domain.be/
│   ├── app/            ← b2b-wheels-collect-site/app
│   └── public_html/    ← contents of b2b-wheels-collect-site/public_html
└── admin.domain.be/
    ├── app/            ← b2b-wheels-collect-admin/app
    └── public_html/    ← contents of b2b-wheels-collect-admin/public_html
```

*Alternative:* the admin can live in a sub-folder of the site, `domain.be/admin/`. Upload the contents of the admin package's `public_html/` to `domain.be/public_html/admin/`, put its `app/` anywhere outside `public_html` (for example `domain.be/app-admin/`), and adjust the path in `admin/_bootstrap.php`. In that case, set `admin_url` to `https://domain.be/admin`.

**Steps:**

1. **PHP**: hPanel → Advanced → PHP Configuration → PHP 8.3 for both websites. The extensions `pdo_mysql`, `curl`, `openssl`, `mbstring` and `gd` are enabled by default.
2. **Subdomain**: hPanel → Domains → Subdomains → create `admin.domain.be`.
3. **Database**: hPanel → Databases → MySQL → create one database and one user. Both packages use them.
4. **Upload** both packages as shown above, including hidden files (`.htaccess`, `.user.ini`). `app/` must never be inside `public_html`.
5. **Configure the site**: copy `app/config.example.php` to `app/config.php` in the site package and fill it in. Generate `app_secret` once with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`, set `admin_url` = `https://admin.domain.be`, and set `photos_dir` = `/home/uXXX/domains/domain.be/app/storage/photos`.
6. **Configure the admin**: same file in the admin package, with the **same** database, `app_secret`, `app_url`, `admin_url` and `photos_dir`. Copy the site's `app/settings.php` over the admin's if you changed it.
7. **Create the tables** (SSH, site package): `cd domains/domain.be && php app/tools/migrate.php`. Without SSH, import `app/database/schema.sql` in phpMyAdmin.
8. **First administrator** (SSH, admin package): `cd domains/admin.domain.be && php app/tools/admin.php create planning@vbmwheels.com "Équipe VBM"`. The password is asked interactively and is never stored in a file. Without SSH, use `admin_setup_key` in the admin's `config.php` and open `https://admin.domain.be/installation.php`, then empty the key.
9. **Cron jobs** (hPanel → Advanced → Cron Jobs), on the **site** package only. Never run them from both packages.
   - every 15 minutes: `/usr/bin/php /home/uXXX/domains/domain.be/app/cron/notifications.php`
   - every night at 03:00: `/usr/bin/php /home/uXXX/domains/domain.be/app/cron/maintenance.php`
10. **SSL**: enable SSL for both the domain and the subdomain. The `.htaccess` files already redirect to HTTPS.
11. **Check**: run `php app/tools/check.php --send-test=you@example.com` in each package. `https://domain.be/api/health.php` must answer `"ok":true`.
12. **Go live**: set `'demo' => false` in both `config.php`. In demo mode emails are simulated (previewable) and Google Calendar is off.
13. **DNS**: only point the real domain to Hostinger once steps 1–12 are verified on Hostinger's temporary URL.

---

## 4. Configuration reference (`app/config.php`)

| Key | Site | Admin | Notes |
| --- | --- | --- | --- |
| `app_url` | ✔ | ✔ same | public site URL |
| `admin_url` | ✔ | ✔ same | admin URL (empty = `<app_url>/admin`) |
| `photos_dir` | ✔ | ✔ same | absolute path, shared |
| `app_secret` | ✔ | ✔ same | 64 hex characters; never change it after go-live |
| `demo` | ✔ | ✔ | `false` in production |
| `db` | ✔ | ✔ same | MySQL access |
| `mail` | ✔ | ✔ | SMTP or Resend. VBM recipients are only used by the site; garage emails are sent by both |
| `google` | ✔ | ✔ same | optional; service account JSON outside `public_html` |
| `turnstile` | ✔ | – | optional anti-spam on the public form |
| `admin_setup_key` | – | ✔ | only for the first account without SSH, then empty |

Business settings (slots, capacity, contacts, legal information, data retention) are in `app/settings.php`, identical on both sides.

---

## 5. Verification checklist (staging)

Run through this on the Hostinger temporary URL with `'demo' => true` first (emails are previewable, nothing is sent), then again with real credentials:

| # | Action | Expected result |
| --- | --- | --- |
| 1 | Open the site | Page loads, calendar shows available days, "Mode démonstration" banner in demo |
| 2 | Book a pickup with 2 photos and a reference | Confirmation with a `BWC-XXXX-XXXX` reference; in demo, links to the garage email and the VBM email (photos attached) |
| 3 | Open `https://admin.domain.be/` | Redirects to the login page; the eye shows the password |
| 4 | Log in | Overview shows the booking in "Prochaines collectes" |
| 5 | Pickups → Détails | All data, the 2 photos (click = large view), internal note, emails "Envoyé", history |
| 6 | Copy a photo URL into a private window | 401: photos are not public |
| 7 | "Marquer enlevé" → confirm | Status "Enlevé", toast "Email envoyé au garage", one completion email |
| 8 | Click "Marquer enlevé" again / reload | No second email |
| 9 | "Remettre à enlever" | Back to "À enlever"; marking again sends no new email |
| 10 | Modifier → change date/slot | Full slots disabled; reschedule email to the garage; history entry |
| 11 | Fill a slot with 2 bookings, try a third on the site | "Ce créneau vient d'être complété" |
| 12 | Nouvel enlèvement for an existing garage | Prefilled; appears in Planning and in the garage's history |
| 13 | Planning: day / week / month, route sheet | Bookings at their real time; printable sheet; Maps tour opens |
| 14 | Garages → Exporter (CSV) | Opens in Excel with accents and readable phone numbers |
| 15 | Mon compte → État du service | All green or explained; "Réglages des créneaux: identiques" |
| 16 | Mon compte → Télécharger une sauvegarde | A `.sql.gz` file that imports in phpMyAdmin |
| 17 | Log out, then "Mot de passe oublié ?" | Link by email (in demo, shown on the page); new password works; the old session is closed |
| 18 | Phone (Safari / Chrome) | Bottom tab bar, cards instead of tables, no horizontal scrolling |

---

## 6. Automated tests (in the repository)

Requirements: PHP 8.2+, Docker (MySQL 8) and Node.js (for Playwright). The full setup is in `php/README.md`, section 12 (French).

```bash
php php/tests/run.php                              # 59 tests
npx playwright test -c playwright.php.config.ts    # 32 browser tests, desktop + mobile
bash php/packaging/build.sh && php php/tests/packages-smoke.php   # the two packages installed separately
```

`packages-smoke.php` installs both delivered packages exactly as on the hosting: two PHP servers, two `config.php` files, one database and a shared photos folder. It checks that:
- the site's migrations create the tables, and the admin tool creates the first account;
- a booking with a photo made on the site appears in the admin, and the photo is displayed there (refused without login);
- "Marquer enlevé" sends the email;
- the admin's service status reads the site's cron run and confirms the settings are identical;
- password links point to `admin_url`.

- `php/tests/run.php` covers slots, DST, Belgian holidays, validation, capacity, idempotency, concurrent bookings, Google Calendar (create, update, delete, retry), statuses and history, single completion email, failed email retry, rescheduling, cancellation, garages (matching, merge, erasure), statistics, login (wrong password, rate limit, cookie flags, CSRF, session invalidation), password reset, photos (stored, protected, attached to retries), CSV exports and SMTP.
- The browser tests cover the public booking flow and the admin: login, password eye, public booking visible and marked as collected, photos visible and protected, new pickup, planning, route sheet, logout, and no page wider than the screen.

Not tested yet: real SMTP over TLS against Hostinger, the real Google Calendar API, and the deployment on Hostinger itself. These need VBM's credentials; see section 7.

---

## 7. What VBM must provide

- A Hostinger account in VBM's name, with the domain.
- A mailbox for sending (for example `reservations@domain.be`) or a Resend API key, and the addresses that receive new-pickup notifications.
- Optionally, Google Calendar: a dedicated calendar shared with the service account (steps in `php/README.md`, section 5).
- **Legal information** for the privacy page (`app/settings.php` → `legal`): company name, registered address, company number, privacy contact. It was deliberately left blank, never invented.
- The **data retention** period (`data_retention_months`). Until it is set, nothing is deleted automatically.
- Confirmation of the WhatsApp number, phone and email shown on the site (currently taken from VBM's website).

---

## 8. Notes and limitations

- Edits made directly to the site's events in Google Calendar are not synced back: changes go through the admin. Manual events and closures added by VBM in Google are respected.
- HEIC photos that the browser could not convert are offered as a download instead of a preview.
- Backups made by the nightly cron contain the database, not the photos. Hostinger's file backups cover `app/storage/photos`.
- The admin needs read access to the site's photos folder, so both packages must be on the same hosting account (or the admin inside the site, see the alternative in section 3).
- Security:
  - prepared statements and output escaping everywhere;
  - a strict Content-Security-Policy, CSRF tokens, and Secure / HttpOnly / SameSite session cookies;
  - login rate limiting (failures only), password hashing (`password_hash`), and sessions invalidated on password change or account deactivation;
  - secrets only in `app/config.php`, outside the web root and outside Git;
  - logs without personal data.

## 9. Running costs (estimate, to confirm at purchase)

- **Hostinger Business:** about 4 $/month on the 48-month launch offer, then about 17–19 $/month on renewal.
- **`.be` domain:** about 10–20 € per year.
- **Emails:** free with a Hostinger mailbox, or with Resend's free tier (100 emails/day).
- **Google Calendar API:** free.

No other subscription is required.
