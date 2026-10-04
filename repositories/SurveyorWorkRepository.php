<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/SurveyorWorkRepository.php
//  A surveyor's assigned segments in city audits, and the checks
//  that tie the existing audit form to those assignments.
//  SQL is kept portable (MySQL in production, SQLite in tests).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../helpers/SurveyorWork.php';

class SurveyorWorkRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Every segment assigned to this surveyor in a draft-free city audit.
     * Each row also carries state_key / state_label for display.
     *
     * @return list<array<string,mixed>>
     */
    public function forSurveyor(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sa.id AS assignment_id, sa.segment_id, sa.status AS assignment_status, sa.review_note,
                    s.segment_number, s.length,
                    r.id AS road_id, r.name AS road_name,
                    a.id AS audit_id, a.name AS audit_name, a.audit_year, a.status AS audit_status,
                    (SELECT COUNT(*) FROM audit_sessions au
                      WHERE au.user_id = sa.surveyor_id AND au.road_id = r.id AND au.status = 'active') AS active_sessions
               FROM segment_assignments sa
               JOIN segments s     ON s.id = sa.segment_id
               JOIN roads r        ON r.id = s.road_id
               JOIN city_audits a  ON a.id = sa.audit_id
              WHERE sa.surveyor_id = ? AND a.status NOT IN ('draft', 'voided')
              ORDER BY a.id DESC, r.name ASC, s.segment_number ASC"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $state = surveyorWorkState((string)$row['assignment_status'], (int)$row['active_sessions'] > 0);
            $row['state_key']   = $state['key'];
            $row['state_label'] = $state['label'];
            $row['can_audit']   = surveyorCanAudit((string)$row['assignment_status'])
                && in_array((string)$row['audit_status'], SURVEYOR_WORKABLE_AUDIT_STATUSES, true);
        }
        unset($row);
        return $rows;
    }

    /** Reason this surveyor may not submit the segment, or null if they may. */
    public function submitBlockReason(int $segmentId, int $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.audit_id, a.status AS audit_status,
                    sa.surveyor_id, sa.status AS assignment_status
               FROM segments s
               JOIN roads r ON r.id = s.road_id
               LEFT JOIN city_audits a ON a.id = r.audit_id
               LEFT JOIN segment_assignments sa ON sa.segment_id = s.id
              WHERE s.id = ?'
        );
        $stmt->execute([$segmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null; // unknown segment: the caller's own 404 handles it
        }
        return surveyorSubmitBlockReason(
            $row['audit_id'] !== null ? (int)$row['audit_id'] : null,
            $row['audit_status'] !== null ? (string)$row['audit_status'] : null,
            $row['surveyor_id'] !== null ? (int)$row['surveyor_id'] : null,
            $row['assignment_status'] !== null ? (string)$row['assignment_status'] : null,
            $userId
        );
    }

    /**
     * May this user start or resume an audit session on the road?
     * Roads outside city audits are unchanged. Roads in a city audit need
     * an assigned segment on that road and a workable audit.
     */
    public function mayOpenRoad(int $roadId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.audit_id, a.status AS audit_status
               FROM roads r LEFT JOIN city_audits a ON a.id = r.audit_id
              WHERE r.id = ?'
        );
        $stmt->execute([$roadId]);
        $road = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($road === false || $road['audit_id'] === null) {
            return true;
        }
        if (!in_array((string)$road['audit_status'], SURVEYOR_WORKABLE_AUDIT_STATUSES, true)) {
            return false;
        }
        $q = $this->pdo->prepare(
            'SELECT COUNT(*) FROM segment_assignments sa
               JOIN segments s ON s.id = sa.segment_id
              WHERE s.road_id = ? AND sa.surveyor_id = ?'
        );
        $q->execute([$roadId, $userId]);
        return (int)$q->fetchColumn() > 0;
    }

    /** The surveyor submitted the segment: mark their assignment as submitted. */
    public function markSubmitted(int $segmentId, int $userId): void
    {
        $this->pdo->prepare(
            "UPDATE segment_assignments
                SET status = 'submitted', submitted_at = CURRENT_TIMESTAMP
              WHERE segment_id = ? AND surveyor_id = ?
                AND status IN ('assigned', 'needs_revisit')"
        )->execute([$segmentId, $userId]);
    }
}
