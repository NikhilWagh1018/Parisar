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
//  Filtering/sorting/scoring logic lives in
//  helpers/AuditHistoryFilter.php — shared with audit_export.php so
//  the two can never disagree on what "matches the current filters"
//  means.
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
require_once __DIR__ . '/../../helpers/AuditHistoryFilter.php';

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

    $repo = new SegmentRepository($pdo);
    $rows = $repo->personalAuditList($CURRENT_USER_ID);
    $rows = filterAndSortAuditRows($rows, $status, $range, $sort, $pdo);

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
