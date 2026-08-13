<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/user/audit_continue.php
//  GET — roads the logged-in user can resume, for the "My Audits"
//        page (Section 3, "Continue where you left off").
//
//  A road qualifies if this user's most recent audit_session on it
//  is 'active' and it still has pending segments. Roads with an
//  active session but zero pending segments left are filtered out
//  here (nothing to resume there — edge case, shouldn't normally
//  happen but guards against a session that hasn't transitioned to
//  'completed' yet).
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

const MAX_CONTINUE_ROADS = 5;

try {
    $repo = new SegmentRepository($pdo);
    $rows = $repo->personalContinueAudits($CURRENT_USER_ID);

    $items = [];
    foreach ($rows as $r) {
        $total     = (int)$r['total_segments'];
        $completed = (int)$r['completed_segments'];
        $nextId    = $r['next_segment_id'] !== null ? (int)$r['next_segment_id'] : null;

        // Nothing pending on this road — skip it, nothing to resume.
        if ($nextId === null || $completed >= $total) {
            continue;
        }

        $items[] = [
            'road_id'             => (int)$r['road_id'],
            'road_name'           => $r['road_name'],
            'total_segments'      => $total,
            'completed_segments'  => $completed,
            'next_segment_id'     => $nextId,
            'next_segment_number' => (int)$r['next_segment_number'],
            'last_activity_at'    => $r['last_activity_at'],
        ];

        if (count($items) >= MAX_CONTINUE_ROADS) {
            break;
        }
    }

    echo json_encode([
        'success' => true,
        'items'   => $items,
    ]);

} catch (PDOException $e) {
    error_log('api/user/audit_continue.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while loading resume data.']);
}
