<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/city/audit_assign.php
//  POST { audit_id, surveyor_id, segment_ids: [..] }   assign segments
//  POST { audit_id, surveyor_id, road_id }             assign a whole road
//  surveyor_id null / "" / 0 clears the assignment.
//  Only pending segments of the City Leader's own audit can change.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/city/audit_assign.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityLeaderAssign.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/CityAudit.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../../repositories/NotificationRepository.php';
require_once __DIR__ . '/../../helpers/Notifications.php';

$body = cityApiContext($CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID);
$repo = new CityAuditRepository($pdo);

$auditId = filter_var($body['audit_id'] ?? null, FILTER_VALIDATE_INT);
$audit   = ($auditId !== false && $auditId !== null) ? $repo->find((int)$auditId) : null;
if ($audit === null || !projectOpenableBy($audit, $CURRENT_USER_ROLE, $CURRENT_USER_ID, (int)$CURRENT_USER_CITY_ID)) {
    cityApiFail(404, 'Audit not found.');
}
cityApiFailIfDeleted($audit);

$v = cityAuditValidateAssignment($body);
if ($v['errors']) {
    cityApiFail(422, (string)reset($v['errors']), ['errors' => $v['errors']]);
}
$c = $v['clean'];

// Remember who holds these segments now, so whoever loses them can be told afterwards.
$before = [];
$touched = [];
try {
    $touched = $c['mode'] === 'road'
        ? $repo->segmentIdsOfRoad((int)$audit['id'], (int)$c['road_id'], true)
        : array_map('intval', $c['segment_ids']);
    $before = $repo->assigneesOfSegments((int)$audit['id'], $touched);
} catch (Throwable $e) {
    error_log('api/city/audit_assign.php lookup failed: ' . $e->getMessage());
}

try {
    if ($c['mode'] === 'road') {
        $res = $repo->assignRoad($audit, $CURRENT_USER_ID, (int)$c['road_id'], $c['surveyor_id']);
    } else {
        $res = ['assigned' => $repo->assignSegments($audit, $CURRENT_USER_ID, $c['segment_ids'], $c['surveyor_id']), 'skipped' => 0];
    }
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}

ActivityLogger::log($pdo, ActivityLogger::AUDIT_SEGMENTS_ASSIGNED, $CURRENT_USER_ID, [
    'audit_id'    => (int)$audit['id'],
    'surveyor_id' => $c['surveyor_id'],
    'road_id'     => $c['road_id'],
    'segments'    => $res['assigned'],
]);

// Tell the people involved. A failure here must never undo or fail the assignment.
// The new surveyor hears only about segments they did not already have, and a surveyor
// who loses segments (moved to someone else, or cleared) is told too.
try {
    $plan = assignmentNotifyPlan($before, $touched, $c['surveyor_id']);
    $notifier = new NotificationRepository($pdo);

    // If the lookup above failed we cannot tell what was already theirs, so tell them the lot.
    $gained = $touched ? $plan['gained'] : (int)$res['assigned'];
    if ($c['surveyor_id'] !== null && $gained > 0) {
        $roadName = null;
        if ($c['mode'] === 'road') {
            $rn = $pdo->prepare('SELECT name FROM roads WHERE id = ?');
            $rn->execute([(int)$c['road_id']]);
            $roadName = ($rn->fetchColumn() ?: null);
        }
        $text = assignmentNotificationText((string)$audit['name'], (string)$audit['status'], $gained, $roadName);
        $notifier->create((int)$c['surveyor_id'], NOTIFY_ASSIGNED, $text['title'], $text['body'], 'dashboard.php');
    }
    $notifier->tellUnassigned($plan['lost'], (string)$audit['name'], $c['surveyor_id'] === null ? 'cleared' : 'moved');
} catch (Throwable $e) {
    error_log('api/city/audit_assign.php notification failed: ' . $e->getMessage());
}

echo json_encode(['success' => true] + $res);
