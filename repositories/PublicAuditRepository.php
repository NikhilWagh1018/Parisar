<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/PublicAuditRepository.php
//  Read-only queries for the public landing page. PUBLISHED audits only.
//  Plain SQL that runs on MySQL and on SQLite (the tests use SQLite).
// ═══════════════════════════════════════════════════════════════

class PublicAuditRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Every published audit with its city, size and total road length (metres).
     *
     * @return list<array<string,mixed>>
     */
    public function published(): array
    {
        return $this->pdo->query(
            "SELECT a.id, a.name, a.state, a.audit_year, a.audit_date, a.city_id, c.name AS city_name,
                    COUNT(DISTINCT r.id)          AS road_count,
                    COUNT(s.id)                   AS segment_count,
                    COALESCE(SUM(s.length), 0)    AS length_m
               FROM city_audits a
               JOIN cities c ON c.id = a.city_id
               LEFT JOIN roads r    ON r.audit_id = a.id
               LEFT JOIN segments s ON s.road_id  = r.id
              WHERE a.status = 'published'
              GROUP BY a.id, a.name, a.state, a.audit_year, a.audit_date, a.city_id, c.name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Which audit each road belongs to.
     *
     * @param list<int> $auditIds
     * @return array<int,int> road id => audit id
     */
    public function roadAuditMap(array $auditIds): array
    {
        $auditIds = array_values(array_unique(array_map('intval', $auditIds)));
        if (!$auditIds) {
            return [];
        }
        $ph   = implode(',', array_fill(0, count($auditIds), '?'));
        $stmt = $this->pdo->prepare("SELECT id, audit_id FROM roads WHERE audit_id IN ($ph)");
        $stmt->execute($auditIds);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int)$r['id']] = (int)$r['audit_id'];
        }
        return $map;
    }
}
