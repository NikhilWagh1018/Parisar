-- ═══════════════════════════════════════════════════════════════
--  014_audit_admin_decision.sql
--  Admin approval of a City Leader's audit. Stores the Admin's note when
--  an audit is returned for changes, and who decided and when.
--  Additions only: nothing is deleted or rewritten.
--
--  Railway's query console runs ONE statement per query. This file has one.
--
--  Rollback (only if you no longer need the notes):
--    ALTER TABLE city_audits DROP COLUMN admin_note, DROP COLUMN admin_decided_at, DROP COLUMN admin_decided_by;
-- ═══════════════════════════════════════════════════════════════

ALTER TABLE city_audits
  ADD COLUMN admin_note       TEXT NULL,
  ADD COLUMN admin_decided_at DATETIME NULL,
  ADD COLUMN admin_decided_by INT NULL;
