<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/audit_create.php
//  POST { city_id | city_name, name, state, audit_date (YYYY-MM-DD), programme_info? }
//  city_id picks an existing city; city_name picks one by name and adds it to
//  the cities list the first time it is used.
//  Optional leader_user_id also makes that person the City Leader of the city
//  (same rules as api/admin/city_leader.php).
//  The Admin creates a new project (a city audit) for a city. The City
//  Leader of that city then adds the roads and assigns surveyors.
//  admin only. (City Leaders no longer create audits.)
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
require_once __DIR__ . '/../../helpers/CityLeaderAssign.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../repositories/CityAuditRepository.php';

$body = nationalApiContext($CURRENT_USER_ROLE);

$cityId   = filter_var($body['city_id'] ?? null, FILTER_VALIDATE_INT);
$cityName = null;   // name of the city, whether it exists yet or not
if ($cityId !== false && $cityId !== null && $cityId > 0) {
    $q = $pdo->prepare('SELECT id, name FROM cities WHERE id = ?');
    $q->execute([(int)$cityId]);
    $row = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $cityName = $row !== null ? (string)$row['name'] : null;
}
if ($cityName === null) {
    $cityId   = null;
    $cityName = is_string($body['city_name'] ?? null) ? cityNameClean((string)$body['city_name']) : '';
    if ($cityName === '') {
        cityApiFail(422, 'Choose a city.', ['errors' => ['city_name' => 'Choose a city.']]);
    }
    if (!cityNameIsValid($cityName)) {
        cityApiFail(422, 'Enter a valid city name.', ['errors' => ['city_name' => 'Enter a valid city name.']]);
    }
}

// The state follows the city, so a posted value is ignored when the city is known.
$knownState = cityStateFor($cityName);
if ($knownState !== null) {
    $body['state'] = $knownState;
}

$v = cityAuditValidate($body);
if ($v['errors']) {
    cityApiFail(422, (string)reset($v['errors']), ['errors' => $v['errors']]);
}

// A city that is new to the app is added only together with its first project.
$cityCreated = false;
$leaderAssigned = null;
try {
    $pdo->beginTransaction();
    if ($cityId === null) {
        [$cityId, $cityCreated] = cityFindOrCreate($pdo, $cityName);
    }
    $auditId = (new CityAuditRepository($pdo))->createAudit((int)$cityId, $CURRENT_USER_ID, $v['clean']);
    $leaderId = filter_var($body['leader_user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($leaderId !== false && $leaderId !== null && $leaderId > 0) {
        $u = $pdo->prepare('SELECT role, is_active, city_id FROM users WHERE id = ?');
        $u->execute([(int)$leaderId]);
        $leader = $u->fetch(PDO::FETCH_ASSOC) ?: null;
        $leaderError = cityLeaderAssignError($leader, (int)$cityId);
        if ($leaderError !== null) {
            throw new DomainException($leaderError);
        }
        $pdo->prepare("UPDATE users SET role = 'city_leader', city_id = ? WHERE id = ?")->execute([(int)$cityId, (int)$leaderId]);
        $leaderAssigned = (int)$leaderId;
    }
    $pdo->commit();
} catch (DomainException $e) {
    $pdo->rollBack();
    cityApiFail(409, $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

ActivityLogger::log($pdo, ActivityLogger::AUDIT_CREATED, $CURRENT_USER_ID, [
    'audit_id' => $auditId, 'city_id' => (int)$cityId, 'city_created' => $cityCreated, 'leader_user_id' => $leaderAssigned, 'name' => $v['clean']['name'],
    'year' => $v['clean']['audit_year'], 'date' => $v['clean']['audit_date'],
]);

if ($leaderAssigned !== null) {
    ActivityLogger::log($pdo, ActivityLogger::USER_ROLE_CHANGED, $CURRENT_USER_ID, [
        'user_id' => $leaderAssigned, 'to_role' => 'city_leader', 'city_id' => (int)$cityId,
    ]);
}

echo json_encode(['success' => true, 'audit_id' => $auditId]);
