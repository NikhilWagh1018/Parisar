<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/past_audit_delete.php
//  POST { past_audit_id }
//  The Admin removes a past audit from the public Audit Data page. The row (and its
//  PDF) stays in the database; it just stops being listed or downloadable.
//  admin only.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/past_audit_delete.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/PublicAudits.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../repositories/PastAuditRepository.php';

$body = nationalApiContext($CURRENT_USER_ROLE);

$id   = filter_var($body['past_audit_id'] ?? null, FILTER_VALIDATE_INT);
$repo = new PastAuditRepository($pdo);
$row  = ($id !== false && $id !== null) ? $repo->find((int)$id) : null;
if ($row === null || $row['deleted_at'] !== null) {
    cityApiFail(404, 'This past audit was not found. It may already be deleted.');
}

$repo->delete((int)$id);
ActivityLogger::log($pdo, ActivityLogger::PAST_AUDIT_DELETED, $CURRENT_USER_ID, [
    'past_audit_id' => (int)$id, 'name' => (string)$row['name'], 'city' => (string)$row['city_name'],
]);
publicAuditsCacheClear();
echo json_encode(['success' => true]);
