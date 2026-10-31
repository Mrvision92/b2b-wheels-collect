-- B2B Wheels Collect — base de données MySQL / MariaDB
--
-- À importer une seule fois dans phpMyAdmin (onglet « Importer »), dans la
-- base créée depuis hPanel → Bases de données MySQL. Compatible MySQL 5.7+,
-- MySQL 8 et MariaDB 10.3+.

SET NAMES utf8mb4;

-- Une ligne par enlèvement réservé. C'est l'enregistrement de référence :
-- l'événement Google Agenda en est une copie pour le planning de VBM.
CREATE TABLE IF NOT EXISTS bwc_bookings (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference         VARCHAR(13)  NOT NULL COMMENT 'BWC-XXXX-XXXX, communiquée au garage',
  request_hash      CHAR(64)     NOT NULL COMMENT 'Empreinte de la demande : un double envoi ne crée pas de doublon',
  status            ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed'
                    COMMENT 'Passer à cancelled pour libérer le créneau',

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
  email             VARCHAR(254) NOT NULL,
  street            VARCHAR(160) NOT NULL,
  postal_code       CHAR(4)      NOT NULL,
  city              VARCHAR(80)  NOT NULL,
  rim_count         SMALLINT UNSIGNED NOT NULL,
  garage_reference  VARCHAR(60)  NOT NULL DEFAULT '' COMMENT 'Référence propre au garage (facultative)',
  comment           VARCHAR(500) NOT NULL DEFAULT '',
  photo_count       TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Photos jointes à l''email VBM (non conservées)',

  google_event_id   VARCHAR(64)  NOT NULL COMMENT 'Identifiant de l''événement Google Agenda',
  google_status     ENUM('pending','synced','failed','off') NOT NULL DEFAULT 'pending',
  google_link       VARCHAR(500) NULL,
  google_tries      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  google_synced_at  DATETIME(3)  NULL,

  garage_mail       ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  garage_mail_tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
  vbm_mail          ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  vbm_mail_tries    TINYINT UNSIGNED NOT NULL DEFAULT 0,

  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'UTC',

  PRIMARY KEY (id),
  UNIQUE KEY uq_bwc_reference (reference),
  UNIQUE KEY uq_bwc_request (request_hash),
  KEY idx_bwc_slot (starts_at, ends_at, status),
  KEY idx_bwc_followup (garage_mail, vbm_mail, google_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une ligne par jour réservé, verrouillée pendant l'enregistrement d'une
-- réservation : deux garages ne peuvent pas prendre la dernière place d'un
-- créneau au même instant.
CREATE TABLE IF NOT EXISTS bwc_booking_days (
  day DATE NOT NULL,
  PRIMARY KEY (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limite de tentatives par connexion (protection anti-abus).
CREATE TABLE IF NOT EXISTS bwc_rate_limits (
  bucket   VARCHAR(100) NOT NULL,
  hits     INT UNSIGNED NOT NULL,
  reset_at INT UNSIGNED NOT NULL COMMENT 'Horodatage Unix',
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
