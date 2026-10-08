<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/PublicAudits.php
//  Pure rules for the public "Audit data" section of the landing page
//  (no DB, no session), so they are unit-tested. Only PUBLISHED audits
//  are ever shown, and only these fields: nothing about who created it.
// ═══════════════════════════════════════════════════════════════

/** The audit's date as YYYY-MM-DD. Older audits with no stored date use 1 January of their year. */
function publicAuditDate(?string $date, int $year): string
{
    if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
        return substr($date, 0, 10);
    }
    return sprintf('%04d-01-01', $year);
}

/**
 * One public audit, in the shape the landing page reads.
 *
 * @param array<string,mixed>      $row  published-audit row (see PublicAuditRepository::published)
 * @param array<string,mixed>|null $agg  cityReportAggregate() result, null when nothing is scored
 * @return array<string,mixed>
 */
function publicAuditShape(array $row, ?array $agg): array
{
    $date = publicAuditDate($row['audit_date'] ?? null, (int)$row['audit_year']);
    return [
        'id'        => (int)$row['id'],
        'name'      => (string)$row['name'],
        'city_id'   => (int)$row['city_id'],
        'city'      => (string)$row['city_name'],
        'state'     => (string)$row['state'],
        'date'      => $date,
        'year'      => (int)substr($date, 0, 4),
        'month'     => (int)substr($date, 5, 2),
        'roads'     => (int)$row['road_count'],
        'segments'  => (int)$row['segment_count'],
        'length_km' => round(((float)($row['length_m'] ?? 0)) / 1000, 1),
        'score'     => $agg === null ? null : (float)$agg['score'],
        'condition' => $agg === null ? null : (string)$agg['condition'],
    ];
}

/**
 * Newest audit first (by audit date, then id).
 *
 * @param list<array<string,mixed>> $audits shaped audits
 * @return list<array<string,mixed>>
 */
function publicSortAudits(array $audits): array
{
    usort($audits, static function (array $a, array $b): int {
        $d = strcmp((string)$b['date'], (string)$a['date']);
        return $d !== 0 ? $d : ((int)$b['id'] <=> (int)$a['id']);
    });
    return $audits;
}

/**
 * Cities that have at least one public audit, for the filter. A-Z.
 *
 * @param list<array<string,mixed>> $audits shaped audits
 * @return list<array{id:int,name:string}>
 */
function publicAuditCities(array $audits): array
{
    $seen = [];
    foreach ($audits as $a) {
        $seen[(int)$a['city_id']] = (string)$a['city'];
    }
    asort($seen, SORT_NATURAL | SORT_FLAG_CASE);
    $out = [];
    foreach ($seen as $id => $name) {
        $out[] = ['id' => $id, 'name' => $name];
    }
    return $out;
}
