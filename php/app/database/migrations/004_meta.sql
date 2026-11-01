-- 004 — Informations de fonctionnement partagées entre le site et
-- l'administration : dernier passage des tâches planifiées, dernière
-- sauvegarde, empreinte des réglages de créneaux.

CREATE TABLE IF NOT EXISTS bwc_meta (
  name       VARCHAR(60)  NOT NULL,
  value      VARCHAR(255) NOT NULL,
  updated_at DATETIME     NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
