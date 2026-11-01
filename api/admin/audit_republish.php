<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/audit_republish.php
//  POST { audit_id }
//  The Admin shows an audit that was hidden (api/admin/audit_unpublish.php) on the public
//  Audit Data page again.
//  admin only.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/audit_republish.php error: ' . $e->getMessage());
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
    (new AuditReviewRepository($pdo))->republishAudit($audit);
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}
ActivityLogger::log($pdo, ActivityLogger::AUDIT_REPUBLISHED, $CURRENT_USER_ID, ['audit_id' => (int)$audit['id'], 'name' => (string)$audit['name']]);
publicAuditsCacheClear();
echo json_encode(['success' => true]);
