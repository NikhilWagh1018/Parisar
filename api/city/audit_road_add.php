<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/city/audit_road_add.php
//  POST { audit_id, road_group_id, total_length, segment_length }
//  Adds a city road to an audit and generates its segments.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/city/audit_road_add.php error: ' . $e->getMessage());
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

$v = cityAuditValidateRoad($body);
if ($v['errors']) {
    cityApiFail(422, (string)reset($v['errors']), ['errors' => $v['errors']]);
}

try {
    $res = $repo->addRoad(
        $audit, $CURRENT_USER_ID,
        $v['clean']['road_group_id'], $v['clean']['total_length'], $v['clean']['segment_length']
    );
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}

ActivityLogger::log($pdo, ActivityLogger::AUDIT_ROAD_ADDED, $CURRENT_USER_ID, [
    'audit_id' => (int)$audit['id'], 'road_id' => $res['road_id'], 'segments' => $res['segment_count'],
]);

echo json_encode(['success' => true] + $res);
