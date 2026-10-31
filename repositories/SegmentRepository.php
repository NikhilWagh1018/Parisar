<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════════
//  repositories/SegmentRepository.php
//  Centralises all segment + segment_audit SQL.
//  Eliminates duplicated ownership/status queries spread across:
//    api/segments/submit.php, reset.php, unlock.php,
//    audit-data.php, complete.php,
//    api/roads/segments/index.php, save.php
// ═══════════════════════════════════════════════════════════════════

class SegmentRepository
{
    public function __construct(private PDO $pdo) {}

    // ─────────────────────────────────────────────────────────────
    //  SEGMENT READS
    // ─────────────────────────────────────────────────────────────

    /**
     * Fetch a single segment row joined with its road's creator_id.
     * Returns null if not found.
     *
     * Also includes road_name and segment_number, a friendly label for
     * the segment. Callers that only read id/status/road_id/creator_id/
     * finalized_at are unaffected.
     *
     * @return array{id:int,road_id:int,status:string,creator_id:int,segment_number:int,road_name:string}|null
     */
    public function findWithRoad(int $segmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.status, s.road_id, s.segment_number,
                    r.creator_id, r.finalized_at, r.name AS road_name,
                    rg.city_id AS city_id
               FROM segments s
               JOIN roads r ON r.id = s.road_id
               LEFT JOIN road_groups rg ON rg.id = r.road_group_id
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

    // ─────────────────────────────────────────────────────────────
    //  SEGMENT WRITES
    // ─────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────
    //  AUDIT SESSION OWNERSHIP
    // ─────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────
    //  SEGMENT AUDIT READS
    // ─────────────────────────────────────────────────────────────

    /**
     * Fetch the most recent segment_audit row for a segment.
     * Returns null if no audit exists yet.
     *
     * @return array<string,mixed>|null
     */
    public function latestAudit(int $segmentId): ?array
    {
        // NOTE (fixed after live testing): segment_length is NOT a real
        // column on segment_audits in production; the length comes from
        // the segments table via the JOIN. Selecting it from segment_audits
        // was silently breaking api/segments/audit-data.php (500 "Server
        // error") on every call, since that endpoint calls this method
        // directly to pre-fill the edit-audit form.
        $stmt = $this->pdo->prepare(
            'SELECT sa.id, sa.session_id,
                    sa.start_landmark, sa.end_landmark, sa.gps_start, sa.gps_end,
                    sa.cycle_track_missing, sa.missing_length, sa.cyclist_use, sa.better_surface,
                    sa.surface_material, sa.people_walking, sa.signage_count, sa.shade,
                    sa.light_after_sunset, sa.track_geometry, sa.buffer_zone,
                    sa.segment_width, s.length AS segment_length, sa.comments,
                    sa.surface_issues, sa.overhead_issues, sa.footpath_rating, sa.footpath_score,
                    sa.public_id, sa.surveyor_id
               FROM segment_audits sa
               JOIN segments s ON s.id = sa.segment_id
              WHERE sa.segment_id = ?
              ORDER BY sa.id DESC
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

    // ─────────────────────────────────────────────────────────────
    //  SEGMENT AUDIT WRITES
    // ─────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────
    //  STREAK
    // ─────────────────────────────────────────────────────────────

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
            'SELECT DISTINCT DATE(created_at) AS d
               FROM segment_audits
              WHERE surveyor_id = ?
              ORDER BY d DESC
              LIMIT 400'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
