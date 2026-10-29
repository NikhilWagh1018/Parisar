<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/city_leader.php
//  POST { city_id, user_id }
//  The Admin makes an existing user the City Leader of a city.
//  The user must be active, not an Admin, and in that city (or in none).
//  admin only.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/city_leader.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/CityLeaderAssign.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';

$body = nationalApiContext($CURRENT_USER_ROLE);

$cityId = filter_var($body['city_id'] ?? null, FILTER_VALIDATE_INT);
$userId = filter_var($body['user_id'] ?? null, FILTER_VALIDATE_INT);
if ($cityId === false || $cityId === null || $cityId <= 0 || $userId === false || $userId === null || $userId <= 0) {
    cityApiFail(422, 'Choose a city and a person.');
}

$c = $pdo->prepare('SELECT id FROM cities WHERE id = ?');
$c->execute([(int)$cityId]);
if ($c->fetchColumn() === false) {
    cityApiFail(404, 'City not found.');
}

$u = $pdo->prepare('SELECT role, is_active, city_id FROM users WHERE id = ?');
$u->execute([(int)$userId]);
$user = $u->fetch(PDO::FETCH_ASSOC) ?: null;

$error = cityLeaderAssignError($user, (int)$cityId);
if ($error !== null) {
    cityApiFail($user === null ? 404 : 409, $error);
}

$pdo->prepare("UPDATE users SET role = 'city_leader', city_id = ? WHERE id = ?")->execute([(int)$cityId, (int)$userId]);
ActivityLogger::log($pdo, ActivityLogger::USER_ROLE_CHANGED, $CURRENT_USER_ID, [
    'user_id' => (int)$userId, 'from_role' => (string)$user['role'], 'to_role' => 'city_leader', 'city_id' => (int)$cityId,
]);

echo json_encode(['success' => true]);
