-- ---------------------------------------------------------------
--  024_past_audits.sql
--  Past audits: audits done before CycleAudit, added by the Admin so they
--  appear on the public Audit Data page next to the live ones. Each keeps its
--  summary figures and, optionally, the PDF report. Deleting one only sets
--  deleted_at (nothing is erased).
--  Additions only: nothing existing is changed.
--
--  Railway: MySQL service > Database > Data > Query. Run it BEFORE the code
--  that uses it is deployed. It is one statement.
--
--  Rollback (only if no past audit has been added):
--    DROP TABLE past_audits;
-- ---------------------------------------------------------------

CREATE TABLE past_audits (
  id              INT NOT NULL AUTO_INCREMENT,
  city_name       VARCHAR(100) NOT NULL,
  state           VARCHAR(100) NOT NULL DEFAULT '',
  name            VARCHAR(150) NOT NULL,
  audit_date      DATE NOT NULL,
  road_count      INT UNSIGNED NOT NULL DEFAULT 0,
  segment_count   INT UNSIGNED NOT NULL DEFAULT 0,
  length_km       DECIMAL(8,1) NOT NULL DEFAULT 0,
  score           DECIMAL(5,2) NULL,
  condition_label VARCHAR(10) NULL,
  report_name     VARCHAR(200) NULL,
  report_data     LONGBLOB NULL,
  created_by      INT NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at      DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_past_audits_live (deleted_at, audit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
