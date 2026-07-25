<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/dashboard_overview.php
//  GET — org-wide stats + pending verification queue + recent
//        activity feed, for the admin dashboard section.
//  Admin-only (gated by config/admin_guard.php).
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/dashboard_overview.php error: ' . $e->getMessage());
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

// ── Org-wide KPIs ───────────────────────────────────────────────
$roadGroupStmt = $pdo->query(
    'SELECT COUNT(*) AS total, SUM(is_verified) AS verified
       FROM road_groups'
);
$rg = $roadGroupStmt->fetch(PDO::FETCH_ASSOC);

$segmentStmt = $pdo->query(
    "SELECT COUNT(*) AS total, SUM(status = 'completed') AS completed
       FROM segments"
);
$seg = $segmentStmt->fetch(PDO::FETCH_ASSOC);

$activeSessionsStmt = $pdo->query(
    "SELECT COUNT(*) AS total FROM audit_sessions WHERE status = 'active'"
);
$activeSessions = (int)$activeSessionsStmt->fetchColumn();

$surveyorStmt = $pdo->query(
    "SELECT COUNT(*) AS total FROM users WHERE role = 'surveyor'"
);
$totalSurveyors = (int)$surveyorStmt->fetchColumn();

$totalLengthStmt = $pdo->query(
    "SELECT COALESCE(SUM(total_length), 0) AS total
       FROM roads
      WHERE total_length IS NOT NULL"
);
$totalLengthAudited = (float)$totalLengthStmt->fetchColumn();

// ── Pending verification queue ──────────────────────────────────
$pendingStmt = $pdo->prepare(
    'SELECT
        g.id,
        g.canonical_name,
        g.created_at,
        (SELECT COUNT(*) FROM roads r WHERE r.road_group_id = g.id) AS member_count
     FROM road_groups g
    WHERE g.is_verified = 0
    ORDER BY g.created_at ASC
    LIMIT 10'
);
$pendingStmt->execute();
$pendingQueue = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($pendingQueue as &$p) {
    $p['id']           = (int)$p['id'];
    $p['member_count'] = (int)$p['member_count'];
}
unset($p);

$pendingTotalStmt = $pdo->query(
    'SELECT COUNT(*) FROM road_groups WHERE is_verified = 0'
);
$pendingTotal = (int)$pendingTotalStmt->fetchColumn();

// ── Audits over time (last 30 days, zero-filled) ─────────────────
$overTimeStmt = $pdo->query(
    "SELECT DATE(created_at) AS d, COUNT(*) AS total
       FROM segment_audits
      WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
      GROUP BY DATE(created_at)"
);
$overTimeRows = [];
foreach ($overTimeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $overTimeRows[$row['d']] = (int)$row['total'];
}
$auditsOverTime = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $auditsOverTime[] = ['date' => $day, 'total' => $overTimeRows[$day] ?? 0];
}

// ── Audits by surveyor (top 8) ────────────────────────────────────
$bySurveyorStmt = $pdo->query(
    'SELECT
        u.id,
        u.name,
        u.organisation,
        COUNT(*) AS total
     FROM segment_audits sa
     JOIN users u ON u.id = sa.surveyor_id
    GROUP BY u.id, u.name, u.organisation
    ORDER BY total DESC, u.name ASC
    LIMIT 8'
);
$bySurveyor = $bySurveyorStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($bySurveyor as &$sv) {
    $sv['id']    = (int)$sv['id'];
    $sv['total'] = (int)$sv['total'];
}
unset($sv);

// ── Audits by organisation (top 8) ────────────────────────────────
$byOrgStmt = $pdo->query(
    "SELECT
        COALESCE(NULLIF(TRIM(u.organisation), ''), 'Unspecified') AS organisation,
        COUNT(*) AS total
     FROM segment_audits sa
     JOIN users u ON u.id = sa.surveyor_id
    GROUP BY organisation
    ORDER BY total DESC, organisation ASC
    LIMIT 8"
);
$byOrganisation = $byOrgStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($byOrganisation as &$og) {
    $og['total'] = (int)$og['total'];
}
unset($og);

// ── Recent activity feed ────────────────────────────────────────
// Currently only segment_submitted / segment_edited are logged in
// practice (see helpers/ActivityLogger.php for the full action
// list — others are defined but not yet wired up at call sites).
$activityStmt = $pdo->prepare(
    "SELECT
        al.id,
        al.action,
        al.created_at,
        u.name AS user_name,
        r.name AS road_name,
        s.segment_number
     FROM activity_log al
     LEFT JOIN users u    ON u.id = al.user_id
     LEFT JOIN segments s ON s.id = (al.meta->>'$.segment_id') + 0
     LEFT JOIN roads r    ON r.id = s.road_id
    WHERE al.action IN ('segment_submitted', 'segment_edited')
    ORDER BY al.created_at DESC
    LIMIT 15"
);
$activityStmt->execute();
$recentActivity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($recentActivity as &$a) {
    $a['id']             = (int)$a['id'];
    $a['segment_number']  = $a['segment_number'] !== null ? (int)$a['segment_number'] : null;
}
unset($a);

echo json_encode([
    'success' => true,
    'org_stats' => [
        'total_roads'          => (int)$rg['total'],
        'verified_roads'       => (int)($rg['verified'] ?? 0),
        'pending_roads'        => $pendingTotal,
        'total_segments'       => (int)$seg['total'],
        'completed_segments'   => (int)($seg['completed'] ?? 0),
        'active_sessions'      => $activeSessions,
        'total_surveyors'      => $totalSurveyors,
        'total_length_audited' => $totalLengthAudited,
    ],
    'pending_queue'   => $pendingQueue,
    'recent_activity' => $recentActivity,
    'audits_over_time'=> $auditsOverTime,
    'by_surveyor'     => $bySurveyor,
    'by_organisation' => $byOrganisation,
]);
