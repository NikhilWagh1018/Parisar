<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/user/audit_history_list.php
//  GET — filtered/sorted/paginated list of the logged-in user's
//        audited segments, for the "My Audits" page (Sections 2+4).
//
//  Query params (all optional):
//    status = all | active | completed        (default: all)
//    range  = all | week | month               (default: all)
//    sort   = recent | name | score            (default: recent)
//    page   = 1-based page number               (default: 1)
//
//  Condition score isn't a stored column (see ScoreService), so
//  filtering by date and sorting by score both happen in PHP after
//  a single batch score computation — not per-row DB calls.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../repositories/SegmentRepository.php';
require_once __DIR__ . '/../../services/ScoreService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

const PAGE_SIZE = 10;

try {
    $status = $_GET['status'] ?? 'all';
    $range  = $_GET['range']  ?? 'all';
    $sort   = $_GET['sort']   ?? 'recent';
    $page   = max(1, (int)($_GET['page'] ?? 1));

    if (!in_array($status, ['all', 'active', 'completed'], true)) {
        $status = 'all';
    }
    if (!in_array($range, ['all', 'week', 'month'], true)) {
        $range = 'all';
    }
    if (!in_array($sort, ['recent', 'name', 'score'], true)) {
        $sort = 'recent';
    }

    $repo = new SegmentRepository($pdo);
    $rows = $repo->personalAuditList($CURRENT_USER_ID);

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

    // ── Paginate (after filter + sort, so page counts are correct) ─
    $total      = count($rows);
    $totalPages = (int)max(1, ceil($total / PAGE_SIZE));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * PAGE_SIZE;
    $pageRows   = array_slice($rows, $offset, PAGE_SIZE);

    echo json_encode([
        'success'     => true,
        'items'       => $pageRows,
        'page'        => $page,
        'total_pages' => $totalPages,
        'total_items' => $total,
    ]);

} catch (PDOException $e) {
    error_log('api/user/audit_history_list.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while loading audit list.']);
}
