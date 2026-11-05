<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/city/audit_segment_add.php
//  POST { audit_id, road_id, segment_length }
//  Adds one segment to the end of a road that is already in the
//  audit (for a stretch that was missed, or a road that turned out
//  longer than planned).
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/city/audit_segment_add.php error: ' . $e->getMessage());
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

$body = cityApiContext($CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID);
$repo = new CityAuditRepository($pdo);

$auditId = filter_var($body['audit_id'] ?? null, FILTER_VALIDATE_INT);
$roadId  = filter_var($body['road_id'] ?? null, FILTER_VALIDATE_INT);
$audit   = ($auditId !== false && $auditId !== null) ? $repo->find((int)$auditId) : null;
if ($audit === null || !projectOpenableBy($audit, $CURRENT_USER_ROLE, $CURRENT_USER_ID, (int)$CURRENT_USER_CITY_ID)) {
    cityApiFail(404, 'Audit not found.');
}
cityApiFailIfDeleted($audit);
if ($roadId === false || $roadId === null) {
    cityApiFail(400, 'Invalid request.');
}

$len = filter_var($body['segment_length'] ?? null, FILTER_VALIDATE_FLOAT);
if ($len === false || $len === null || $len < CITY_AUDIT_MIN_SEGMENT_LENGTH) {
    cityApiFail(422, 'Segment length must be at least 10 metres.', ['errors' => ['segment_length' => 'Segment length must be at least 10 metres.']]);
}

try {
    $res = $repo->addSegment($audit, $CURRENT_USER_ID, (int)$roadId, (float)$len);
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}

ActivityLogger::log($pdo, ActivityLogger::AUDIT_SEGMENT_ADDED, $CURRENT_USER_ID, [
    'audit_id' => (int)$audit['id'], 'road_id' => $res['road_id'], 'segment_id' => $res['segment_id'],
]);

echo json_encode(['success' => true] + $res);
