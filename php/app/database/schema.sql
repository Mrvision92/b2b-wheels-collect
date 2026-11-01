-- B2B Wheels Collect — base de données MySQL / MariaDB (installation neuve)
--
-- À importer une seule fois dans phpMyAdmin (onglet « Importer »), dans la
-- base créée depuis hPanel → Bases de données MySQL. Compatible MySQL 5.7+,
-- MySQL 8 et MariaDB 10.3+.
--
-- Une installation existante se met à jour avec les fichiers du dossier
-- migrations/ (php app/tools/migrate.php), jamais en réimportant ce fichier.

SET NAMES utf8mb4;

-- Annuaire des garages clients, alimenté automatiquement par les réservations.
CREATE TABLE IF NOT EXISTS bwc_garages (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  contact_name  VARCHAR(120) NOT NULL DEFAULT '',
  phone         VARCHAR(20)  NOT NULL DEFAULT '',
  email         VARCHAR(254) NOT NULL DEFAULT '' COMMENT 'Sert à reconnaître le garage lors des réservations suivantes',
  street        VARCHAR(160) NOT NULL DEFAULT '',
  postal_code   VARCHAR(4)   NOT NULL DEFAULT '',
  city          VARCHAR(80)  NOT NULL DEFAULT '',
  notes         VARCHAR(2000) NOT NULL DEFAULT '' COMMENT 'Notes internes de VBM',
  created_at    DATETIME     NOT NULL COMMENT 'UTC',
  updated_at    DATETIME     NOT NULL COMMENT 'UTC',
  PRIMARY KEY (id),
  KEY idx_bwc_garages_email (email),
  KEY idx_bwc_garages_phone (phone),
  KEY idx_bwc_garages_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Administrateurs VBM (aucun compte n'est créé ici : voir app/tools/admin.php).
CREATE TABLE IF NOT EXISTS bwc_admins (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email               VARCHAR(254) NOT NULL,
  name                VARCHAR(120) NOT NULL,
  password_hash       VARCHAR(255) NULL COMMENT 'NULL tant que le mot de passe n''a pas été choisi',
  active              TINYINT(1)   NOT NULL DEFAULT 1,
  reset_token_hash    CHAR(64)     NULL COMMENT 'Empreinte du lien de choix du mot de passe',
  reset_expires_at    DATETIME     NULL,
  password_changed_at DATETIME(6)  NULL COMMENT 'Change à chaque nouveau mot de passe : ferme les autres sessions',
  last_login_at       DATETIME     NULL,
  created_at          DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bwc_admins_email (email),
  UNIQUE KEY uq_bwc_admins_reset (reset_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une ligne par enlèvement, qu'il vienne du site public ou de l'administration.
-- C'est l'enregistrement de référence : Google Agenda n'en est qu'une copie.
CREATE TABLE IF NOT EXISTS bwc_bookings (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference         VARCHAR(13)  NOT NULL COMMENT 'BWC-XXXX-XXXX, communiquée au garage',
  request_hash      CHAR(64)     NOT NULL COMMENT 'Empreinte de la demande : un double envoi ne crée pas de doublon',
  garage_id         INT UNSIGNED NULL,
  source            ENUM('public','admin') NOT NULL DEFAULT 'public',
  status            ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'confirmed'
                    COMMENT 'pending : en attente, confirmed : à enlever, completed : enlevé, cancelled : annulé',

  slot_date         DATE         NOT NULL COMMENT 'Date de l''enlèvement (heure de Bruxelles)',
  slot_key          CHAR(4)      NOT NULL COMMENT 'Début du créneau, ex. 0900',
  start_time        CHAR(5)      NOT NULL COMMENT 'Heure de Bruxelles, ex. 09:00',
  end_time          CHAR(5)      NOT NULL COMMENT 'Heure de Bruxelles, ex. 11:00',
  starts_at         DATETIME     NOT NULL COMMENT 'Début en UTC',
  ends_at           DATETIME     NOT NULL COMMENT 'Fin en UTC',
  slot_mode         ENUM('ranges','times') NOT NULL DEFAULT 'ranges',

  garage_name       VARCHAR(120) NOT NULL,
  contact_name      VARCHAR(120) NOT NULL,
  phone             VARCHAR(20)  NOT NULL COMMENT 'Format international, ex. +32470123456',
  email             VARCHAR(254) NOT NULL DEFAULT '' COMMENT 'Vide possible pour un enlèvement saisi par VBM',
  street            VARCHAR(160) NOT NULL,
  postal_code       CHAR(4)      NOT NULL,
  city              VARCHAR(80)  NOT NULL,
  rim_count         SMALLINT UNSIGNED NOT NULL,
  garage_reference  VARCHAR(60)  NOT NULL DEFAULT '' COMMENT 'Référence propre au garage (facultative)',
  comment           VARCHAR(500) NOT NULL DEFAULT '',
  internal_note     VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'Note interne de VBM, jamais envoyée au garage',
  photo_count       TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Photos jointes à l''email VBM (non conservées)',

  google_event_id   VARCHAR(64)  NOT NULL COMMENT 'Identifiant de l''événement Google Agenda',
  google_status     ENUM('pending','synced','failed','off') NOT NULL DEFAULT 'pending',
  google_link       VARCHAR(500) NULL,
  google_tries      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  google_synced_at  DATETIME(3)  NULL,

  created_by        INT UNSIGNED NULL COMMENT 'Administrateur, pour un enlèvement saisi par VBM',
  completed_at      DATETIME     NULL COMMENT 'Enlèvement réellement effectué (UTC)',
  completed_by      INT UNSIGNED NULL,
  cancelled_at      DATETIME     NULL,
  cancelled_by      INT UNSIGNED NULL,
  cancel_reason     VARCHAR(255) NOT NULL DEFAULT '',
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'UTC',

  PRIMARY KEY (id),
  UNIQUE KEY uq_bwc_reference (reference),
  UNIQUE KEY uq_bwc_request (request_hash),
  KEY idx_bwc_slot (starts_at, ends_at, status),
  KEY idx_bwc_date_status (slot_date, status),
  KEY idx_bwc_completed (completed_at),
  KEY idx_bwc_garage (garage_id, slot_date),
  KEY idx_bwc_google (google_status, created_at),
  CONSTRAINT fk_bwc_bookings_garage FOREIGN KEY (garage_id) REFERENCES bwc_garages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique de chaque réservation : création, changements de statut, de
-- créneau ou d'informations, avec l'administrateur concerné.
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

-- Emails liés aux réservations : un email par événement (clé unique), avec
-- son état, le nombre d'essais et la réponse du service d'envoi.
CREATE TABLE IF NOT EXISTS bwc_notifications (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id        INT UNSIGNED NOT NULL,
  kind              VARCHAR(40)  NOT NULL COMMENT 'garage_confirmation, vbm_notification, pickup_completed, booking_rescheduled, booking_cancelled',
  recipients        VARCHAR(1000) NOT NULL DEFAULT '',
  status            ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  idempotency_key   VARCHAR(150) NOT NULL COMMENT 'Le même événement ne produit jamais deux emails',
  provider_response VARCHAR(255) NULL COMMENT 'Identifiant ou réponse du service d''envoi',
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

-- Photos jointes par les garages, visibles dans l'administration. Les
-- fichiers sont dans app/storage/photos/<réservation>/, hors de public_html.
CREATE TABLE IF NOT EXISTS bwc_booking_photos (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id   INT UNSIGNED NOT NULL,
  position     TINYINT UNSIGNED NOT NULL COMMENT '1 à 5, dans l''ordre choisi par le garage',
  file_name    VARCHAR(40)  NOT NULL COMMENT 'ex. photo-1.jpg, dans app/storage/photos/<booking_id>/',
  content_type VARCHAR(30)  NOT NULL,
  size_bytes   INT UNSIGNED NOT NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bwc_photo_position (booking_id, position),
  CONSTRAINT fk_bwc_photos_booking FOREIGN KEY (booking_id) REFERENCES bwc_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une ligne par jour réservé, verrouillée pendant l'enregistrement d'une
-- réservation : deux garages ne peuvent pas prendre la dernière place d'un
-- créneau au même instant.
CREATE TABLE IF NOT EXISTS bwc_booking_days (
  day DATE NOT NULL,
  PRIMARY KEY (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limite de tentatives par connexion (réservations, connexion à l'administration).
CREATE TABLE IF NOT EXISTS bwc_rate_limits (
  bucket   VARCHAR(100) NOT NULL,
  hits     INT UNSIGNED NOT NULL,
  reset_at INT UNSIGNED NOT NULL COMMENT 'Horodatage Unix',
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mises à jour de la base déjà appliquées.
CREATE TABLE IF NOT EXISTS bwc_migrations (
  name       VARCHAR(100) NOT NULL,
  applied_at DATETIME     NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO bwc_migrations (name, applied_at) VALUES ('001_admin_panel', UTC_TIMESTAMP()), ('002_booking_photos', UTC_TIMESTAMP()), ('003_internal_notes', UTC_TIMESTAMP());
