-- ═══════════════════════════════════════════════════════════════
--  006_backfill_stuck_active_sessions.sql
--  Session 31 — ALREADY APPLIED to production on 2026-08-13.
--
--  This file is a historical record, not a migration to run again.
--  It is safe to re-run (idempotent — only touches sessions still
--  at status = 'active'), but it should be a no-op if applied a
--  second time against the current production DB.
--
--  ── Bug ──────────────────────────────────────────────────────
--  api/segments/submit.php (the real audit-submission form path)
--  was missing the AuditSessionRepository::autoCompleteIfReady()
--  call that api/segments/complete.php (the manual-completion
--  path) already had. As a result, audit_sessions rows never
--  flipped from 'active' to 'completed' when a road was finished
--  through the normal surveyor workflow — only through the manual
--  completion route, which was rarely used.
--
--  Fixed in application code the same session: submit.php now
--  calls autoCompleteIfReady($sessionId) right after marking the
--  final segment completed, mirroring complete.php exactly.
--
--  ── Backfill ─────────────────────────────────────────────────
--  This script found 39 roads (40 audit_sessions rows, one road
--  had 2 stale sessions) that were already fully audited — every
--  segment for the road was status = 'completed' — but whose
--  audit_sessions row was still stuck at 'active' because the
--  code bug above meant the session was never flipped.
--
--  All 39 rows were reviewed manually (road names + user_ids
--  checked against known real surveyor accounts) before applying,
--  confirmed as genuine historical audit data, not test/duplicate
--  account artifacts.
--
--  Verified after applying: 0 sessions remained active with all
--  segments completed. Dashboard confirmed roads showing
--  "Completed" instead of "Active" post-backfill.
-- ═══════════════════════════════════════════════════════════════

UPDATE audit_sessions a
SET a.status = 'completed',
    a.completed_at = NOW()
WHERE a.status = 'active'
  AND a.road_id IN (
        SELECT road_id FROM (
            SELECT road_id
            FROM segments
            GROUP BY road_id
            HAVING COUNT(*) > 0
               AND COUNT(*) = SUM(status = 'completed')
        ) AS done_roads
  );
