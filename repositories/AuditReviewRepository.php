<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/AuditReviewRepository.php
//  City Leader review loop: approve or send back submitted segments,
//  close the audit, send it to the Admin.
//  SQL is kept portable (MySQL in production, SQLite in tests).
//  User-facing problems are thrown as DomainException (safe to show).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../helpers/AuditReview.php';

class AuditReviewRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array{total:int,unassigned:int,assigned:int,submitted:int,needs_revisit:int,approved:int} */
    public function counts(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sa.status AS assignment_status
               FROM roads r
               JOIN segments s ON s.road_id = r.id
               LEFT JOIN segment_assignments sa ON sa.segment_id = s.id
              WHERE r.audit_id = ?'
        );
        $stmt->execute([$auditId]);
        return auditReviewCounts(array_map(
            static fn($v) => $v === false || $v === null ? null : (string)$v,
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        ));
    }

    /**
     * Segments waiting for review (submitted), oldest first, each with the
     * latest audit row so the reviewer can see what was recorded.
     *
     * @return list<array<string,mixed>>
     */
    public function submissions(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sa.id AS assignment_id, sa.segment_id, sa.surveyor_id, sa.submitted_at,
                    u.name AS surveyor_name,
                    s.segment_number, s.length,
                    r.id AS road_id, r.name AS road_name,
                    (SELECT MAX(x.id) FROM segment_audits x WHERE x.segment_id = s.id) AS latest_audit_id
               FROM segment_assignments sa
               JOIN segments s ON s.id = sa.segment_id
               JOIN roads r    ON r.id = s.road_id
               LEFT JOIN users u ON u.id = sa.surveyor_id
              WHERE sa.audit_id = ? AND sa.status = 'submitted'
              ORDER BY sa.submitted_at ASC, sa.id ASC"
        );
        $stmt->execute([$auditId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $detail = $this->pdo->prepare(
            'SELECT cycle_track_missing, missing_length, cyclist_use, surface_material, segment_width,
                    shade, buffer_zone, signage_count, comments
               FROM segment_audits WHERE id = ?'
        );
        foreach ($rows as &$row) {
            $row['data'] = [];
            if ($row['latest_audit_id'] !== null) {
                $detail->execute([(int)$row['latest_audit_id']]);
                $row['data'] = $detail->fetch(PDO::FETCH_ASSOC) ?: [];
            }
        }
        unset($row);
        return $rows;
    }

    /** Segments the City Leader sent back that have not been resubmitted yet. @return list<array<string,mixed>> */
    public function sentBack(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sa.segment_id, sa.review_note, sa.reviewed_at,
                    u.name AS surveyor_name, s.segment_number, r.name AS road_name
               FROM segment_assignments sa
               JOIN segments s ON s.id = sa.segment_id
               JOIN roads r    ON r.id = s.road_id
               LEFT JOIN users u ON u.id = sa.surveyor_id
              WHERE sa.audit_id = ? AND sa.status = 'needs_revisit'
              ORDER BY r.name ASC, s.segment_number ASC"
        );
        $stmt->execute([$auditId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Active surveyors of the city (for the "assign to a different surveyor" choice). @return list<array{id:int,name:string}> */
    public function citySurveyors(int $cityId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, name FROM users WHERE role = 'surveyor' AND is_active = 1 AND city_id = ? ORDER BY name ASC"
        );
        $stmt->execute([$cityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function requireOpen(array $audit): void
    {
        if (!in_array($audit['status'], AUDIT_REVIEW_OPEN_STATUSES, true)) {
            throw new DomainException('This audit is not open for review.');
        }
    }

    /** The assignment row of a submitted segment in this audit, or an error. */
    private function submittedAssignment(array $audit, int $segmentId): array
    {
        $q = $this->pdo->prepare(
            'SELECT sa.id, sa.surveyor_id, sa.status, s.road_id
               FROM segment_assignments sa
               JOIN segments s ON s.id = sa.segment_id
              WHERE sa.audit_id = ? AND sa.segment_id = ?'
        );
        $q->execute([(int)$audit['id'], $segmentId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new DomainException('That segment is not part of this audit.');
        }
        if ($row['status'] !== 'submitted') {
            throw new DomainException('That segment is not waiting for review.');
        }
        return $row;
    }

    /** Review has begun: move an active audit to in_review. */
    private function markInReview(int $auditId): void
    {
        $this->pdo->prepare("UPDATE city_audits SET status = 'in_review' WHERE id = ? AND status = 'active'")
            ->execute([$auditId]);
    }

    public function approve(array $audit, int $segmentId, int $reviewerId): void
    {
        $this->requireOpen($audit);
        $a = $this->submittedAssignment($audit, $segmentId);

        $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare(
                "UPDATE segment_assignments
                    SET status = 'approved', reviewed_at = CURRENT_TIMESTAMP, reviewed_by = ?, review_note = NULL
                  WHERE id = ? AND status = 'submitted'"
            );
            $upd->execute([$reviewerId, (int)$a['id']]);
            if ($upd->rowCount() === 0) {
                throw new DomainException('That segment was already reviewed. Reload the page.');
            }
            $this->markInReview((int)$audit['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Send a submitted segment back for re-audit. The segment becomes pending
     * again so the form opens; the surveyor sees "Needs revisit" with the note.
     * $newSurveyorId (optional) hands the segment to a different surveyor.
     */
    public function sendBack(array $audit, int $segmentId, int $reviewerId, string $note, ?int $newSurveyorId = null): void
    {
        $this->requireOpen($audit);
        $clean = auditReviewCleanNote($note);
        if ($clean['error'] !== null) {
            throw new DomainException($clean['error']);
        }
        $a = $this->submittedAssignment($audit, $segmentId);

        $surveyorId = (int)$a['surveyor_id'];
        if ($newSurveyorId !== null && $newSurveyorId !== $surveyorId) {
            $u = $this->pdo->prepare(
                "SELECT COUNT(*) FROM users WHERE id = ? AND role = 'surveyor' AND is_active = 1 AND city_id = ?"
            );
            $u->execute([$newSurveyorId, (int)$audit['city_id']]);
            if ((int)$u->fetchColumn() === 0) {
                throw new DomainException('Choose an active surveyor of this city.');
            }
            $surveyorId = $newSurveyorId;
        }

        $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare(
                "UPDATE segment_assignments
                    SET status = 'needs_revisit', surveyor_id = ?, reviewed_at = CURRENT_TIMESTAMP,
                        reviewed_by = ?, review_note = ?
                  WHERE id = ? AND status = 'submitted'"
            );
            $upd->execute([$surveyorId, $reviewerId, $clean['note'], (int)$a['id']]);
            if ($upd->rowCount() === 0) {
                throw new DomainException('That segment was already reviewed. Reload the page.');
            }
            // The audit data is kept; only the segment is opened up again.
            $this->pdo->prepare("UPDATE segments SET status = 'pending', completed_at = NULL WHERE id = ?")
                ->execute([$segmentId]);
            $this->reopenLatestSession((int)$a['road_id'], $surveyorId);
            $this->markInReview((int)$audit['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Re-open the surveyor's latest completed session on the road so the form can be used again. */
    private function reopenLatestSession(int $roadId, int $userId): void
    {
        $q = $this->pdo->prepare(
            "SELECT id FROM audit_sessions WHERE road_id = ? AND user_id = ? AND status = 'completed'
              ORDER BY id DESC LIMIT 1"
        );
        $q->execute([$roadId, $userId]);
        $id = $q->fetchColumn();
        if ($id !== false) {
            $this->pdo->prepare("UPDATE audit_sessions SET status = 'active', completed_at = NULL WHERE id = ?")
                ->execute([(int)$id]);
        }
    }

    /** Close the audit: every segment approved. Status becomes finalised. */
    public function close(array $audit): void
    {
        $counts = $this->counts((int)$audit['id']);
        $why    = auditReviewCloseBlockReason((string)$audit['status'], $counts);
        if ($why !== null) {
            throw new DomainException($why);
        }
        $upd = $this->pdo->prepare(
            "UPDATE city_audits SET status = 'finalised' WHERE id = ? AND status IN ('active', 'in_review')"
        );
        $upd->execute([(int)$audit['id']]);
        if ($upd->rowCount() === 0) {
            throw new DomainException('This audit was already closed.');
        }
    }

    /** Send a closed audit and its report to the Admin. */
    public function sendToAdmin(array $audit): void
    {
        if ($audit['status'] !== 'finalised') {
            throw new DomainException('Close the audit before sending it to the Admin.');
        }
        $upd = $this->pdo->prepare("UPDATE city_audits SET status = 'awaiting_approval' WHERE id = ? AND status = 'finalised'");
        $upd->execute([(int)$audit['id']]);
        if ($upd->rowCount() === 0) {
            throw new DomainException('This audit was already sent.');
        }
    }
}
