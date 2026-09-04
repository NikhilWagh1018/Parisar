<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/activity_log.php
//  GET — list of audit_log rows (road create/delete actions),
//        newest first, for the admin Activity Log page.
//  Admin-only (gated by config/admin_guard.php).
//
//  Note: audit_log is distinct from the surveyor-facing
//  activity_log table (segment_submitted/segment_edited). This
//  endpoint reads audit_log only — the create/delete trail written
//  by api/admin/roads.php. Do not conflate the two.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/activity_log.php error: ' . $e->getMessage());
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

$isNationalAdmin = $CURRENT_USER_ROLE === 'national_admin';
$cityId          = $isNationalAdmin ? null : $CURRENT_USER_CITY_ID;

// A city_admin with no city assigned sees an empty log rather than
// falling through to every city's entries.
if (!$isNationalAdmin && $cityId === null) {
    echo json_encode(['success' => true, 'entries' => []]);
    exit;
}

// audit_log is small by nature (one row per road create/delete), but
// cap the read as a sane safety net rather than trusting it'll stay
// small forever. Page filters/searches client-side, same convention
// as pages/admin.php and pages/admin_surveyors.php.
//
// LEFT JOIN so a city_admin still sees log entries whose road_group
// has since been deleted (rg will be NULL) rather than silently
// losing them — but those NULL-city rows are excluded for a
// city_admin (unknowable which city they belonged to), while a
// national_admin sees everything as before.
$stmt = $pdo->prepare(
    'SELECT al.id, al.actor_id, al.actor_name, al.action, al.road_group_id, al.road_group_name, al.created_at
       FROM audit_log al
       LEFT JOIN road_groups rg ON rg.id = al.road_group_id
      WHERE (:cid1 IS NULL OR rg.city_id = :cid2)
      ORDER BY al.created_at DESC, al.id DESC
      LIMIT 500'
);
$stmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
    $r['id']             = (int)$r['id'];
    $r['actor_id']       = $r['actor_id'] !== null ? (int)$r['actor_id'] : null;
    $r['road_group_id']  = (int)$r['road_group_id'];
}
unset($r);

echo json_encode(['success' => true, 'entries' => $rows]);
