<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/CityAuditRepository.php
//  City audits and the roads / segments that belong to them.
//  Road rows made here follow the same shape as the surveyor flow
//  (roads + segments), tied to the audit through roads.audit_id.
//  User-facing problems are thrown as DomainException (safe to show).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../helpers/CityAudit.php';

class CityAuditRepository
{
    /** Audit statuses in which roads can still be added or removed. */
    public const EDITABLE_STATUSES = ['draft'];

    /** Audit statuses in which segments can still be (re)assigned to surveyors. */
    public const ASSIGNABLE_STATUSES = ['draft', 'active', 'in_review'];

    public function __construct(private PDO $pdo) {}

    /** @param array{name:string,state:string,audit_date:string,audit_year:int,programme_info:?string} $clean */
    public function createAudit(int $cityId, int $userId, array $clean): int
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO city_audits (city_id, state, name, audit_year, audit_date, programme_info, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $cityId, $clean['state'], $clean['name'], $clean['audit_year'], $clean['audit_date'],
                $clean['programme_info'], $userId,
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new DomainException('An audit with this name and year already exists for your city.');
            }
            throw $e;
        }
        return (int)$this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function listForCity(int $cityId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.name, a.state, a.audit_year, a.audit_date, a.status, a.created_at,
                    COUNT(DISTINCT r.id) AS road_count,
                    COUNT(s.id)          AS segment_count,
                    SUM(CASE WHEN s.status = \'completed\' THEN 1 ELSE 0 END) AS done_count
               FROM city_audits a
               LEFT JOIN roads r    ON r.audit_id = a.id
               LEFT JOIN segments s ON s.road_id  = r.id
              WHERE a.city_id = ?
              GROUP BY a.id, a.name, a.state, a.audit_year, a.audit_date, a.status, a.created_at
              ORDER BY a.created_at DESC, a.id DESC'
        );
        $stmt->execute([$cityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $auditId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.city_id, a.state, a.name, a.audit_year, a.audit_date, a.programme_info,
                    a.status, a.created_by, a.created_at,
                    c.name AS city_name, u.name AS created_by_name
               FROM city_audits a
               JOIN cities c ON c.id = a.city_id
               LEFT JOIN users u ON u.id = a.created_by
              WHERE a.id = ?'
        );
        $stmt->execute([$auditId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function isEditable(array $audit): bool
    {
        return in_array($audit['status'], self::EDITABLE_STATUSES, true);
    }

    public function canAssign(array $audit): bool
    {
        return in_array($audit['status'], self::ASSIGNABLE_STATUSES, true);
    }

    /** City roads not yet in this audit.
     *  @return list<array{id:int,canonical_name:string}> */
    public function availableRoadGroups(int $cityId, int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rg.id, rg.canonical_name
               FROM road_groups rg
              WHERE rg.city_id = ? AND rg.is_flagged = 0
                AND NOT EXISTS (SELECT 1 FROM roads r
                                 WHERE r.audit_id = ? AND r.road_group_id = rg.id)
              ORDER BY rg.canonical_name ASC'
        );
        $stmt->execute([$cityId, $auditId]);
        return array_map(
            static fn(array $r): array => ['id' => (int)$r['id'], 'canonical_name' => (string)$r['canonical_name']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** Roads of an audit, each with its segments.
     *  @return list<array<string,mixed>> */
    public function roadsWithSegments(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, name, road_group_id, total_length, segment_length, finalized_at
               FROM roads WHERE audit_id = ? ORDER BY name ASC, id ASC'
        );
        $stmt->execute([$auditId]);
        $roads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$roads) {
            return [];
        }

        $ids  = array_map(static fn(array $r): int => (int)$r['id'], $roads);
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $segs = $this->pdo->prepare(
            "SELECT s.id AS segment_id, s.road_id, s.segment_number, s.start_distance, s.end_distance,
                    s.length, s.status, sa.surveyor_id AS assigned_to, u.name AS assigned_name
               FROM segments s
               LEFT JOIN segment_assignments sa ON sa.segment_id = s.id
               LEFT JOIN users u ON u.id = sa.surveyor_id
              WHERE s.road_id IN ($in) ORDER BY s.road_id ASC, s.segment_number ASC"
        );
        $segs->execute($ids);
        $byRoad = [];
        foreach ($segs->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $byRoad[(int)$s['road_id']][] = $s;
        }
        foreach ($roads as &$r) {
            $r['segments'] = $byRoad[(int)$r['id']] ?? [];
        }
        unset($r);
        return $roads;
    }

    /**
     * Add a city road to an audit and generate its segments.
     *
     * @param array<string,mixed> $audit  row from find()
     * @return array{road_id:int, public_id:string, segment_count:int}
     */
    public function addRoad(array $audit, int $userId, int $roadGroupId, float $totalLength, float $segmentLength): array
    {
        if (!$this->isEditable($audit)) {
            throw new DomainException('Roads can no longer be added to this audit.');
        }

        $g = $this->pdo->prepare(
            'SELECT id, canonical_name FROM road_groups
              WHERE id = ? AND city_id = ? AND is_flagged = 0'
        );
        $g->execute([$roadGroupId, (int)$audit['city_id']]);
        $group = $g->fetch(PDO::FETCH_ASSOC);
        if ($group === false) {
            throw new DomainException('That road was not found in this city.');
        }

        $plan = cityAuditSegmentPlan($totalLength, $segmentLength);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO roads
                   (public_id, creator_id, name, road_group_id, audit_id,
                    total_length, segment_method, segment_length)
                 VALUES (?, ?, ?, ?, ?, ?, \'auto\', ?)'
            )->execute([
                'T' . bin2hex(random_bytes(8)), $userId, (string)$group['canonical_name'],
                (int)$group['id'], (int)$audit['id'], $totalLength, $segmentLength,
            ]);
            $roadId   = (int)$this->pdo->lastInsertId();
            $publicId = 'ROAD-' . str_pad((string)$roadId, 4, '0', STR_PAD_LEFT);
            $this->pdo->prepare('UPDATE roads SET public_id = ? WHERE id = ?')
                      ->execute([$publicId, $roadId]);

            $ins = $this->pdo->prepare(
                'INSERT INTO segments
                   (public_id, road_id, segment_number, start_label, end_label,
                    start_distance, end_distance, length)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $upd = $this->pdo->prepare('UPDATE segments SET public_id = ? WHERE id = ?');
            foreach ($plan as $seg) {
                $ins->execute([
                    'SEG-R' . $roadId . 'N' . $seg['segment_number'], $roadId, $seg['segment_number'],
                    $seg['start_label'], $seg['end_label'],
                    $seg['start_distance'], $seg['end_distance'], $seg['length'],
                ]);
                $segId = (int)$this->pdo->lastInsertId();
                $upd->execute(['SEG-' . str_pad((string)$segId, 4, '0', STR_PAD_LEFT), $segId]);
            }
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            if ((string)$e->getCode() === '23000') {
                throw new DomainException('That road is already in this audit.');
            }
            throw $e;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['road_id' => $roadId, 'public_id' => $publicId, 'segment_count' => count($plan)];
    }

    /** Remove a road (and its segments) from an audit while nothing has been audited on it. */
    public function removeRoad(array $audit, int $roadId): void
    {
        if (!$this->isEditable($audit)) {
            throw new DomainException('Roads can no longer be removed from this audit.');
        }
        $r = $this->pdo->prepare('SELECT id, finalized_at FROM roads WHERE id = ? AND audit_id = ?');
        $r->execute([$roadId, (int)$audit['id']]);
        $road = $r->fetch(PDO::FETCH_ASSOC);
        if ($road === false) {
            throw new DomainException('That road is not part of this audit.');
        }
        if ($road['finalized_at'] !== null) {
            throw new DomainException('This road is already finalised and cannot be removed.');
        }
        $started = $this->pdo->prepare(
            'SELECT COUNT(*) FROM segments WHERE road_id = ? AND status <> \'pending\''
        );
        $started->execute([$roadId]);
        if ((int)$started->fetchColumn() > 0) {
            throw new DomainException('Auditing has already started on this road, so it cannot be removed.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM segment_assignments WHERE segment_id IN (SELECT id FROM segments WHERE road_id = ?)'
            )->execute([$roadId]);
            $this->pdo->prepare('DELETE FROM segments WHERE road_id = ?')->execute([$roadId]);
            $this->pdo->prepare('DELETE FROM roads WHERE id = ?')->execute([$roadId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ── Surveyor assignment (Slice 3) ─────────────────────────────

    /** Active surveyors of a city, for the assignment dropdowns.
     *  @return list<array{id:int,name:string}> */
    public function assignableSurveyors(int $cityId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, name FROM users
              WHERE role = 'surveyor' AND is_active = 1 AND city_id = ?
              ORDER BY name ASC, id ASC"
        );
        $stmt->execute([$cityId]);
        return array_map(
            static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function requireCitySurveyor(int $cityId, int $surveyorId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM users
              WHERE id = ? AND role = 'surveyor' AND is_active = 1 AND city_id = ?"
        );
        $stmt->execute([$surveyorId, $cityId]);
        if ((int)$stmt->fetchColumn() === 0) {
            throw new DomainException('That surveyor is not an active surveyor in this city.');
        }
    }

    /**
     * Give the segments to one surveyor, or clear them when $surveyorId is null.
     * Only pending segments of this audit can change.
     *
     * @param array<string,mixed> $audit      row from find()
     * @param list<int>           $segmentIds
     * @return int number of segments updated
     */
    public function assignSegments(array $audit, int $byUserId, array $segmentIds, ?int $surveyorId): int
    {
        if (!$this->canAssign($audit)) {
            throw new DomainException('Surveyors can no longer be assigned in this audit.');
        }
        $ids = array_values(array_unique(array_map('intval', $segmentIds)));
        if (!$ids) {
            throw new DomainException('Select at least one segment.');
        }
        if ($surveyorId !== null) {
            $this->requireCitySurveyor((int)$audit['city_id'], $surveyorId);
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $q  = $this->pdo->prepare(
            "SELECT s.id, s.status FROM segments s
               JOIN roads r ON r.id = s.road_id
              WHERE r.audit_id = ? AND s.id IN ($in)"
        );
        $q->execute(array_merge([(int)$audit['id']], $ids));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($ids)) {
            throw new DomainException('Some of those segments are not part of this audit.');
        }
        foreach ($rows as $row) {
            if ($row['status'] !== 'pending') {
                throw new DomainException('Auditing has already started on a segment, so it cannot be reassigned.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM segment_assignments WHERE segment_id IN ($in)")->execute($ids);
            if ($surveyorId !== null) {
                $ins = $this->pdo->prepare(
                    'INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id, assigned_by)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ($ids as $segId) {
                    $ins->execute([(int)$audit['id'], $segId, $surveyorId, $byUserId]);
                }
            }
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            if ((string)$e->getCode() === '23000') {
                throw new DomainException('Someone changed these segments at the same time. Reload and try again.');
            }
            throw $e;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return count($ids);
    }

    /**
     * Give every pending segment of a road to one surveyor (null clears them).
     * Segments where auditing already started are left alone and counted as skipped.
     *
     * @return array{assigned:int, skipped:int}
     */
    public function assignRoad(array $audit, int $byUserId, int $roadId, ?int $surveyorId): array
    {
        $r = $this->pdo->prepare('SELECT id FROM roads WHERE id = ? AND audit_id = ?');
        $r->execute([$roadId, (int)$audit['id']]);
        if ($r->fetch(PDO::FETCH_ASSOC) === false) {
            throw new DomainException('That road is not part of this audit.');
        }
        $s = $this->pdo->prepare('SELECT id, status FROM segments WHERE road_id = ? ORDER BY segment_number ASC');
        $s->execute([$roadId]);
        $pending = [];
        $skipped = 0;
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['status'] === 'pending') {
                $pending[] = (int)$row['id'];
            } else {
                $skipped++;
            }
        }
        if (!$pending) {
            throw new DomainException('Auditing has already started on every segment of this road.');
        }
        $n = $this->assignSegments($audit, $byUserId, $pending, $surveyorId);
        return ['assigned' => $n, 'skipped' => $skipped];
    }

    /** @return array{total:int, assigned:int} segment counts for the whole audit */
    public function assignmentCounts(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(s.id) AS total, COUNT(sa.id) AS assigned
               FROM roads r
               JOIN segments s ON s.road_id = r.id
               LEFT JOIN segment_assignments sa ON sa.segment_id = s.id
              WHERE r.audit_id = ?'
        );
        $stmt->execute([$auditId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['total' => (int)($row['total'] ?? 0), 'assigned' => (int)($row['assigned'] ?? 0)];
    }

    /**
     * Move a draft audit to active. Every segment must have an active surveyor
     * of this city. From then on roads can no longer be added or removed.
     */
    public function activate(array $audit): void
    {
        if ($audit['status'] !== 'draft') {
            throw new DomainException('Only a draft audit can be activated.');
        }
        $c = $this->assignmentCounts((int)$audit['id']);
        if ($c['total'] === 0) {
            throw new DomainException('Add at least one road before activating this audit.');
        }
        if ($c['assigned'] < $c['total']) {
            $left = $c['total'] - $c['assigned'];
            throw new DomainException($left . ($left === 1 ? ' segment still has' : ' segments still have')
                . ' no surveyor. Assign every segment before activating.');
        }

        $bad = $this->pdo->prepare(
            "SELECT COUNT(*) FROM segment_assignments sa
               JOIN segments s ON s.id = sa.segment_id
               JOIN roads r    ON r.id = s.road_id
               LEFT JOIN users u ON u.id = sa.surveyor_id
                                AND u.role = 'surveyor' AND u.is_active = 1 AND u.city_id = ?
              WHERE r.audit_id = ? AND u.id IS NULL"
        );
        $bad->execute([(int)$audit['city_id'], (int)$audit['id']]);
        if ((int)$bad->fetchColumn() > 0) {
            throw new DomainException('Some segments are assigned to a surveyor who is no longer active in this city. Reassign them first.');
        }

        $upd = $this->pdo->prepare("UPDATE city_audits SET status = 'active' WHERE id = ? AND status = 'draft'");
        $upd->execute([(int)$audit['id']]);
        if ($upd->rowCount() === 0) {
            throw new DomainException('This audit was already activated.');
        }
    }
}
