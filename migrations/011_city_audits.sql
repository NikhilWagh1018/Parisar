-- ═══════════════════════════════════════════════════════════════
--  011_city_audits.sql
--  City Audit entity (workflow steps 2-4). Additions only: nothing is
--  deleted or rewritten. Existing roads keep audit_id = NULL (they
--  pre-date audits and stay exactly as they are).
--
--  Railway's query console runs ONE statement per query, so run the two
--  statements below separately, in order.
--
--  Rollback (only if no audit has been created yet), in this order:
--    ALTER TABLE roads DROP FOREIGN KEY fk_roads_audit;
--    ALTER TABLE roads DROP INDEX uq_roads_audit_group, DROP COLUMN audit_id;
--    DROP TABLE city_audits;
-- ═══════════════════════════════════════════════════════════════

-- 1 of 2 ------------------------------------------------------
CREATE TABLE city_audits (
  id             INT NOT NULL AUTO_INCREMENT,
  city_id        INT NOT NULL,
  state          VARCHAR(100) NOT NULL,
  name           VARCHAR(150) NOT NULL,
  audit_year     SMALLINT UNSIGNED NOT NULL,
  programme_info TEXT NULL,
  status         ENUM('draft','active','in_review','finalised','awaiting_approval','published','voided') NOT NULL DEFAULT 'draft',
  created_by     INT NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_city_audit_name (city_id, audit_year, name),
  KEY idx_city_audits_city_status (city_id, status),
  CONSTRAINT fk_city_audits_city    FOREIGN KEY (city_id)    REFERENCES cities (id),
  CONSTRAINT fk_city_audits_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2 of 2 ------------------------------------------------------
ALTER TABLE roads
  ADD COLUMN audit_id INT NULL AFTER road_group_id,
  ADD UNIQUE KEY uq_roads_audit_group (audit_id, road_group_id),
  ADD CONSTRAINT fk_roads_audit FOREIGN KEY (audit_id) REFERENCES city_audits (id);
