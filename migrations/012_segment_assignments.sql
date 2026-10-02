-- ═══════════════════════════════════════════════════════════════
--  012_segment_assignments.sql
--  City Audit workflow, Slice 3: assign surveyors to segments.
--  Additions only: nothing existing is changed or deleted.
--  One surveyor per segment (UNIQUE on segment_id). Assignments are
--  removed automatically if their segment is deleted.
--
--  BEFORE running, check the type of segments.id (and keep users.id as is):
--      SHOW COLUMNS FROM segments LIKE 'id';
--  If it says "int"           -> run the statement below as written.
--  If it says "int unsigned"  -> change the line  segment_id INT NOT NULL
--                                to               segment_id INT UNSIGNED NOT NULL
--
--  Railway's query console runs ONE statement per query.
--
--  Rollback (only if no segment has been assigned yet):
--    DROP TABLE segment_assignments;
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE segment_assignments (
  id          INT NOT NULL AUTO_INCREMENT,
  audit_id    INT NOT NULL,
  segment_id  INT NOT NULL,
  surveyor_id INT NOT NULL,
  assigned_by INT NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_segment_assignment_segment (segment_id),
  KEY idx_segment_assignments_audit (audit_id),
  KEY idx_segment_assignments_surveyor (surveyor_id),
  CONSTRAINT fk_sa_audit       FOREIGN KEY (audit_id)    REFERENCES city_audits (id),
  CONSTRAINT fk_sa_segment     FOREIGN KEY (segment_id)  REFERENCES segments (id) ON DELETE CASCADE,
  CONSTRAINT fk_sa_surveyor    FOREIGN KEY (surveyor_id) REFERENCES users (id),
  CONSTRAINT fk_sa_assigned_by FOREIGN KEY (assigned_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
