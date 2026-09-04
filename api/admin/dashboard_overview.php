<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/dashboard_overview.php
//  GET — org-wide stats + pending verification queue + recent
//        activity feed, for the admin dashboard section.
//  Admin-only (gated by config/admin_guard.php).
//  city_admin sees the same shape of data, scoped to their own
//  city only (via road_groups.city_id / users.city_id).
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

// A city_admin with no city_id assigned sees empty/zeroed data rather
// than falling through to org-wide — same fail-closed default used
// throughout the rest of the city-scoping work.
$isNationalAdmin = $CURRENT_USER_ROLE === 'national_admin';
$cityId          = $isNationalAdmin ? null : $CURRENT_USER_CITY_ID;
$cityBlocked     = !$isNationalAdmin && $cityId === null;

// ── Org-wide KPIs ───────────────────────────────────────────────
$roadGroupStmt = $pdo->prepare(
    'SELECT COUNT(*) AS total, SUM(is_verified) AS verified
       FROM road_groups
      WHERE (:cid1 IS NULL OR city_id = :cid2)'
);
$roadGroupStmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
$rg = $cityBlocked ? ['total' => 0, 'verified' => 0] : $roadGroupStmt->fetch(PDO::FETCH_ASSOC);

$segmentStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total, SUM(s.status = 'completed') AS completed
       FROM segments s
       JOIN roads r       ON r.id = s.road_id
       JOIN road_groups rg ON rg.id = r.road_group_id
      WHERE (:cid1 IS NULL OR rg.city_id = :cid2)"
);
$segmentStmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
$seg = $cityBlocked ? ['total' => 0, 'completed' => 0] : $segmentStmt->fetch(PDO::FETCH_ASSOC);

$activeSessionsStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total
       FROM audit_sessions ases
       JOIN roads r        ON r.id = ases.road_id
       JOIN road_groups rg ON rg.id = r.road_group_id
      WHERE ases.status = 'active'
        AND (:cid1 IS NULL OR rg.city_id = :cid2)"
);
$activeSessionsStmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
$activeSessions = $cityBlocked ? 0 : (int)$activeSessionsStmt->fetchColumn();

$surveyorStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total FROM users
      WHERE role = 'surveyor' AND (:cid1 IS NULL OR city_id = :cid2)"
);
$surveyorStmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
$totalSurveyors = $cityBlocked ? 0 : (int)$surveyorStmt->fetchColumn();

$totalLengthStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(r.total_length), 0) AS total
       FROM roads r
       JOIN road_groups rg ON rg.id = r.road_group_id
      WHERE r.total_length IS NOT NULL
        AND (:cid1 IS NULL OR rg.city_id = :cid2)"
);
$totalLengthStmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
$totalLengthAudited = $cityBlocked ? 0.0 : (float)$totalLengthStmt->fetchColumn();

// ── Pending verification queue ──────────────────────────────────
$pendingStmt = $pdo->prepare(
    'SELECT
        g.id,
        g.canonical_name,
        g.created_at,
        (SELECT COUNT(*) FROM roads r WHERE r.road_group_id = g.id) AS member_count
     FROM road_groups g
    WHERE g.is_verified = 0
      AND (:cid1 IS NULL OR g.city_id = :cid2)
    ORDER BY g.created_at ASC
    LIMIT 10'
);
$pendingStmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
$pendingQueue = $cityBlocked ? [] : $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($pendingQueue as &$p) {
    $p['id']           = (int)$p['id'];
    $p['member_count'] = (int)$p['member_count'];
}
unset($p);

$pendingTotalStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM road_groups WHERE is_verified = 0 AND (:cid1 IS NULL OR city_id = :cid2)'
);
$pendingTotalStmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
$pendingTotal = $cityBlocked ? 0 : (int)$pendingTotalStmt->fetchColumn();

// ── Audits over time (last 30 days, zero-filled) ─────────────────
$overTimeStmt = $pdo->prepare(
    "SELECT DATE(sa.created_at) AS d, COUNT(*) AS total
       FROM segment_audits sa
       JOIN segments s     ON s.id = sa.segment_id
       JOIN roads r        ON r.id = s.road_id
       JOIN road_groups rg ON rg.id = r.road_group_id
      WHERE sa.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        AND (:cid1 IS NULL OR rg.city_id = :cid2)
      GROUP BY DATE(sa.created_at)"
);
$overTimeStmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
$overTimeRows = [];
if (!$cityBlocked) {
    foreach ($overTimeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $overTimeRows[$row['d']] = (int)$row['total'];
    }
}
$auditsOverTime = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $auditsOverTime[] = ['date' => $day, 'total' => $overTimeRows[$day] ?? 0];
}

// ── Audits by surveyor (top 8) ────────────────────────────────────
$bySurveyorStmt = $pdo->prepare(
    'SELECT
        u.id,
        u.name,
        u.organisation,
        COUNT(*) AS total
     FROM segment_audits sa
     JOIN users u ON u.id = sa.surveyor_id
    WHERE (:cid1 IS NULL OR u.city_id = :cid2)
    GROUP BY u.id, u.name, u.organisation
    ORDER BY total DESC, u.name ASC
    LIMIT 8'
);
$bySurveyorStmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
$bySurveyor = $cityBlocked ? [] : $bySurveyorStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($bySurveyor as &$sv) {
    $sv['id']    = (int)$sv['id'];
    $sv['total'] = (int)$sv['total'];
}
unset($sv);

// ── Audits by organisation (top 8) ────────────────────────────────
$byOrgStmt = $pdo->prepare(
    "SELECT
        COALESCE(NULLIF(TRIM(u.organisation), ''), 'Unspecified') AS organisation,
        COUNT(*) AS total
     FROM segment_audits sa
     JOIN users u ON u.id = sa.surveyor_id
    WHERE (:cid1 IS NULL OR u.city_id = :cid2)
    GROUP BY organisation
    ORDER BY total DESC, organisation ASC
    LIMIT 8"
);
$byOrgStmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
$byOrganisation = $cityBlocked ? [] : $byOrgStmt->fetchAll(PDO::FETCH_ASSOC);
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
     LEFT JOIN road_groups rg ON rg.id = r.road_group_id
    WHERE al.action IN ('segment_submitted', 'segment_edited')
      AND (:cid1 IS NULL OR rg.city_id = :cid2)
    ORDER BY al.created_at DESC
    LIMIT 15"
);
$activityStmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
$recentActivity = $cityBlocked ? [] : $activityStmt->fetchAll(PDO::FETCH_ASSOC);
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
