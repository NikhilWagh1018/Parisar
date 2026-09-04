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
            'SELECT r.id, r.public_id, r.creator_id, r.name,
                    r.start_point, r.end_point, r.total_length,
                    r.gps_start, r.gps_end,
                    r.segment_method, r.segment_length,
                    r.created_at, r.finalized_at,
                    rg.city_id AS city_id
               FROM roads r
               LEFT JOIN road_groups rg ON rg.id = r.road_group_id
              WHERE r.id = ?
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
            'SELECT r.id, r.public_id, r.creator_id, r.name,
                    r.start_point, r.end_point, r.total_length,
                    r.gps_start, r.gps_end,
                    r.segment_method, r.segment_length, r.finalized_at,
                    rg.city_id AS city_id
               FROM roads r
               LEFT JOIN road_groups rg ON rg.id = r.road_group_id
              WHERE r.id = ? AND r.creator_id = ?
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
        $name      = strtoupper(strip_tags((string)$data['name']));
        $roadGroupId = $this->findOrCreateRoadGroup($name, $creatorId);

        $this->pdo->prepare(
            'INSERT INTO roads
               (creator_id, name, road_group_id, total_length,
                segment_method, segment_length)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $creatorId,
            $name,
            $roadGroupId,
            $data['total_length']   ?? null,
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
     * Check whether a road_groups row matching this name (normalized,
     * case/whitespace insensitive) already exists. Used to block
     * non-admin users from introducing brand-new road names via
     * api/roads/create.php — they may only attach to an existing group.
     */
    public function roadGroupExists(string $name): bool
    {
        $normalized = trim(strtoupper($name));
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM road_groups WHERE TRIM(UPPER(canonical_name)) = ? LIMIT 1'
        );
        $stmt->execute([$normalized]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Find the road_groups row matching this name (case/whitespace
     * insensitive), or create a new unverified group if none exists.
     * This is what lets a 12th surveyor creating "Karve Road" attach
     * automatically to the existing group, with zero admin steps,
     * instead of spawning an invisible 12th duplicate that needs
     * manual re-verification.
     */
    private function findOrCreateRoadGroup(string $name, int $creatorId): int
    {
        $normalized = trim(strtoupper($name));

        $stmt = $this->pdo->prepare(
            'SELECT id FROM road_groups WHERE TRIM(UPPER(canonical_name)) = ? LIMIT 1'
        );
        $stmt->execute([$normalized]);
        $existing = $stmt->fetchColumn();

        if ($existing !== false) {
            return (int)$existing;
        }

        $cityId = $this->resolveCityIdForNewRoadGroup($creatorId);

        // Race-safe-ish: rely on the UNIQUE KEY on canonical_name. If a
        // concurrent request created the same group between our SELECT
        // and this INSERT, fall back to re-selecting instead of erroring.
        try {
            $this->pdo->prepare(
                'INSERT INTO road_groups (canonical_name, city_id, is_verified) VALUES (?, ?, 0)'
            )->execute([$name, $cityId]);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $stmt->execute([$normalized]);
            $retry = $stmt->fetchColumn();
            if ($retry !== false) {
                return (int)$retry;
            }
            throw $e;
        }
    }

    /**
     * road_groups.city_id is NOT NULL, but not every user has a city_id
     * set yet (national_admins are city-less by design; older accounts
     * predate city assignment). Resolution order:
     *   1. The creator's own city_id, if set.
     *   2. If exactly one city exists in the whole app, use that — this
     *      is Parisar's current single-city state (Pune, id 1), so a
     *      city-less national_admin creating a road still works.
     *   3. Otherwise, fail loudly rather than guess which of several
     *      cities a road belongs to.
     */
    private function resolveCityIdForNewRoadGroup(int $creatorId): int
    {
        $stmt = $this->pdo->prepare('SELECT city_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$creatorId]);
        $creatorCityId = $stmt->fetchColumn();

        if ($creatorCityId !== false && $creatorCityId !== null) {
            return (int)$creatorCityId;
        }

        $cityCount = (int)$this->pdo->query('SELECT COUNT(*) FROM cities')->fetchColumn();
        if ($cityCount === 1) {
            return (int)$this->pdo->query('SELECT id FROM cities LIMIT 1')->fetchColumn();
        }

        throw new RuntimeException(
            "Cannot create a new road: your account has no city assigned, and there is no single " .
            "default city to fall back to. Please assign a city to this user before creating roads."
        );
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
               total_length   = ?,
               segment_method = ?,
               segment_length = ?
             WHERE id = ? AND creator_id = ?'
        )->execute([
            strtoupper(strip_tags((string)$data['name'])),
            $data['total_length']   ?? null,
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

    /**
     * Whether every segment for a road is status = 'completed'
     * (and the road has at least one segment). Used to gate
     * finalization — a road can't be finalized until it's fully
     * audited.
     */
    public function allSegmentsCompleted(int $roadId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total, SUM(status = 'completed') AS done
               FROM segments
              WHERE road_id = ?"
        );
        $stmt->execute([$roadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int)$row['total'] === 0) {
            return false;
        }

        return (int)$row['total'] === (int)$row['done'];
    }

    /**
     * Permanently lock a road against further edits. Caller must
     * verify ownership and that allSegmentsCompleted() is true first.
     * Idempotent — only sets finalized_at if it isn't already set.
     *
     * @return bool  true if this call set finalized_at, false if it
     *               was already finalized (no-op).
     */
    public function finalize(int $roadId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE roads SET finalized_at = NOW()
              WHERE id = ? AND finalized_at IS NULL'
        );
        $stmt->execute([$roadId]);
        return $stmt->rowCount() > 0;
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
     * Find the most recently updated session for a user on a road,
     * regardless of status (active, completed, whatever). Used for
     * finalized roads, where there is no active session to resume but
     * the UI (e.g. "Download Road Score PDF") still needs a session_id
     * to build the report link.
     *
     * @return array{id:int,public_id:string}|null
     */
    public function findLatestSession(int $userId, int $roadId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, public_id
               FROM audit_sessions
              WHERE user_id = ? AND road_id = ?
              ORDER BY updated_at DESC
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
