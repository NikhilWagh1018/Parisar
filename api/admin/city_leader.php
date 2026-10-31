<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/city_leader.php
//  POST { city_id, user_id, replace? }
//  The Admin makes an existing user the City Leader of a city.
//  The user must be active, not an Admin, and in that city (or in none).
//  A city can have several City Leaders. Projects are assigned to a City
//  Leader separately, on the project's Create / Edit screen.
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
require_once __DIR__ . '/../../helpers/Cities.php';
require_once __DIR__ . '/../../helpers/CityLeaderAssign.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../helpers/Notifications.php';
require_once __DIR__ . '/../../repositories/NotificationRepository.php';

$body = nationalApiContext($CURRENT_USER_ROLE);

$cityId = filter_var($body['city_id'] ?? null, FILTER_VALIDATE_INT);
$userId = filter_var($body['user_id'] ?? null, FILTER_VALIDATE_INT);
if ($cityId === false || $cityId === null || $cityId <= 0 || $userId === false || $userId === null || $userId <= 0) {
    cityApiFail(422, 'Choose a city and a person.');
}

$c = $pdo->prepare('SELECT id, name FROM cities WHERE id = ?');
$c->execute([(int)$cityId]);
$city = $c->fetch(PDO::FETCH_ASSOC) ?: null;
if ($city === null) {
    cityApiFail(404, 'City not found.');
}

$pdo->beginTransaction();
try {
    $u = $pdo->prepare('SELECT role, is_active, city_id FROM users WHERE id = ?');
    $u->execute([(int)$userId]);
    $user = $u->fetch(PDO::FETCH_ASSOC) ?: null;

    $error = cityLeaderAssignError($user, (int)$cityId);
    if ($error !== null) {
        $pdo->rollBack();
        cityApiFail($user === null ? 404 : 409, $error);
    }

    $pdo->prepare("UPDATE users SET role = 'city_leader', city_id = ? WHERE id = ?")->execute([(int)$cityId, (int)$userId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

ActivityLogger::log($pdo, ActivityLogger::USER_ROLE_CHANGED, $CURRENT_USER_ID, [
    'user_id' => (int)$userId, 'from_role' => (string)$user['role'], 'to_role' => 'city_leader', 'city_id' => (int)$cityId,
]);
// Tell the new City Leader. A failure here must never undo the change.
try {
    $notes = new NotificationRepository($pdo);
    $t = cityLeaderChangedNotificationText((string)$city['name'], true);
    $notes->create((int)$userId, NOTIFY_LEADER_CHANGED, $t['title'], $t['body'], null);
} catch (Throwable $e) {
    error_log('city leader notification failed: ' . $e->getMessage());
}

echo json_encode(['success' => true]);
