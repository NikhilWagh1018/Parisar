<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/past_audit_create.php
//  POST (multipart form) city_name, name, audit_date, road_count, segment_count, length_km,
//       score (optional), report (optional PDF, up to 8 MB)
//  The Admin adds an audit that was done before CycleAudit. It is published on the
//  Audit Data page straight away (there is no field work to review).
//  admin only.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/past_audit_create.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/PastAudits.php';
require_once __DIR__ . '/../../helpers/PublicAudits.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../repositories/PastAuditRepository.php';

$in = nationalFormContext($CURRENT_USER_ROLE);

$today = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$v = pastAuditValidate($in, $_FILES['report'] ?? null, $today);
if ($v['errors']) {
    cityApiFail(422, (string)reset($v['errors']), ['errors' => $v['errors']]);
}

$id = (new PastAuditRepository($pdo))->create($v['clean'], (int)$CURRENT_USER_ID);
ActivityLogger::log($pdo, ActivityLogger::PAST_AUDIT_ADDED, $CURRENT_USER_ID, [
    'past_audit_id' => $id, 'name' => $v['clean']['name'], 'city' => $v['clean']['city_name'],
    'date' => $v['clean']['audit_date'], 'has_report' => $v['clean']['report'] !== null,
]);
publicAuditsCacheClear();
echo json_encode(['success' => true, 'id' => $id]);
