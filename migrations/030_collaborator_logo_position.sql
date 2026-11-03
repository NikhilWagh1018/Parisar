-- ---------------------------------------------------------------
--  030_collaborator_logo_position.sql
--  Collaborations: where each logo sits inside its round tile. The Admin
--  drags the logo in the preview circle; the position is kept as two
--  percentages of the tile (x: left/right, y: up/down), -50 to 50. 0 and 0
--  is the centre, so every existing collaborator looks exactly as before.
--  Additions only: nothing existing is changed.
--
--  Railway: MySQL service > Database > Data > Query. Run it BEFORE the code
--  that uses it is deployed. It is ONE statement (the box accepts only one).
--
--  Rollback (only after the code that uses it has been rolled back):
--    ALTER TABLE collaborators DROP COLUMN logo_x, DROP COLUMN logo_y;
-- ---------------------------------------------------------------

ALTER TABLE collaborators ADD COLUMN logo_x SMALLINT NOT NULL DEFAULT 0, ADD COLUMN logo_y SMALLINT NOT NULL DEFAULT 0;
