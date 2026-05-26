<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/AuditSessionRepository.php
//  Centralises all audit_sessions read/write logic that is
//  session-centric (as opposed to road-centric queries, which
//  live in RoadRepository).
//
//  Use this repository for:
//    - Fetching sessions for a specific user (dashboard, history)
//    - Completing / archiving a session
//    - Checking completion status of all segments in a session
//
//  Cross-cutting ownership checks during segment operations
//  live in SegmentRepository to keep those files self-contained.
// ═══════════════════════════════════════════════════════════════

class AuditSessionRepository
{
    public function __construct(private PDO $pdo) {}

    // ══════════════════════════════════════════════════════════
    //  READS
    // ══════════════════════════════════════════════════════════

    /**
     * Fetch a session by ID.
     * Returns null if not found.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $sessionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, user_id, road_id,
                    status, started_at, completed_at
               FROM audit_sessions
              WHERE id = ?
              LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Fetch a session and assert it belongs to $userId.
     * Returns null if not found or wrong owner.
     *
     * @return array<string,mixed>|null
     */
    public function findOwnedBy(int $sessionId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, user_id, road_id,
                    status, started_at, completed_at
               FROM audit_sessions
              WHERE id = ? AND user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$sessionId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Fetch all sessions for a user, newest first.
     * Optionally filter by status ('active' | 'completed' | 'archived').
     *
     * @return list<array<string,mixed>>
     */
    public function allForUser(int $userId, ?string $status = null): array
    {
        if ($status !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT s.id, s.public_id, s.road_id, s.status,
                        s.started_at, s.completed_at,
                        r.name AS road_name
                   FROM audit_sessions s
                   JOIN roads r ON r.id = s.road_id
                  WHERE s.user_id = ? AND s.status = ?
                  ORDER BY s.id DESC'
            );
            $stmt->execute([$userId, $status]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT s.id, s.public_id, s.road_id, s.status,
                        s.started_at, s.completed_at,
                        r.name AS road_name
                   FROM audit_sessions s
                   JOIN roads r ON r.id = s.road_id
                  WHERE s.user_id = ?
                  ORDER BY s.id DESC'
            );
            $stmt->execute([$userId]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check whether all segments on a session's road are completed.
     * Used to decide if a session can be auto-completed.
     */
    public function allSegmentsComplete(int $sessionId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(s.status = 'completed') AS done
               FROM audit_sessions a
               JOIN segments s ON s.road_id = a.road_id
              WHERE a.id = ?"
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int)$row['total'] === 0) {
            return false;
        }

        return (int)$row['total'] === (int)$row['done'];
    }

    /**
     * Return a count breakdown: total / completed segments for a session.
     *
     * @return array{total:int, completed:int, pending:int}
     */
    public function segmentProgress(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)                     AS total,
                    SUM(s.status = 'completed')  AS completed,
                    SUM(s.status = 'pending')    AS pending
               FROM audit_sessions a
               JOIN segments s ON s.road_id = a.road_id
              WHERE a.id = ?"
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total'     => (int)($row['total']     ?? 0),
            'completed' => (int)($row['completed'] ?? 0),
            'pending'   => (int)($row['pending']   ?? 0),
        ];
    }

    // ══════════════════════════════════════════════════════════
    //  WRITES
    // ══════════════════════════════════════════════════════════

    /**
     * Mark a session as completed.
     */
    public function complete(int $sessionId): void
    {
        $this->pdo->prepare(
            "UPDATE audit_sessions
                SET status = 'completed', completed_at = NOW()
              WHERE id = ?"
        )->execute([$sessionId]);
    }

    /**
     * Archive a session (read-only; no further edits allowed).
     */
    public function archive(int $sessionId): void
    {
        $this->pdo->prepare(
            "UPDATE audit_sessions
                SET status = 'archived'
              WHERE id = ?"
        )->execute([$sessionId]);
    }

    /**
     * Reactivate a completed session (used after a segment reset/unlock).
     * Only reactivates if the session is currently 'completed'.
     */
    public function reactivate(int $sessionId): void
    {
        $this->pdo->prepare(
            "UPDATE audit_sessions
                SET status = 'active', completed_at = NULL
              WHERE id = ? AND status = 'completed'"
        )->execute([$sessionId]);
    }

    /**
     * Auto-complete a session if all its road's segments are done.
     * Returns true if the session was completed, false if not yet ready.
     */
    public function autoCompleteIfReady(int $sessionId): bool
    {
        if (!$this->allSegmentsComplete($sessionId)) {
            return false;
        }

        $this->complete($sessionId);
        return true;
    }
}
