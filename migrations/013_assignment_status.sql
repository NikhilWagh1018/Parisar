-- ═══════════════════════════════════════════════════════════════
--  013_assignment_status.sql
--  City Audit workflow, Slice 3b: progress of each assigned segment.
--  Additions only: segments.status and all scores are untouched.
--
--    assigned       the surveyor has it to do (shown as "In progress"
--                   once they have started a session on the road)
--    submitted      the surveyor submitted it, waiting for the City Leader
--    needs_revisit  the City Leader sent it back (slice 4)
--    approved       the City Leader approved it (slice 4)
--
--  Existing assignments become 'assigned'. Railway's query console
--  runs ONE statement per query: this file is a single statement.
--
--  Rollback (drops the new columns only):
--    ALTER TABLE segment_assignments
--      DROP INDEX idx_sa_surveyor_status,
--      DROP COLUMN review_note, DROP COLUMN reviewed_by,
--      DROP COLUMN reviewed_at, DROP COLUMN submitted_at, DROP COLUMN status;
-- ═══════════════════════════════════════════════════════════════

ALTER TABLE segment_assignments
  ADD COLUMN status       ENUM('assigned','submitted','needs_revisit','approved') NOT NULL DEFAULT 'assigned' AFTER assigned_by,
  ADD COLUMN submitted_at DATETIME NULL AFTER assigned_at,
  ADD COLUMN reviewed_at  DATETIME NULL AFTER submitted_at,
  ADD COLUMN reviewed_by  INT NULL AFTER reviewed_at,
  ADD COLUMN review_note  VARCHAR(500) NULL AFTER reviewed_by,
  ADD KEY idx_sa_surveyor_status (surveyor_id, status);
