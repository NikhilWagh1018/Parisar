<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/AdminDashboardRepository.php
//  Read-only queries for the Admin dashboard: every city, every
//  audit, and the road names waiting to be verified.
//  Plain SQL that runs on MySQL and on SQLite (the tests use SQLite).
// ═══════════════════════════════════════════════════════════════

class AdminDashboardRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * One row per city with its people, roads and segment progress.
     *
     * @return list<array<string,mixed>>
     */
    public function cities(): array
    {
        $rows = $this->pdo->query(
            "SELECT c.id, c.name,
                (SELECT COUNT(*) FROM users u WHERE u.city_id = c.id AND u.role = 'city_admin')  AS leaders,
                (SELECT COUNT(*) FROM users u WHERE u.city_id = c.id AND u.role = 'surveyor')    AS surveyors,
                (SELECT COUNT(*) FROM road_groups rg WHERE rg.city_id = c.id)                    AS road_groups,
                (SELECT COUNT(*) FROM segments s
                   JOIN roads r ON r.id = s.road_id
                   JOIN road_groups rg ON rg.id = r.road_group_id
                  WHERE rg.city_id = c.id)                                                       AS segs,
                (SELECT COUNT(*) FROM segments s
                   JOIN roads r ON r.id = s.road_id
                   JOIN road_groups rg ON rg.id = r.road_group_id
                  WHERE rg.city_id = c.id AND s.status = 'completed')                            AS done
               FROM cities c
              ORDER BY c.name ASC, c.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            foreach (['id', 'leaders', 'surveyors', 'road_groups', 'segs', 'done'] as $k) {
                $r[$k] = (int)$r[$k];
            }
        }
        unset($r);
        return $rows;
    }

    /**
     * Every audit except voided ones, with segment progress and the time of the
     * latest segment submission ('last_activity'; falls back to the audit's own
     * last change when nothing has been submitted yet).
     *
     * @return list<array<string,mixed>>
     */
    public function audits(): array
    {
        $rows = $this->pdo->query(
            "SELECT a.id, a.name, a.audit_year, a.status, a.city_id, c.name AS city_name, a.updated_at,
                    COUNT(DISTINCT r.id) AS road_count,
                    COUNT(s.id)          AS segment_count,
                    SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) AS done_count
               FROM city_audits a
               JOIN cities c ON c.id = a.city_id
               LEFT JOIN roads r    ON r.audit_id = a.id
               LEFT JOIN segments s ON s.road_id  = r.id
              WHERE a.status <> 'voided'
              GROUP BY a.id, a.name, a.audit_year, a.status, a.city_id, c.name, a.updated_at"
        )->fetchAll(PDO::FETCH_ASSOC);

        $last = [];
        foreach ($this->pdo->query(
            "SELECT r.audit_id AS audit_id, MAX(sa.created_at) AS last_at
               FROM segment_audits sa
               JOIN segments s ON s.id = sa.segment_id
               JOIN roads r    ON r.id = s.road_id
              WHERE r.audit_id IS NOT NULL
              GROUP BY r.audit_id"
        )->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $last[(int)$l['audit_id']] = (string)$l['last_at'];
        }

        foreach ($rows as &$r) {
            $r['id']            = (int)$r['id'];
            $r['road_count']    = (int)$r['road_count'];
            $r['segment_count'] = (int)$r['segment_count'];
            $r['done_count']    = (int)$r['done_count'];
            $r['last_activity'] = $last[$r['id']] ?? (string)$r['updated_at'];
        }
        unset($r);
        return $rows;
    }

    /** How many road names are waiting to be verified. */
    public function roadsToVerifyCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM road_groups WHERE is_verified = 0')->fetchColumn();
    }
}
