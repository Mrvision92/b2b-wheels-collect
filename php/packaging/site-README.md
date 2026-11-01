# B2B Wheels Collect — public booking site

The website where professional garages book a rim pickup with VBM Wheels Repair. It is a single French page with the booking form, the availability calendar, the time slots, photo upload, a WhatsApp button and a privacy notice.

**Read `HANDOVER.md` first:** it explains how this package works together with the administration package (same database, same secret, same photos folder, same `settings.php`).

## Contents

```text
b2b-wheels-collect-site/
├── public_html/            → web root of the main domain (e.g. domain.be/public_html)
│   ├── index.php           the page
│   ├── api/                availability.php, bookings.php, health.php (JSON)
│   ├── demo/email.php      preview of simulated emails (demo mode only)
│   ├── assets/             CSS, JS, Mona Sans font, van photo
│   ├── _bootstrap.php      path to app/ (change only if app/ moves)
│   ├── .htaccess           HTTPS redirect, security headers, cache, hidden files blocked
│   └── .user.ini           upload limits for photos
├── app/                    → next to public_html, never inside
│   ├── config.example.php  copy to config.php and fill in
│   ├── settings.php        days, hours, slots, capacity, contacts, legal info, retention
│   ├── database/           schema.sql (fresh install) + migrations/ (updates)
│   ├── src/                PHP code (namespace Bwc\)
│   ├── cron/               notifications.php (every 15 min), maintenance.php (nightly)
│   ├── tools/              migrate.php, check.php, seed-demo.php (CLI)
│   └── storage/            photos, logs, backups, cache (created automatically, protected)
└── dev-router.php          local testing only: php -S localhost:8080 -t public_html dev-router.php
```

## Requirements

PHP 8.2+ (8.1 works) with `pdo_mysql`, `curl`, `openssl`, `mbstring` and `gd`, plus MySQL 5.7+/8 or MariaDB 10.3+. No Composer, Node.js or build step.

## Install

1. Upload `public_html/` contents to the domain's web root, and `app/` next to it.
2. `cp app/config.example.php app/config.php`, then fill in: `app_url`, `admin_url`, `photos_dir`, `app_secret`, `db`, `mail` and optionally `google` and `turnstile`. Comments in the file are in French, but each key is described in `HANDOVER.md`, section 4.
3. `php app/tools/migrate.php` creates the tables. It also updates an existing database, after an automatic backup.
4. Add the cron jobs, here only (not in the admin package):
   - `*/15 * * * *  /usr/bin/php /home/uXXX/domains/domain.be/app/cron/notifications.php`
   - `0 3 * * *     /usr/bin/php /home/uXXX/domains/domain.be/app/cron/maintenance.php`
5. `php app/tools/check.php --send-test=you@example.com` must report everything OK.
6. Set `'demo' => false` for production.

## What it does

- Garages pick a day and a free slot. Availability comes from `app/settings.php` (2 pickups per 2-hour slot by default), the bookings in MySQL, and the events VBM adds in Google Calendar (manual appointments take a place, "Fermé" closes a slot).
- A booking is saved inside a MySQL transaction with a per-day lock, so there is no overbooking. It is idempotent, so double clicks never create duplicates. The garage only sees "confirmed" once the booking is stored.
- Then:
  - the photos are stored in `photos_dir`;
  - the confirmation email goes to the garage;
  - the response is sent to the browser;
  - the VBM notification (with the photos attached) is sent in the background.
- The cron job retries failed emails and Google Calendar copies. The nightly job backs up the database to `app/storage/backups` (14 kept) and applies the retention period if one is set.

## Demo / local test

With `'demo' => true`, nothing leaves the server: emails are written to `app/storage/demo-emails` and can be previewed from the confirmation screen, and Google Calendar is off. `php app/tools/seed-demo.php` fills a demo database with fictitious garages, pickups and photos; it refuses to run outside demo mode.
