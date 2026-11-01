# B2B Wheels Collect — administration panel

The back office used by VBM to manage every rim pickup booked on the public site, or entered by phone. It is in French, with a desktop layout (sidebar) and a mobile layout (bottom tab bar).

**Read `HANDOVER.md` first.** This package does not work alone: it uses the **same MySQL database** as the site package, the **same `app_secret`**, the **same photos folder** (`photos_dir`) and the **same `app/settings.php`**.

## Contents

```text
b2b-wheels-collect-admin/
├── public_html/              → web root of the admin (e.g. admin.domain.be/public_html)
│   ├── index.php             overview (dashboard)
│   ├── enlevements.php       pickups list
│   ├── garages.php, garage.php   garage directory and profile
│   ├── planning.php          day / week / month planning
│   ├── feuille-de-route.php  printable daily route sheet + Google Maps tour
│   ├── compte.php            account, administrators, service status, data exports
│   ├── connexion.php, mot-de-passe.php, deconnexion.php, installation.php   login and passwords
│   ├── api.php               JSON actions (session + CSRF token required)
│   ├── photo.php             serves booking photos to logged-in admins only
│   ├── export.php            CSV exports (pickups, all garages)
│   ├── sauvegarde.php        full database backup download
│   ├── assets/               admin.css, admin.js, password.js, font, favicon
│   ├── _bootstrap.php        path to app/ (change only if app/ moves)
│   ├── .htaccess, .user.ini, robots.txt (whole admin excluded from search engines)
├── app/                      → next to public_html, never inside
│   ├── config.example.php    copy to config.php — same values as the site (see HANDOVER.md §4)
│   ├── settings.php          must be identical to the site's
│   ├── src/                  PHP code, including src/Admin/ (auth, API, views helpers)
│   ├── views/admin/          page templates
│   ├── database/             schema and migrations (read only here: migrations run from the site)
│   ├── tools/                admin.php (accounts), check.php
│   └── storage/              sessions, logs, cache (created automatically, protected)
└── dev-router.php            local testing only: php -S localhost:8081 -t public_html dev-router.php
```

## Install

1. Create the subdomain `admin.domain.be` on the same hosting account as the site. Upload `public_html/` contents to its web root, and `app/` next to it. Alternatively, put it in `domain.be/public_html/admin/` (see HANDOVER.md §3).
2. `cp app/config.example.php app/config.php` and use the **same** `db`, `app_secret`, `app_url`, `admin_url`, `photos_dir`, `mail` and `google` values as the site.
3. Use the same `app/settings.php` as the site. "Mon compte → État du service" warns if they differ.
4. Create the first account, with the password asked interactively:
   `php app/tools/admin.php create planning@vbmwheels.com "Équipe VBM"`.
   Other commands: `invite`, `password`, `list`, `disable`, `enable`. Without SSH, use `admin_setup_key` + `/installation.php`, then empty the key.
5. `php app/tools/check.php` must report everything OK.
6. **Do not add cron jobs here.** They run from the site package only.

## Features

- **Overview:**
  - today, upcoming, completed this month (compared with the same period of the previous month) and rims collected;
  - a clickable 6-month chart, next collections, and today's pickups with "Marquer enlevé".
- **Pickups:**
  - filters, search (garage, reference, city, contact, email, phone), sort, pagination, CSV export;
  - a details panel with call / email / maps buttons, photos with large view, an internal note, email states with "resend", and the history;
  - edit or reschedule (capacity checked), cancel with reason, confirm a pending pickup;
  - mark as collected (one confirmation email to the garage, never twice), and undo a collection marked by mistake.
- **Garages:**
  - automatic directory, matched by email (never by similar names);
  - profile with activity and history, edit, notes, merge duplicates, new prefilled pickup;
  - CSV export of all garages, and GDPR erasure of a garage's personal data and photos.
- **Planning:** day / week / month, bookings at their real time, side by side when sharing a slot, plus the printable route sheet.
- **Account:**
  - password change, invitations, disable accounts;
  - service status (emails pending/failed, Google Calendar, last cron run, last backup, migrations, legal info, settings identical with the site);
  - CSV exports and a full backup download.

## Security

- Passwords are hashed with `password_hash`, and every password field has a show/hide eye. A "forgot password" link is valid 1 hour and works once; an invitation link is valid 72 hours.
- Sessions:
  - stored on the server (`app/storage/sessions`), with a `Secure`, `HttpOnly`, `SameSite=Lax` cookie scoped to the admin path;
  - closed after 7 days without activity, after 30 days at most, and on every device when the password changes or the account is disabled.
- A CSRF token is required on every change. Login failures are limited to 8 per account and 20 per IP in 15 minutes; only failures count. Admin actions are rate limited too.
- Photos stay outside the web root and are only served by `photo.php` to logged-in admins.
- No password or key appears in the code or in Git.
