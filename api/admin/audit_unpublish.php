<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/audit_unpublish.php
//  POST { audit_id }
//  The Admin hides a published audit from the public Audit Data page. The audit is not
//  deleted and stays approved, so it does not show up as something to approve. "Publish
//  again" (api/admin/audit_republish.php) shows it on the home page again.
//  admin only.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/audit_unpublish.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../helpers/PublicAudits.php';
require_once __DIR__ . '/../../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../../repositories/AuditReviewRepository.php';

$body = nationalApiContext($CURRENT_USER_ROLE);

$auditId = filter_var($body['audit_id'] ?? null, FILTER_VALIDATE_INT);
$audit   = ($auditId !== false && $auditId !== null) ? (new CityAuditRepository($pdo))->find((int)$auditId) : null;
if ($audit === null) {
    cityApiFail(404, 'Audit not found.');
}
cityApiFailIfDeleted($audit);

try {
    (new AuditReviewRepository($pdo))->unpublishAudit($audit, (int)$CURRENT_USER_ID);
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}
ActivityLogger::log($pdo, ActivityLogger::AUDIT_UNPUBLISHED, $CURRENT_USER_ID, ['audit_id' => (int)$audit['id'], 'name' => (string)$audit['name']]);
publicAuditsCacheClear();
echo json_encode(['success' => true]);
