-- ---------------------------------------------------------------
--  028_collaborators.sql
--  Collaborations: the organisations that work with Parisar. The Admin adds,
--  edits, orders, hides and removes them; the home page shows the visible
--  ones in a "Collaborations" section (the section is not shown at all
--  while there is nobody to show). Each can have a logo (stored here, like
--  the past-audit PDFs), a short role label and a website link.
--  Deleting one only sets deleted_at (nothing is erased).
--  Additions only: nothing existing is changed.
--
--  Railway: MySQL service > Database > Data > Query. Run it BEFORE the code
--  that uses it is deployed. It is one statement.
--
--  Rollback (only if no collaborator has been added):
--    DROP TABLE collaborators;
-- ---------------------------------------------------------------

CREATE TABLE collaborators (
  id          INT NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  role_label  VARCHAR(60)  NULL,
  website_url VARCHAR(255) NULL,
  logo_mime   VARCHAR(30)  NULL,
  logo_data   MEDIUMBLOB   NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_visible  TINYINT(1) NOT NULL DEFAULT 1,
  created_by  INT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at  DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_collaborators_live (deleted_at, is_visible, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
