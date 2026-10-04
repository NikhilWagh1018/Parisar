<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/CityDashboardRepository.php
//  Read-only numbers for the City Leader dashboard: one row per
//  audit with its progress counts. SQL is kept portable (MySQL in
//  production, SQLite in tests).
// ═══════════════════════════════════════════════════════════════

class CityDashboardRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Every audit of a city (newest first) with road, segment and review counts.
     * unassigned_count = segments with no surveyor yet.
     *
     * @return list<array<string,mixed>>
     */
    public function auditSummaries(int $cityId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.name, a.state, a.audit_year, a.status, a.created_at,
                    COUNT(DISTINCT r.id) AS road_count,
                    COUNT(s.id)          AS segment_count,
                    SUM(CASE WHEN sa.status = 'approved'      THEN 1 ELSE 0 END) AS approved_count,
                    SUM(CASE WHEN sa.status = 'submitted'     THEN 1 ELSE 0 END) AS submitted_count,
                    SUM(CASE WHEN sa.status = 'needs_revisit' THEN 1 ELSE 0 END) AS needs_revisit_count,
                    SUM(CASE WHEN sa.status = 'assigned'      THEN 1 ELSE 0 END) AS assigned_count,
                    SUM(CASE WHEN s.id IS NOT NULL AND sa.id IS NULL THEN 1 ELSE 0 END) AS unassigned_count
               FROM city_audits a
               LEFT JOIN roads r    ON r.audit_id = a.id
               LEFT JOIN segments s ON s.road_id  = r.id
               LEFT JOIN segment_assignments sa ON sa.segment_id = s.id
              WHERE a.city_id = ?
              GROUP BY a.id, a.name, a.state, a.audit_year, a.status, a.created_at
              ORDER BY a.created_at DESC, a.id DESC"
        );
        $stmt->execute([$cityId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            foreach (['road_count', 'segment_count', 'approved_count', 'submitted_count',
                      'needs_revisit_count', 'assigned_count', 'unassigned_count'] as $k) {
                $row[$k] = (int)($row[$k] ?? 0);
            }
        }
        unset($row);
        return $rows;
    }
}
