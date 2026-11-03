-- ---------------------------------------------------------------
--  029_collaborator_logo_scale.sql
--  Collaborations: how large each logo sits inside its round tile on the
--  home page. The Admin sets it per organisation, from 50 to 150 percent;
--  100 is the automatic fit, so every existing collaborator looks as before.
--  Additions only: nothing existing is changed.
--
--  Railway: MySQL service > Database > Data > Query. Run it BEFORE the code
--  that uses it is deployed. It is one statement.
--
--  Rollback (only after the code that uses it has been rolled back):
--    ALTER TABLE collaborators DROP COLUMN logo_scale;
-- ---------------------------------------------------------------

ALTER TABLE collaborators ADD COLUMN logo_scale SMALLINT NOT NULL DEFAULT 100;
