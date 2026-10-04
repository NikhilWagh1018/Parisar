<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/city/audit_review.php
//  POST { audit_id, segment_id, action: "approve" | "send_back",
//         note (required for send_back), surveyor_id (optional, send_back) }
//  City Leader reviews a submitted segment of their own city's audit.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/city/audit_review.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../../repositories/AuditReviewRepository.php';

$body = cityApiContext($CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID);
$repo = new CityAuditRepository($pdo);

$auditId = filter_var($body['audit_id'] ?? null, FILTER_VALIDATE_INT);
$audit   = ($auditId !== false && $auditId !== null) ? $repo->find((int)$auditId) : null;
if ($audit === null || (int)$audit['city_id'] !== (int)$CURRENT_USER_CITY_ID) {
    cityApiFail(404, 'Audit not found.');
}

$segmentId = filter_var($body['segment_id'] ?? null, FILTER_VALIDATE_INT);
if ($segmentId === false || $segmentId === null || $segmentId < 1) {
    cityApiFail(422, 'Choose a segment.');
}
$action = (string)($body['action'] ?? '');
if (!in_array($action, ['approve', 'send_back'], true)) {
    cityApiFail(422, 'Unknown action.');
}

$review = new AuditReviewRepository($pdo);
try {
    if ($action === 'approve') {
        $review->approve($audit, (int)$segmentId, (int)$CURRENT_USER_ID);
        ActivityLogger::log($pdo, ActivityLogger::AUDIT_SEGMENT_APPROVED, $CURRENT_USER_ID, [
            'audit_id' => (int)$audit['id'], 'segment_id' => (int)$segmentId,
        ]);
    } else {
        $newSurveyor = filter_var($body['surveyor_id'] ?? null, FILTER_VALIDATE_INT);
        $newSurveyor = ($newSurveyor === false || $newSurveyor === null || $newSurveyor < 1) ? null : (int)$newSurveyor;
        $review->sendBack($audit, (int)$segmentId, (int)$CURRENT_USER_ID, (string)($body['note'] ?? ''), $newSurveyor);
        ActivityLogger::log($pdo, ActivityLogger::AUDIT_SEGMENT_SENT_BACK, $CURRENT_USER_ID, [
            'audit_id' => (int)$audit['id'], 'segment_id' => (int)$segmentId, 'new_surveyor_id' => $newSurveyor,
        ]);
    }
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}

echo json_encode(['success' => true]);
