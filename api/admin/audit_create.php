<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/audit_create.php
//  POST { city_id, name, state, audit_date (YYYY-MM-DD), programme_info? }
//  The Admin creates a new project (a city audit) for a city. The City
//  Leader of that city then adds the roads and assigns surveyors.
//  national_admin only. (City Leaders no longer create audits.)
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/audit_create.php error: ' . $e->getMessage());
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

$body = nationalApiContext($CURRENT_USER_ROLE);

$cityId = filter_var($body['city_id'] ?? null, FILTER_VALIDATE_INT);
$cityRow = null;
if ($cityId !== false && $cityId !== null && $cityId > 0) {
    $q = $pdo->prepare('SELECT id, name FROM cities WHERE id = ?');
    $q->execute([(int)$cityId]);
    $cityRow = $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($cityRow === null) {
    cityApiFail(422, 'Choose a city.', ['errors' => ['city_id' => 'Choose a city.']]);
}

// The state follows the city, so a posted value is ignored when the city is known.
$knownState = cityStateFor((string)$cityRow['name']);
if ($knownState !== null) {
    $body['state'] = $knownState;
}

$v = cityAuditValidate($body);
if ($v['errors']) {
    cityApiFail(422, (string)reset($v['errors']), ['errors' => $v['errors']]);
}

try {
    $auditId = (new CityAuditRepository($pdo))->createAudit(
        (int)$cityRow['id'], $CURRENT_USER_ID, $v['clean']
    );
} catch (DomainException $e) {
    cityApiFail(409, $e->getMessage());
}

ActivityLogger::log($pdo, ActivityLogger::AUDIT_CREATED, $CURRENT_USER_ID, [
    'audit_id' => $auditId, 'city_id' => (int)$cityRow['id'], 'name' => $v['clean']['name'],
    'year' => $v['clean']['audit_year'], 'date' => $v['clean']['audit_date'],
]);

echo json_encode(['success' => true, 'audit_id' => $auditId]);
