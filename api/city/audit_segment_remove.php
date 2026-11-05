<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/city/audit_segment_remove.php
//  POST { audit_id, segment_id }
//  Deletes one segment that was created by mistake, only while no
//  auditing has started on it.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/city/audit_segment_remove.php error: ' . $e->getMessage());
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

$auditId   = filter_var($body['audit_id'] ?? null, FILTER_VALIDATE_INT);
$segmentId = filter_var($body['segment_id'] ?? null, FILTER_VALIDATE_INT);
$audit     = ($auditId !== false && $auditId !== null) ? $repo->find((int)$auditId) : null;
if ($audit === null || !projectOpenableBy($audit, $CURRENT_USER_ROLE, $CURRENT_USER_ID, (int)$CURRENT_USER_CITY_ID)) {
    cityApiFail(404, 'Audit not found.');
}
cityApiFailIfDeleted($audit);
if ($segmentId === false || $segmentId === null) {
    cityApiFail(400, 'Invalid request.');
}

try {
    $res = $repo->removeSegment($audit, (int)$segmentId);
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}

ActivityLogger::log($pdo, ActivityLogger::AUDIT_SEGMENT_REMOVED, $CURRENT_USER_ID, [
    'audit_id' => (int)$audit['id'], 'road_id' => $res['road_id'], 'segment_id' => (int)$segmentId,
]);

echo json_encode(['success' => true, 'remaining' => $res['remaining']]);
