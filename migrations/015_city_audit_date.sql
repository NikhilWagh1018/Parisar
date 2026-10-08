-- ═══════════════════════════════════════════════════════════════
--  015_city_audit_date.sql
--  A City Audit now has an audit DATE (not just a year). audit_year stays and is
--  always the year of audit_date, so existing screens and the unique key keep working.
--  Addition only: nothing is deleted or rewritten.
--
--  Railway's query console runs ONE statement per query. This file has one.
--  Run this BEFORE deploying the code, then run 016 once.
--
--  Rollback: ALTER TABLE city_audits DROP COLUMN audit_date;
-- ═══════════════════════════════════════════════════════════════

ALTER TABLE city_audits ADD COLUMN audit_date DATE NULL AFTER audit_year;
