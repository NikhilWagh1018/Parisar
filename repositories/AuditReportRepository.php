<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/AuditReportRepository.php
//  Read-only data for the segment review page and the audit report:
//  what a surveyor recorded, and one row per segment for the report.
//  Scores are worked out by services/ScoreService.php, not here.
//  SQL is kept portable (MySQL in production, SQLite in tests).
// ═══════════════════════════════════════════════════════════════

class AuditReportRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * One segment of an audit with its assignment, its latest audit row,
     * obstructions and intersections. Null when the segment is not part of
     * this audit.
     *
     * @return array<string,mixed>|null
     */
    public function segmentDetail(int $auditId, int $segmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sa.id AS assignment_id, sa.status AS assignment_status, sa.surveyor_id,
                    sa.submitted_at, sa.reviewed_at, sa.review_note,
                    u.name AS surveyor_name,
                    s.id AS segment_id, s.segment_number, s.length, s.start_distance, s.end_distance,
                    r.id AS road_id, r.name AS road_name,
                    (SELECT MAX(x.id) FROM segment_audits x WHERE x.segment_id = s.id) AS latest_audit_id
               FROM segment_assignments sa
               JOIN segments s ON s.id = sa.segment_id
               JOIN roads r    ON r.id = s.road_id
               LEFT JOIN users u ON u.id = sa.surveyor_id
              WHERE sa.audit_id = ? AND sa.segment_id = ?"
        );
        $stmt->execute([$auditId, $segmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $row['data']          = [];
        $row['obstructions']  = [];
        $row['intersections'] = [];
        if ($row['latest_audit_id'] !== null) {
            $aid = (int)$row['latest_audit_id'];

            $a = $this->pdo->prepare('SELECT * FROM segment_audits WHERE id = ?');
            $a->execute([$aid]);
            $row['data'] = $a->fetch(PDO::FETCH_ASSOC) ?: [];

            $o = $this->pdo->prepare(
                'SELECT obstruction_category, obstruction_type, partial_obstructions, total_obstructions, cyclist_slowed
                   FROM obstructions WHERE audit_id = ? ORDER BY id ASC'
            );
            $o->execute([$aid]);
            $row['obstructions'] = $o->fetchAll(PDO::FETCH_ASSOC);

            $i = $this->pdo->prepare(
                'SELECT intersection_num, landmark_name, off_ramp, on_ramp, markings, signage,
                        traffic_calming, discontinuity, tapering
                   FROM intersections WHERE audit_id = ? ORDER BY intersection_num ASC, id ASC'
            );
            $i->execute([$aid]);
            $row['intersections'] = $i->fetchAll(PDO::FETCH_ASSOC);
        }
        return $row;
    }

    /** Another segment of this audit that is waiting for review, or null. */
    public function nextToReview(int $auditId, int $excludeSegmentId): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT sa.segment_id
               FROM segment_assignments sa
              WHERE sa.audit_id = ? AND sa.status = 'submitted' AND sa.segment_id <> ?
              ORDER BY sa.submitted_at ASC, sa.id ASC
              LIMIT 1"
        );
        $stmt->execute([$auditId, $excludeSegmentId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Every segment of the audit (road by road) with its assignment state and
     * the id of its latest audit row (null when nothing was recorded yet).
     *
     * @return list<array<string,mixed>>
     */
    public function reportSegments(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id AS road_id, r.name AS road_name,
                    s.id AS segment_id, s.segment_number, s.length,
                    sa.status AS assignment_status, u.name AS surveyor_name,
                    (SELECT MAX(x.id) FROM segment_audits x WHERE x.segment_id = s.id) AS latest_audit_id
               FROM roads r
               JOIN segments s ON s.road_id = r.id
               LEFT JOIN segment_assignments sa ON sa.segment_id = s.id
               LEFT JOIN users u ON u.id = sa.surveyor_id
              WHERE r.audit_id = ?
              ORDER BY r.name ASC, r.id ASC, s.segment_number ASC"
        );
        $stmt->execute([$auditId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Everything the detailed report shows for each segment audit: the audit row,
     * intersection counts and obstruction totals. Keyed by audit id.
     *
     * @param  list<int> $auditIds
     * @return array<int,array<string,mixed>>
     */
    public function detailsForAuditIds(array $auditIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $auditIds)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        $a = $this->pdo->prepare(
            "SELECT id, surface_material, buffer_zone, light_after_sunset, shade, surface_issues,
                    overhead_issues, cycle_track_missing, missing_length, people_walking, cyclist_use,
                    better_surface, signage_count AS seg_signage_count
               FROM segment_audits WHERE id IN ($in)"
        );
        $a->execute($ids);
        $out = [];
        foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['id']] = [
                'audit' => $row, 'intersections' => 0, 'no_ramps' => 0, 'no_sign' => 0,
                'obs_total' => 0, 'obs_partial' => 0, 'cyclist_slowed' => 0,
            ];
        }

        $i = $this->pdo->prepare("SELECT audit_id, off_ramp, on_ramp, markings, signage FROM intersections WHERE audit_id IN ($in)");
        $i->execute($ids);
        foreach ($i->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (int)$r['audit_id'];
            if (!isset($out[$k])) { continue; }
            $out[$k]['intersections']++;
            if (($r['off_ramp'] ?? '') === 'No Ramp' || ($r['on_ramp'] ?? '') === 'No Ramp') { $out[$k]['no_ramps']++; }
            if (($r['markings'] ?? '') === 'Absent' || ($r['signage'] ?? '') === 'Absent')   { $out[$k]['no_sign']++; }
        }

        $o = $this->pdo->prepare(
            "SELECT audit_id, COALESCE(SUM(total_obstructions),0) AS total,
                    COALESCE(SUM(partial_obstructions),0) AS partial, COALESCE(SUM(cyclist_slowed),0) AS slowed
               FROM obstructions WHERE audit_id IN ($in) GROUP BY audit_id"
        );
        $o->execute($ids);
        foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (int)$r['audit_id'];
            if (!isset($out[$k])) { continue; }
            $out[$k]['obs_total']      = (int)$r['total'];
            $out[$k]['obs_partial']    = (int)$r['partial'];
            $out[$k]['cyclist_slowed'] = (int)$r['slowed'];
        }
        return $out;
    }
}
