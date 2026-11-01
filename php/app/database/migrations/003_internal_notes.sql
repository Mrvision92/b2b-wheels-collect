-- 003 — Note interne de VBM sur chaque enlèvement (jamais montrée au garage).

ALTER TABLE bwc_bookings
  ADD COLUMN internal_note VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'Note interne de VBM, jamais envoyée au garage' AFTER comment
