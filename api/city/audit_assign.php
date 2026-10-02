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
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/CityAudit.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../repositories/CityAuditRepository.php';

$body = cityApiContext($CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID);
$repo = new CityAuditRepository($pdo);

$auditId = filter_var($body['audit_id'] ?? null, FILTER_VALIDATE_INT);
$audit   = ($auditId !== false && $auditId !== null) ? $repo->find((int)$auditId) : null;
if ($audit === null || (int)$audit['city_id'] !== (int)$CURRENT_USER_CITY_ID) {
    cityApiFail(404, 'Audit not found.');
}

$v = cityAuditValidateAssignment($body);
if ($v['errors']) {
    cityApiFail(422, (string)reset($v['errors']), ['errors' => $v['errors']]);
}
$c = $v['clean'];

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

echo json_encode(['success' => true] + $res);
