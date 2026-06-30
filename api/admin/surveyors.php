<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/surveyors.php
//  GET — all surveyor accounts (role = 'surveyor') with per-user
//        stats: roads created, segments audited, last activity.
//        Admin-only (gated by config/admin_guard.php).
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/surveyors.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$stmt = $pdo->query(
    "SELECT
        u.id,
        u.name,
        u.email,
        u.phone,
        u.organisation,
        u.profile_picture,
        u.last_login,
        u.created_at,
        (SELECT COUNT(*) FROM roads r WHERE r.creator_id = u.id) AS roads_created,
        (SELECT COUNT(*) FROM segment_audits sa WHERE sa.surveyor_id = u.id) AS segments_audited,
        (SELECT MAX(sa2.created_at) FROM segment_audits sa2 WHERE sa2.surveyor_id = u.id) AS last_audit_at
     FROM users u
    WHERE u.role = 'surveyor'
    ORDER BY u.name ASC"
);
$surveyors = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($surveyors as &$s) {
    $s['id']               = (int)$s['id'];
    $s['roads_created']    = (int)$s['roads_created'];
    $s['segments_audited'] = (int)$s['segments_audited'];
    // Don't ship full base64 profile pictures in a list endpoint —
    // just a boolean flag, the avatar initial is used as fallback.
    $s['has_profile_picture'] = $s['profile_picture'] !== null && $s['profile_picture'] !== '';
    unset($s['profile_picture']);
}
unset($s);

echo json_encode(['success' => true, 'surveyors' => $surveyors]);
