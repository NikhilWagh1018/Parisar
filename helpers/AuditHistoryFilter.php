<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/AuditHistoryFilter.php
//  Shared status/date-range/sort/score logic for a user's personal
//  audit history — used by BOTH api/user/audit_history_list.php
//  (paginated, for the on-screen list) and api/user/audit_export.php
//  (unpaginated, for the Excel export), so the two can never drift
//  apart on what "filtered" means.
// ═══════════════════════════════════════════════════════════════

/**
 * Normalize, filter, score, and sort a user's personal audit rows.
 * Does NOT paginate — callers slice the result themselves if needed.
 *
 * @param list<array<string,mixed>> $rows   Raw rows from
 *        SegmentRepository::personalAuditList().
 * @param string $status 'all' | 'active' | 'completed'
 * @param string $range  'all' | 'week' | 'month'
 * @param string $sort   'recent' | 'name' | 'score'
 * @param PDO    $pdo    Needed for calculateScoresForAuditIds().
 *
 * @return list<array<string,mixed>>
 */
function filterAndSortAuditRows(array $rows, string $status, string $range, string $sort, PDO $pdo): array
{
    if (!in_array($status, ['all', 'active', 'completed'], true)) {
        $status = 'all';
    }
    if (!in_array($range, ['all', 'week', 'month'], true)) {
        $range = 'all';
    }
    if (!in_array($sort, ['recent', 'name', 'score'], true)) {
        $sort = 'recent';
    }

    // Normalize BEFORE filtering: a segment with no matching audit_sessions
    // row (legacy data, or the session was deleted) should read as
    // "completed" consistently in both the filter and the display value —
    // not silently vanish from every status filter.
    foreach ($rows as &$r) {
        $r['session_status'] = $r['session_status'] ?? 'completed';
    }
    unset($r);

    // ── Filter: status (maps to the road's audit_session status) ──
    if ($status !== 'all') {
        $rows = array_values(array_filter(
            $rows,
            static fn(array $r): bool => $r['session_status'] === $status
        ));
    }

    // ── Filter: date range (on this segment's own audit date) ─────
    if ($range !== 'all') {
        $cutoff = $range === 'week'
            ? new DateTime('-7 days')
            : new DateTime('-30 days');

        $rows = array_values(array_filter(
            $rows,
            static function (array $r) use ($cutoff): bool {
                if (empty($r['created_at'])) {
                    return false;
                }
                return new DateTime($r['created_at']) >= $cutoff;
            }
        ));
    }

    // ── Batch-compute condition scores for whatever survived filtering ──
    $auditIds = array_map(static fn(array $r): int => (int)$r['audit_id'], $rows);
    $scores   = calculateScoresForAuditIds($auditIds, $pdo);

    foreach ($rows as &$r) {
        $auditId = (int)$r['audit_id'];
        $score   = $scores[$auditId] ?? null;

        $r['audit_id']        = $auditId;
        $r['segment_id']      = (int)$r['segment_id'];
        $r['road_id']         = (int)$r['road_id'];
        $r['segment_number']  = (int)$r['segment_number'];
        $r['segment_width']   = $r['segment_width']  !== null ? (float)$r['segment_width']  : null;
        $r['segment_length']  = $r['segment_length'] !== null ? (float)$r['segment_length'] : null;
        $r['condition']       = $score['condition'] ?? null;
        $r['score']           = $score['final']      ?? null;
    }
    unset($r);

    // ── Sort ────────────────────────────────────────────────────
    usort($rows, static function (array $a, array $b) use ($sort): int {
        return match ($sort) {
            'name'  => strcasecmp($a['road_name'], $b['road_name']),
            // Higher penalty score = worse condition; worst first for the
            // re-verification-queue use case.
            'score' => ($b['score'] ?? -1) <=> ($a['score'] ?? -1),
            default => strcmp($b['created_at'], $a['created_at']), // recent first
        };
    });

    return $rows;
}
