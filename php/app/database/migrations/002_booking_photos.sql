-- 002 — Photos des réservations, visibles dans l'administration.
--
-- Les fichiers sont rangés dans app/storage/photos/<réservation>/, hors de
-- public_html ; cette table en garde la liste. Les réservations passées
-- n'ont pas de photo enregistrée : elles avaient seulement été jointes à
-- l'email envoyé à VBM.

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
