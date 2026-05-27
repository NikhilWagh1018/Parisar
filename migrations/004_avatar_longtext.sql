-- ═══════════════════════════════════════════════════════════════
--  migrations/004_avatar_longtext.sql
--  Increases profile_picture column to LONGTEXT so it can hold
--  base64-encoded images (up to 4MB) stored directly in the DB.
--  Railway filesystem is ephemeral, so DB storage is intentional.
--
--  Run once on Railway MySQL:
--    Railway Dashboard → MySQL → Database → Data → Query
--    paste and run this file
-- ═══════════════════════════════════════════════════════════════

ALTER TABLE users
  MODIFY COLUMN profile_picture LONGTEXT NULL DEFAULT NULL;
