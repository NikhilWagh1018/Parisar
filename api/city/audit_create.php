<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/city/audit_create.php
//  POST { name, state, audit_date (YYYY-MM-DD), programme_info? }
//  City Leader creates a new city audit for their own city.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/city/audit_create.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/CityAudit.php';
require_once __DIR__ . '/../../helpers/Cities.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../repositories/CityAuditRepository.php';

$body = cityApiContext($CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID);

// The state follows the leader's city, so a posted value is ignored when the city is known.
$cityRow = $pdo->prepare('SELECT name FROM cities WHERE id = ?');
$cityRow->execute([(int)$CURRENT_USER_CITY_ID]);
$knownState = cityStateFor((string)($cityRow->fetchColumn() ?: ''));
if ($knownState !== null) {
    $body['state'] = $knownState;
}

$v = cityAuditValidate($body);
if ($v['errors']) {
    cityApiFail(422, (string)reset($v['errors']), ['errors' => $v['errors']]);
}

try {
    $auditId = (new CityAuditRepository($pdo))->createAudit(
        (int)$CURRENT_USER_CITY_ID, $CURRENT_USER_ID, $v['clean']
    );
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}

ActivityLogger::log($pdo, ActivityLogger::AUDIT_CREATED, $CURRENT_USER_ID, [
    'audit_id' => $auditId, 'name' => $v['clean']['name'], 'year' => $v['clean']['audit_year'], 'date' => $v['clean']['audit_date'],
]);

echo json_encode(['success' => true, 'audit_id' => $auditId]);
