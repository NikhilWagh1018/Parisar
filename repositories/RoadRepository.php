<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/RoadRepository.php
//  Centralises all roads + audit_sessions SQL.
//  Eliminates duplicated ownership/existence queries spread across:
//    api/roads/create.php, update.php, delete.php,
//    api/roads/segments/save.php, index.php,
//    api/audit-sessions/create.php,
//    api/reports/session.php
// ═══════════════════════════════════════════════════════════════

class RoadRepository
{
    public function __construct(private PDO $pdo) {}

    // ══════════════════════════════════════════════════════════
    //  ROAD READS
    // ══════════════════════════════════════════════════════════

    /**
     * Fetch a single road row by ID.
     * Returns null if not found.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $roadId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, creator_id, name,
                    start_point, end_point, total_length,
                    gps_start, gps_end,
                    segment_method, segment_length,
                    created_at
               FROM roads
              WHERE id = ?
              LIMIT 1'
        );
        $stmt->execute([$roadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Verify a road exists AND is owned by $userId.
     * Returns the road row on success, null on not-found or wrong owner.
     *
     * @return array<string,mixed>|null
     */
    public function findOwnedBy(int $roadId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, creator_id, name,
                    start_point, end_point, total_length,
                    gps_start, gps_end,
                    segment_method, segment_length
               FROM roads
              WHERE id = ? AND creator_id = ?
              LIMIT 1'
        );
        $stmt->execute([$roadId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Fetch all roads created by a user, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function allForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, name, start_point, end_point,
                    total_length, segment_method, created_at
               FROM roads
              WHERE creator_id = ?
              ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ══════════════════════════════════════════════════════════
    //  ROAD WRITES
    // ══════════════════════════════════════════════════════════

    /**
     * Insert a new road and return its id + generated public_id.
     *
     * @param array<string,mixed> $data  Keys: name, start_point, end_point,
     *                                   total_length, gps_start, gps_end,
     *                                   segment_method, segment_length
     * @return array{road_id:int, public_id:string}
     */
    public function create(int $creatorId, array $data): array
    {
        $this->pdo->prepare(
            'INSERT INTO roads
               (creator_id, name, start_point, end_point, total_length,
                gps_start, gps_end, segment_method, segment_length)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $creatorId,
            strtoupper(strip_tags((string)$data['name'])),
            $data['start_point']    ?: null,
            $data['end_point']      ?: null,
            $data['total_length']   ?? null,
            $data['gps_start']      ?: null,
            $data['gps_end']        ?: null,
            $data['segment_method'] ?? 'auto',
            $data['segment_length'] ?? null,
        ]);

        $roadId      = (int)$this->pdo->lastInsertId();
        $publicId    = 'ROAD-' . str_pad((string)$roadId, 4, '0', STR_PAD_LEFT);
        $this->pdo->prepare('UPDATE roads SET public_id = ? WHERE id = ?')
                  ->execute([$publicId, $roadId]);

        return ['road_id' => $roadId, 'public_id' => $publicId];
    }

    /**
     * Update road metadata. Caller must verify ownership first.
     *
     * @param array<string,mixed> $data  Same keys as create().
     */
    public function update(int $roadId, int $creatorId, array $data): void
    {
        $this->pdo->prepare(
            'UPDATE roads SET
               name           = ?,
               start_point    = ?,
               end_point      = ?,
               total_length   = ?,
               gps_start      = ?,
               gps_end        = ?,
               segment_method = ?,
               segment_length = ?
             WHERE id = ? AND creator_id = ?'
        )->execute([
            strtoupper(strip_tags((string)$data['name'])),
            $data['start_point']    ?: null,
            $data['end_point']      ?: null,
            $data['total_length']   ?? null,
            $data['gps_start']      ?: null,
            $data['gps_end']        ?: null,
            $data['segment_method'] ?? 'auto',
            $data['segment_length'] ?? null,
            $roadId,
            $creatorId,
        ]);
    }

    /**
     * Delete a road (DB cascade removes children).
     */
    public function delete(int $roadId): void
    {
        $this->pdo->prepare('DELETE FROM roads WHERE id = ?')->execute([$roadId]);
    }

    // ══════════════════════════════════════════════════════════
    //  AUDIT SESSION READS
    // ══════════════════════════════════════════════════════════

    /**
     * Find the active session for a user on a road.
     * Returns null if none exists.
     *
     * @return array{id:int,public_id:string}|null
     */
    public function findActiveSession(int $userId, int $roadId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, public_id
               FROM audit_sessions
              WHERE user_id = ? AND road_id = ? AND status = 'active'
              LIMIT 1"
        );
        $stmt->execute([$userId, $roadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Fetch full session data including road_id and user_id.
     * Returns null if not found.
     *
     * @return array<string,mixed>|null
     */
    public function findSession(int $sessionId): ?array
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

    // ══════════════════════════════════════════════════════════
    //  AUDIT SESSION WRITES
    // ══════════════════════════════════════════════════════════

    /**
     * Create a new audit session and return its id + public_id.
     *
     * @return array{session_id:int, public_id:string}
     */
    public function createSession(int $userId, int $roadId): array
    {
        $this->pdo->prepare(
            "INSERT INTO audit_sessions (user_id, road_id, status, started_at)
             VALUES (?, ?, 'active', NOW())"
        )->execute([$userId, $roadId]);

        $sessionId = (int)$this->pdo->lastInsertId();
        $publicId  = 'AUD-' . str_pad((string)$sessionId, 4, '0', STR_PAD_LEFT);
        $this->pdo->prepare('UPDATE audit_sessions SET public_id = ? WHERE id = ?')
                  ->execute([$publicId, $sessionId]);

        return ['session_id' => $sessionId, 'public_id' => $publicId];
    }
}
