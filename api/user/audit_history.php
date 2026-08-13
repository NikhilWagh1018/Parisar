<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/user/audit_history.php
//  GET — returns the logged-in user's personal audit summary stats
//        for the "My Audits" history page header strip.
//
//  Section 1 of the My Audits feature (summary strip only).
//  Segment list / filters / "continue where you left off" are
//  separate endpoints, added in later sections.
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

try {
    $repo  = new SegmentRepository($pdo);
    $stats = $repo->personalStats($CURRENT_USER_ID);

    echo json_encode([
        'success' => true,
        'stats'   => [
            'segments_audited' => $stats['segments_audited'],
            'total_length_km'  => round($stats['total_length_m'] / 1000, 2),
            'roads_touched'    => $stats['roads_touched'],
            'member_since'     => $stats['first_audit_at']
                ? substr($stats['first_audit_at'], 0, 10) // YYYY-MM-DD
                : null,
        ],
    ]);

} catch (PDOException $e) {
    error_log('api/user/audit_history.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while loading audit history.']);
}
