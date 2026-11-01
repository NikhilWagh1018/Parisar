-- ---------------------------------------------------------------
--  025_audit_public_hidden.sql
--  "Unpublish" now only HIDES a published audit from the public home page.
--  The audit stays approved (status 'published'), so it does not come back to
--  the Admin as something to approve. NULL = shown on the home page.
--  Additions only: nothing existing is changed.
--
--  Railway: MySQL service > Database > Data > Query. Run it BEFORE the code
--  that uses it is deployed. It is one statement.
--
--  Rollback: ALTER TABLE city_audits DROP COLUMN public_hidden_at;
-- ---------------------------------------------------------------

ALTER TABLE city_audits ADD COLUMN public_hidden_at DATETIME NULL;
