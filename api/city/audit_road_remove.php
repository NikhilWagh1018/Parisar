<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/city/audit_road_remove.php
//  POST { audit_id, road_id }
//  Removes a road (and its segments) from an audit, only while no
//  auditing has started on it.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/city/audit_road_remove.php error: ' . $e->getMessage());
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
$roadId  = filter_var($body['road_id'] ?? null, FILTER_VALIDATE_INT);
$audit   = ($auditId !== false && $auditId !== null) ? $repo->find((int)$auditId) : null;
if ($audit === null || (int)$audit['city_id'] !== (int)$CURRENT_USER_CITY_ID) {
    cityApiFail(404, 'Audit not found.');
}
if ($roadId === false || $roadId === null) {
    cityApiFail(400, 'Invalid request.');
}

try {
    $repo->removeRoad($audit, (int)$roadId);
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}

ActivityLogger::log($pdo, ActivityLogger::AUDIT_ROAD_REMOVED, $CURRENT_USER_ID, [
    'audit_id' => (int)$audit['id'], 'road_id' => (int)$roadId,
]);

echo json_encode(['success' => true]);
