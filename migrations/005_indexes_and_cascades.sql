-- ═══════════════════════════════════════════════════════════════
--  migrations/005_indexes_and_cascades.sql
--  Adds missing performance indexes and ON DELETE CASCADE FKs.
--  Idempotent — uses IF NOT EXISTS / checks before adding.
--  Issue 13 + 14 from audit.
-- ═══════════════════════════════════════════════════════════════

-- ── Performance indexes (Issue 13) ────────────────────────────

-- segment_audits: most-queried lookup column
ALTER TABLE `segment_audits`
    ADD INDEX IF NOT EXISTS `idx_segment_id`  (`segment_id`),
    ADD INDEX IF NOT EXISTS `idx_session_id`  (`session_id`),
    ADD INDEX IF NOT EXISTS `idx_surveyor_id` (`surveyor_id`);

-- obstructions: joined on audit_id in every score calculation
ALTER TABLE `obstructions`
    ADD INDEX IF NOT EXISTS `idx_audit_id` (`audit_id`);

-- intersections: joined on audit_id in every score calculation
ALTER TABLE `intersections`
    ADD INDEX IF NOT EXISTS `idx_audit_id` (`audit_id`);

-- ── ON DELETE CASCADE foreign keys (Issue 14) ─────────────────
-- Adds FK constraints with CASCADE so child rows are cleaned up
-- automatically when a parent audit is deleted.
-- Each block checks for the FK before adding to stay idempotent.

-- obstructions → segment_audits
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'obstructions'
      AND CONSTRAINT_NAME   = 'fk_obs_audit'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `obstructions`
        ADD CONSTRAINT `fk_obs_audit`
        FOREIGN KEY (`audit_id`) REFERENCES `segment_audits` (`id`) ON DELETE CASCADE',
    'SELECT ''fk_obs_audit already exists — skipping'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- intersections → segment_audits
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'intersections'
      AND CONSTRAINT_NAME   = 'fk_int_audit'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `intersections`
        ADD CONSTRAINT `fk_int_audit`
        FOREIGN KEY (`audit_id`) REFERENCES `segment_audits` (`id`) ON DELETE CASCADE',
    'SELECT ''fk_int_audit already exists — skipping'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
