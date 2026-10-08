-- ═══════════════════════════════════════════════════════════════
--  016_city_audit_date_backfill.sql
--  Gives existing audits a date: the day they were created when that falls in
--  their audit year, otherwise 1 January of the audit year.
--  Only fills empty dates, so it is safe to run twice.
--
--  Railway's query console runs ONE statement per query. This file has one.
--  Run after 015.
-- ═══════════════════════════════════════════════════════════════

UPDATE city_audits
   SET audit_date = CASE WHEN YEAR(created_at) = audit_year THEN DATE(created_at)
                         ELSE MAKEDATE(audit_year, 1) END
 WHERE audit_date IS NULL;
