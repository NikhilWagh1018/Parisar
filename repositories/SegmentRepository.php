<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/SegmentRepository.php
//  Centralises all segment + segment_audit SQL.
//  Eliminates duplicated ownership/status queries spread across:
//    api/segments/submit.php, reset.php, unlock.php,
//    audit-data.php, complete.php,
//    api/roads/segments/index.php, save.php
// ═══════════════════════════════════════════════════════════════

class SegmentRepository
{
    public function __construct(private PDO $pdo) {}

    // ══════════════════════════════════════════════════════════
    //  SEGMENT READS
    // ══════════════════════════════════════════════════════════

    /**
     * Fetch a single segment row joined with its road's creator_id.
     * Returns null if not found.
     *
     * @return array{id:int,road_id:int,status:string,creator_id:int}|null
     */
    public function findWithRoad(int $segmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.status, s.road_id, r.creator_id, r.finalized_at
               FROM segments s
               JOIN roads r ON r.id = s.road_id
              WHERE s.id = ?
              LIMIT 1'
        );
        $stmt->execute([$segmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Fetch a single segment row joined with road — using a FOR UPDATE
     * lock (call inside a transaction to prevent duplicate submissions).
     *
     * @return array{id:int,road_id:int,status:string,creator_id:int}|null
     */
    public function findWithRoadForUpdate(int $segmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.status, s.road_id, r.creator_id, r.finalized_at
               FROM segments s
               JOIN roads r ON r.id = s.road_id
              WHERE s.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$segmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Return all segments for a road, ordered by segment_number.
     *
     * @return list<array<string,mixed>>
     */
    public function allForRoad(int $roadId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, segment_number,
                    start_label, end_label, length, status, completed_at
               FROM segments
              WHERE road_id = ?
              ORDER BY segment_number ASC'
        );
        $stmt->execute([$roadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Verify that a segment belongs to a given road.
     */
    public function belongsToRoad(int $segmentId, int $roadId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM segments WHERE id = ? AND road_id = ? LIMIT 1'
        );
        $stmt->execute([$segmentId, $roadId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Count total segments for a road.
     */
    public function countForRoad(int $roadId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM segments WHERE road_id = ?'
        );
        $stmt->execute([$roadId]);
        return (int)$stmt->fetchColumn();
    }

    // ══════════════════════════════════════════════════════════
    //  SEGMENT WRITES
    // ══════════════════════════════════════════════════════════

    /**
     * Mark a segment as completed.
     * Returns true if the row was actually updated (wasn't already completed).
     * Uses CURRENT_TIMESTAMP (works in both MySQL and SQLite).
     */
    public function markCompleted(int $segmentId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE segments
                SET status = 'completed', completed_at = CURRENT_TIMESTAMP
              WHERE id = ? AND status != 'completed'"
        );
        $stmt->execute([$segmentId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reset a segment back to pending (clears completed_at).
     * Audit data is NOT touched — use deleteAuditData() for that.
     */
    public function resetToPending(int $segmentId): void
    {
        $this->pdo->prepare(
            "UPDATE segments
                SET status = 'pending', completed_at = NULL
              WHERE id = ?"
        )->execute([$segmentId]);
    }

    /**
     * Delete all segments for a road (cascade handles child rows).
     */
    public function deleteForRoad(int $roadId): void
    {
        $this->pdo->prepare(
            'DELETE FROM segments WHERE road_id = ?'
        )->execute([$roadId]);
    }

    // ══════════════════════════════════════════════════════════
    //  AUDIT SESSION OWNERSHIP
    // ══════════════════════════════════════════════════════════

    /**
     * Find an active audit session owned by $userId.
     * Returns null if not found or not active.
     *
     * @return array{id:int,road_id:int}|null
     */
    public function findActiveSession(int $sessionId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, road_id
               FROM audit_sessions
              WHERE id = ? AND user_id = ? AND status = 'active'
              LIMIT 1"
        );
        $stmt->execute([$sessionId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Find any audit session (any status) owned by $userId.
     *
     * @return array{id:int,road_id:int}|null
     */
    public function findSession(int $sessionId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, road_id
               FROM audit_sessions
              WHERE id = ? AND user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$sessionId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Re-open the most recent completed session for a road + user.
     * Called after a segment is reset or unlocked.
     */
    public function reopenCompletedSession(int $roadId, int $userId): void
    {
        $this->pdo->prepare(
            "UPDATE audit_sessions
                SET status = 'active', completed_at = NULL
              WHERE road_id = ?
                AND user_id = ?
                AND status  = 'completed'
              ORDER BY id DESC
              LIMIT 1"
        )->execute([$roadId, $userId]);
    }

    // ══════════════════════════════════════════════════════════
    //  SEGMENT AUDIT READS
    // ══════════════════════════════════════════════════════════

    /**
     * Fetch the most recent segment_audit row for a segment.
     * Returns null if no audit exists yet.
     *
     * @return array<string,mixed>|null
     */
    public function latestAudit(int $segmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, session_id,
                    start_landmark, end_landmark, gps_start, gps_end,
                    cycle_track_missing, missing_length, cyclist_use, better_surface,
                    surface_material, people_walking, signage_count, shade,
                    light_after_sunset, track_geometry, buffer_zone,
                    segment_width, segment_length, comments,
                    surface_issues, overhead_issues, footpath_rating, footpath_score,
                    public_id, surveyor_id
               FROM segment_audits
              WHERE segment_id = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$segmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        // Decode JSON multi-select columns so callers get arrays, not strings.
        $row['surface_issues']  = json_decode($row['surface_issues']  ?? '[]', true) ?? [];
        $row['overhead_issues'] = json_decode($row['overhead_issues'] ?? '[]', true) ?? [];
        $row['footpath_rating'] = json_decode($row['footpath_rating'] ?? '[]', true) ?? [];

        return $row;
    }

    /**
     * Return all audit IDs for a segment (used before bulk-delete).
     *
     * @return int[]
     */
    public function auditIdsForSegment(int $segmentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM segment_audits WHERE segment_id = ?'
        );
        $stmt->execute([$segmentId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Fetch obstructions for a given audit, keyed for the edit form.
     *
     * @return list<array{category:string,type:string,slowed:int,partial:int,total:int}>
     */
    public function obstructionsForAudit(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT obstruction_category AS category,
                    obstruction_type     AS type,
                    cyclist_slowed       AS slowed,
                    partial_obstructions AS partial,
                    total_obstructions   AS total
               FROM obstructions
              WHERE audit_id = ?'
        );
        $stmt->execute([$auditId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $o): array {
            $o['slowed']  = (int)$o['slowed'];
            $o['partial'] = (int)$o['partial'];
            $o['total']   = (int)$o['total'];
            return $o;
        }, $rows);
    }

    /**
     * Fetch intersections for a given audit, ordered by intersection_num.
     *
     * @return list<array<string,mixed>>
     */
    public function intersectionsForAudit(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT intersection_num, gps_coords, landmark_name,
                    off_ramp, on_ramp, markings, signage,
                    traffic_calming, discontinuity, tapering, obstruction_type
               FROM intersections
              WHERE audit_id = ?
              ORDER BY intersection_num ASC'
        );
        $stmt->execute([$auditId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Personal audit summary stats for one surveyor, used on the
     * "My Audits" history page header strip.
     *
     * Aggregates over the MOST RECENT audit per segment only — a segment
     * can legally be re-audited (no unique constraint on segment_id in
     * segment_audits), so a naive SUM/COUNT across all rows would
     * double-count re-audited segments in both the segment count and
     * the distance total.
     *
     * @return array{segments_audited:int, total_length_m:float, roads_touched:int, first_audit_at:?string}
     */
    public function personalStats(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                 COUNT(DISTINCT latest.segment_id) AS segments_audited,
                 SUM(s.length)                      AS total_length_m,
                 COUNT(DISTINCT s.road_id)          AS roads_touched,
                 MIN(latest.audited_at)             AS first_audit_at
             FROM (
                 SELECT segment_id, MAX(id) AS latest_audit_id
                   FROM segment_audits
                  WHERE surveyor_id = ?
                  GROUP BY segment_id
             ) latest_ids
             JOIN segment_audits latest ON latest.id = latest_ids.latest_audit_id
             JOIN segments s ON s.id = latest.segment_id'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'segments_audited' => (int)($row['segments_audited'] ?? 0),
            'total_length_m'   => (float)($row['total_length_m'] ?? 0),
            'roads_touched'    => (int)($row['roads_touched'] ?? 0),
            'first_audit_at'   => $row['first_audit_at'] ?? null,
        ];
    }

    /**
     * Fetch every segment this user has audited (latest audit per segment
     * only — same de-dup rule as personalStats()), joined with road name
     * and that road's audit-session status for this user.
     *
     * Filtering by date range, sorting by score, and pagination all
     * happen in PHP on the caller side (api/user/audit_history_list.php)
     * because condition score isn't a stored column — see ScoreService's
     * calculateScoresForAuditIds().
     *
     * @return list<array<string,mixed>>
     */
    public function personalAuditList(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                 latest.id              AS audit_id,
                 latest.segment_id,
                 latest.segment_width,
                 s.length               AS segment_length,
                 latest.audited_at      AS created_at,
                 s.segment_number,
                 s.road_id,
                 r.name                 AS road_name,
                 sess.status            AS session_status
             FROM (
                 SELECT segment_id, MAX(id) AS latest_audit_id
                   FROM segment_audits
                  WHERE surveyor_id = ?
                  GROUP BY segment_id
             ) latest_ids
             JOIN segment_audits latest ON latest.id = latest_ids.latest_audit_id
             JOIN segments s ON s.id = latest.segment_id
             JOIN roads r    ON r.id = s.road_id
             LEFT JOIN audit_sessions sess
                    ON sess.id = (
                         SELECT id FROM audit_sessions
                          WHERE road_id = s.road_id AND user_id = ?
                          ORDER BY id DESC
                          LIMIT 1
                       )
             ORDER BY latest.audited_at DESC'
        );
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Audited segments that have a captured GPS start point, for the Map
     * View (Visibility & Motivation roadmap item #2). One row per segment,
     * using that segment's latest audit for GPS + label data.
     * segments.status ('pending'|'completed') drives pin color client-side.
     *
     * @param int|null $userId Pass a user id to scope to just that
     *     surveyor's own audits ("My Audits" mode — the latest audit
     *     *by that user* on each segment). Pass null for "All Audits"
     *     mode — the latest audit by anyone on each segment, plus who
     *     did it.
     * @return list<array{
     *     segment_id:int, road_id:int, road_name:string,
     *     segment_number:int, status:string,
     *     gps_start:string, start_label:?string, surveyor_name:?string
     * }>
     */
    public function mapData(?int $userId): array
    {
        $scopeClause = $userId !== null ? 'WHERE surveyor_id = ?' : '';
        $stmt = $this->pdo->prepare(
            "SELECT
                 s.id            AS segment_id,
                 s.road_id,
                 r.name          AS road_name,
                 s.segment_number,
                 s.status,
                 latest.gps_start,
                 latest.start_landmark AS start_label,
                 u.name          AS surveyor_name
             FROM (
                 SELECT segment_id, MAX(id) AS latest_audit_id
                   FROM segment_audits
                   $scopeClause
                  GROUP BY segment_id
             ) latest_ids
             JOIN segment_audits latest ON latest.id = latest_ids.latest_audit_id
             JOIN segments s ON s.id = latest.segment_id
             JOIN roads r    ON r.id = s.road_id
             JOIN users u    ON u.id = latest.surveyor_id
             WHERE latest.gps_start IS NOT NULL AND latest.gps_start != ''
             ORDER BY r.name, s.segment_number"
        );
        $stmt->execute($userId !== null ? [$userId] : []);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Roads this user can resume — the user's most recent audit_session
     * on the road is 'active' AND the road still has at least one
     * 'pending' segment. Used by "My Audits" Section 3 ("Continue where
     * you left off").
     *
     * For each qualifying road, returns segment-progress counts, the
     * next pending segment to resume at, and the most recent audit
     * timestamp this user logged on that road (for "most recently
     * touched" ordering — audit_sessions has no updated_at column, so
     * segment_audits.audited_at is the best available recency signal).
     *
     * Roads where the active session has no pending segments left
     * (edge case — session not yet transitioned to completed) are
     * excluded in PHP below, since there's nothing to resume there.
     *
     * @return list<array<string,mixed>>
     */
    public function personalContinueAudits(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                 r.id                  AS road_id,
                 r.name                AS road_name,
                 latest_sess.id        AS session_id,
                 latest_sess.started_at AS session_started_at,
                 (SELECT COUNT(*) FROM segments s2
                   WHERE s2.road_id = r.id)                       AS total_segments,
                 (SELECT COUNT(*) FROM segments s2
                   WHERE s2.road_id = r.id AND s2.status = \'completed\') AS completed_segments,
                 (SELECT s3.id FROM segments s3
                   WHERE s3.road_id = r.id AND s3.status = \'pending\'
                   ORDER BY s3.segment_number ASC LIMIT 1)         AS next_segment_id,
                 (SELECT s3.segment_number FROM segments s3
                   WHERE s3.road_id = r.id AND s3.status = \'pending\'
                   ORDER BY s3.segment_number ASC LIMIT 1)         AS next_segment_number,
                 (SELECT MAX(sa.audited_at) FROM segment_audits sa
                   JOIN segments s4 ON s4.id = sa.segment_id
                  WHERE s4.road_id = r.id AND sa.surveyor_id = ?)  AS last_activity_at
             FROM (
                 SELECT road_id, MAX(id) AS latest_session_id
                   FROM audit_sessions
                  WHERE user_id = ?
                  GROUP BY road_id
             ) latest_ids
             JOIN audit_sessions latest_sess ON latest_sess.id = latest_ids.latest_session_id
             JOIN roads r ON r.id = latest_sess.road_id
             WHERE latest_sess.status = \'active\'
             ORDER BY last_activity_at DESC'
        );
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ══════════════════════════════════════════════════════════
    //  SEGMENT AUDIT WRITES
    // ══════════════════════════════════════════════════════════

    /**
     * Delete all audit data (segment_audits + children) for a segment.
     * Handles the obstructions/intersections cascade manually because
     * not all deployments guarantee ON DELETE CASCADE is set.
     */
    public function deleteAuditData(int $segmentId): void
    {
        $auditIds = $this->auditIdsForSegment($segmentId);

        if (!empty($auditIds)) {
            $ph = implode(',', array_fill(0, count($auditIds), '?'));
            $this->pdo->prepare("DELETE FROM obstructions  WHERE audit_id IN ({$ph})")->execute($auditIds);
            $this->pdo->prepare("DELETE FROM intersections WHERE audit_id IN ({$ph})")->execute($auditIds);
            $this->pdo->prepare('DELETE FROM segment_audits WHERE segment_id = ?')->execute([$segmentId]);
        }
    }

    /**
     * Delete obstructions + intersections for a single audit row
     * (used in edit-mode to re-insert from fresh form data).
     */
    public function deleteAuditChildren(int $auditId): void
    {
        $this->pdo->prepare('DELETE FROM obstructions  WHERE audit_id = ?')->execute([$auditId]);
        $this->pdo->prepare('DELETE FROM intersections WHERE audit_id = ?')->execute([$auditId]);
    }

    // ══════════════════════════════════════════════════════════
    //  LEADERBOARD / STREAK — Visibility & Motivation roadmap #3
    // ══════════════════════════════════════════════════════════

    /**
     * Ranked surveyor totals: segment submissions + distance audited
     * (sum of each audited segment's length), either all-time or
     * scoped to the current ISO week (Mon–Sun, matches MySQL mode 3).
     * Every segment_audits row counts once — this rewards submitted
     * work, not just currently-completed segment state, so a segment
     * re-audited by someone else doesn't retroactively strip credit.
     *
     * @return list<array{surveyor_id:int,surveyor_name:string,segments_completed:int,distance_m:float}>
     */
    public function leaderboardRows(bool $thisWeekOnly): array
    {
        $windowClause = $thisWeekOnly
            ? 'WHERE YEARWEEK(sa.audited_at, 3) = YEARWEEK(CURDATE(), 3)'
            : '';

        $stmt = $this->pdo->prepare(
            "SELECT
                 u.id            AS surveyor_id,
                 u.name          AS surveyor_name,
                 COUNT(*)        AS segments_completed,
                 COALESCE(SUM(s.length), 0) AS distance_m
             FROM segment_audits sa
             JOIN users u    ON u.id = sa.surveyor_id
             JOIN segments s ON s.id = sa.segment_id
             {$windowClause}
             GROUP BY u.id, u.name
             ORDER BY segments_completed DESC, distance_m DESC, u.name ASC
             LIMIT 50"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['surveyor_id']         = (int)$r['surveyor_id'];
            $r['segments_completed']  = (int)$r['segments_completed'];
            $r['distance_m']          = (float)$r['distance_m'];
        }
        unset($r);

        return $rows;
    }

    /**
     * Distinct calendar dates (Y-m-d strings, DESC) this user has
     * submitted at least one segment audit on — the raw input for
     * streak calculation. Capped at 400 rows (~13 months of daily
     * activity) since only the trailing consecutive run matters.
     *
     * @return list<string>
     */
    public function auditDatesForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT DATE(audited_at) AS d
               FROM segment_audits
              WHERE surveyor_id = ?
              ORDER BY d DESC
              LIMIT 400'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}