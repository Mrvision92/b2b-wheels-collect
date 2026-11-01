-- 001 — Administration : garages, statuts, historique, emails, administrateurs.
--
-- Met à jour une base créée avec la première version (réservations seules).
-- S'applique avec : php app/tools/migrate.php (une sauvegarde est faite avant).
-- Les réservations existantes sont conservées : chaque garage est créé à
-- partir de ses réservations et chaque email garde son état.

CREATE TABLE IF NOT EXISTS bwc_garages (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  contact_name  VARCHAR(120) NOT NULL DEFAULT '',
  phone         VARCHAR(20)  NOT NULL DEFAULT '',
  email         VARCHAR(254) NOT NULL DEFAULT '',
  street        VARCHAR(160) NOT NULL DEFAULT '',
  postal_code   VARCHAR(4)   NOT NULL DEFAULT '',
  city          VARCHAR(80)  NOT NULL DEFAULT '',
  notes         VARCHAR(2000) NOT NULL DEFAULT '',
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_bwc_garages_email (email),
  KEY idx_bwc_garages_phone (phone),
  KEY idx_bwc_garages_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bwc_admins (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email               VARCHAR(254) NOT NULL,
  name                VARCHAR(120) NOT NULL,
  password_hash       VARCHAR(255) NULL,
  active              TINYINT(1)   NOT NULL DEFAULT 1,
  reset_token_hash    CHAR(64)     NULL,
  reset_expires_at    DATETIME     NULL,
  password_changed_at DATETIME(6)  NULL COMMENT 'Change à chaque nouveau mot de passe : ferme les autres sessions',
  last_login_at       DATETIME     NULL,
  created_at          DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bwc_admins_email (email),
  UNIQUE KEY uq_bwc_admins_reset (reset_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bwc_bookings
  MODIFY status ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'confirmed',
  MODIFY email VARCHAR(254) NOT NULL DEFAULT '',
  ADD COLUMN garage_id INT UNSIGNED NULL AFTER request_hash,
  ADD COLUMN source ENUM('public','admin') NOT NULL DEFAULT 'public' AFTER garage_id,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER google_synced_at,
  ADD COLUMN completed_at DATETIME NULL AFTER created_by,
  ADD COLUMN completed_by INT UNSIGNED NULL AFTER completed_at,
  ADD COLUMN cancelled_at DATETIME NULL AFTER completed_by,
  ADD COLUMN cancelled_by INT UNSIGNED NULL AFTER cancelled_at,
  ADD COLUMN cancel_reason VARCHAR(255) NOT NULL DEFAULT '' AFTER cancelled_by,
  ADD KEY idx_bwc_date_status (slot_date, status),
  ADD KEY idx_bwc_completed (completed_at),
  ADD KEY idx_bwc_garage (garage_id, slot_date),
  ADD KEY idx_bwc_google (google_status, created_at);

INSERT INTO bwc_garages (name, contact_name, phone, email, street, postal_code, city, created_at, updated_at)
SELECT b.garage_name, b.contact_name, b.phone, LOWER(b.email), b.street, b.postal_code, b.city, first.created_at, b.created_at
FROM bwc_bookings b
JOIN (SELECT LOWER(email) AS email_key, MAX(id) AS last_id, MIN(created_at) AS created_at FROM bwc_bookings GROUP BY LOWER(email)) first
  ON first.last_id = b.id;

UPDATE bwc_bookings b JOIN bwc_garages g ON g.email = LOWER(b.email) SET b.garage_id = g.id WHERE b.garage_id IS NULL;

ALTER TABLE bwc_bookings
  ADD CONSTRAINT fk_bwc_bookings_garage FOREIGN KEY (garage_id) REFERENCES bwc_garages (id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS bwc_booking_history (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id  INT UNSIGNED NOT NULL,
  action      VARCHAR(40)  NOT NULL,
  from_status VARCHAR(12)  NULL,
  to_status   VARCHAR(12)  NULL,
  details     VARCHAR(1000) NOT NULL DEFAULT '',
  admin_id    INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_bwc_history_booking (booking_id, id),
  CONSTRAINT fk_bwc_history_booking FOREIGN KEY (booking_id) REFERENCES bwc_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO bwc_booking_history (booking_id, action, from_status, to_status, details, created_at)
SELECT id, 'created', NULL, status, 'Réservation reçue sur le site', created_at FROM bwc_bookings;

CREATE TABLE IF NOT EXISTS bwc_notifications (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id        INT UNSIGNED NOT NULL,
  kind              VARCHAR(40)  NOT NULL,
  recipients        VARCHAR(1000) NOT NULL DEFAULT '',
  status            ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  idempotency_key   VARCHAR(150) NOT NULL,
  provider_response VARCHAR(255) NULL,
  last_error        VARCHAR(500) NULL,
  admin_id          INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NOT NULL,
  sent_at           DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bwc_notification_key (idempotency_key),
  KEY idx_bwc_notification_booking (booking_id),
  KEY idx_bwc_notification_pending (status, created_at),
  CONSTRAINT fk_bwc_notifications_booking FOREIGN KEY (booking_id) REFERENCES bwc_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO bwc_notifications (booking_id, kind, recipients, status, attempts, idempotency_key, created_at, updated_at, sent_at)
SELECT id, 'garage_confirmation', email, garage_mail, garage_mail_tries, CONCAT('bwc/garage-confirmation/', reference), created_at, updated_at,
       IF(garage_mail = 'sent', updated_at, NULL)
FROM bwc_bookings;

INSERT INTO bwc_notifications (booking_id, kind, recipients, status, attempts, idempotency_key, created_at, updated_at, sent_at)
SELECT id, 'vbm_notification', '', vbm_mail, vbm_mail_tries, CONCAT('bwc/vbm-notification/', reference), created_at, updated_at,
       IF(vbm_mail = 'sent', updated_at, NULL)
FROM bwc_bookings;

ALTER TABLE bwc_bookings
  DROP INDEX idx_bwc_followup,
  DROP COLUMN garage_mail,
  DROP COLUMN garage_mail_tries,
  DROP COLUMN vbm_mail,
  DROP COLUMN vbm_mail_tries;

CREATE TABLE IF NOT EXISTS bwc_migrations (
  name       VARCHAR(100) NOT NULL,
  applied_at DATETIME     NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
