-- ═══════════════════════════════════════════════════════════════
--  migrations/005_performance_indexes.sql
--  Adds missing indexes on the most-queried columns.
--
--  WHY: calculateSegmentScore(), SegmentRepository::latestAudit(),
--  and report queries all filter by segment_id / audit_id.
--  Without indexes these are full table scans — performance
--  degrades noticeably as audit data grows beyond a few hundred rows.
--
--  Run once on Railway MySQL:
--    mysql -h $MYSQLHOST -u $MYSQLUSER -p$MYSQLPASSWORD $MYSQLDATABASE \
--          < migrations/005_performance_indexes.sql
--
--  All statements use IF NOT EXISTS / ignore duplicate-key errors
--  so this is safe to re-run.
-- ═══════════════════════════════════════════════════════════════

-- ── segment_audits ────────────────────────────────────────────
-- Most queried column: segment_id (latestAudit, score calc, reset, unlock)
-- Second most: session_id (report page, export endpoints)
ALTER TABLE segment_audits
    ADD INDEX IF NOT EXISTS idx_sa_segment_id (segment_id),
    ADD INDEX IF NOT EXISTS idx_sa_session_id (session_id),
    ADD INDEX IF NOT EXISTS idx_sa_surveyor_id (surveyor_id);

-- ── obstructions ─────────────────────────────────────────────
-- Every score calculation SUMs obstructions WHERE audit_id = ?
ALTER TABLE obstructions
    ADD INDEX IF NOT EXISTS idx_obs_audit_id (audit_id);

-- ── intersections ─────────────────────────────────────────────
-- Every score calculation fetches intersections WHERE audit_id = ?
ALTER TABLE intersections
    ADD INDEX IF NOT EXISTS idx_int_audit_id (audit_id);

-- ── segments ──────────────────────────────────────────────────
-- Frequently filtered by road_id and status
ALTER TABLE segments
    ADD INDEX IF NOT EXISTS idx_seg_road_id  (road_id),
    ADD INDEX IF NOT EXISTS idx_seg_status   (status);

-- ── audit_sessions ────────────────────────────────────────────
-- Looked up by user_id + status on nearly every protected page
ALTER TABLE audit_sessions
    ADD INDEX IF NOT EXISTS idx_as_user_id   (user_id),
    ADD INDEX IF NOT EXISTS idx_as_road_id   (road_id),
    ADD INDEX IF NOT EXISTS idx_as_status    (status);

-- ── roads ─────────────────────────────────────────────────────
-- Dashboard and segment pages filter by creator_id
ALTER TABLE roads
    ADD INDEX IF NOT EXISTS idx_roads_creator_id (creator_id);
