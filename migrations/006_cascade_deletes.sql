-- ═══════════════════════════════════════════════════════════════
--  migrations/006_cascade_deletes.sql
--  Adds ON DELETE CASCADE to foreign keys on child tables so that
--  deleting a segment_audit automatically removes its obstructions
--  and intersections — no manual cleanup code needed.
--
--  WHY: SegmentRepository::deleteAuditData() currently deletes
--  obstructions and intersections manually because it cannot rely
--  on cascade deletes being set. This migration fixes the root cause.
--
--  SAFE TO RUN: Uses DROP/ADD pattern which replaces the constraint.
--  No data is lost — CASCADE only affects future deletes.
--
--  Run once on Railway MySQL:
--    mysql -h $MYSQLHOST -u $MYSQLUSER -p$MYSQLPASSWORD $MYSQLDATABASE \
--          < migrations/006_cascade_deletes.sql
-- ═══════════════════════════════════════════════════════════════

-- ── Step 1: Drop existing foreign keys (names may vary) ───────
-- Wrap in a stored procedure so we can check existence before dropping
-- and avoid errors if the constraint doesn't exist yet.

-- obstructions → segment_audits
ALTER TABLE `obstructions`
    DROP FOREIGN KEY IF EXISTS `fk_obs_audit`;

ALTER TABLE `obstructions`
    ADD CONSTRAINT `fk_obs_audit`
    FOREIGN KEY (`audit_id`) REFERENCES `segment_audits` (`id`)
    ON DELETE CASCADE;

-- intersections → segment_audits
ALTER TABLE `intersections`
    DROP FOREIGN KEY IF EXISTS `fk_int_audit`;

ALTER TABLE `intersections`
    ADD CONSTRAINT `fk_int_audit`
    FOREIGN KEY (`audit_id`) REFERENCES `segment_audits` (`id`)
    ON DELETE CASCADE;

-- segment_audits → segments
ALTER TABLE `segment_audits`
    DROP FOREIGN KEY IF EXISTS `fk_sa_segment`;

ALTER TABLE `segment_audits`
    ADD CONSTRAINT `fk_sa_segment`
    FOREIGN KEY (`segment_id`) REFERENCES `segments` (`id`)
    ON DELETE CASCADE;

-- segment_audits → audit_sessions
ALTER TABLE `segment_audits`
    DROP FOREIGN KEY IF EXISTS `fk_sa_session`;

ALTER TABLE `segment_audits`
    ADD CONSTRAINT `fk_sa_session`
    FOREIGN KEY (`session_id`) REFERENCES `audit_sessions` (`id`)
    ON DELETE CASCADE;

-- segments → roads
ALTER TABLE `segments`
    DROP FOREIGN KEY IF EXISTS `fk_segments_road`;

ALTER TABLE `segments`
    ADD CONSTRAINT `fk_segments_road`
    FOREIGN KEY (`road_id`) REFERENCES `roads` (`id`)
    ON DELETE CASCADE;

-- audit_sessions → roads
ALTER TABLE `audit_sessions`
    DROP FOREIGN KEY IF EXISTS `fk_sessions_road`;

ALTER TABLE `audit_sessions`
    ADD CONSTRAINT `fk_sessions_road`
    FOREIGN KEY (`road_id`) REFERENCES `roads` (`id`)
    ON DELETE CASCADE;
