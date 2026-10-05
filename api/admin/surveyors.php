<?php
declare(strict_types=1);
header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    error_log('api/admin/surveyors.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});
require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/Cities.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../helpers/RoleRules.php';

$isNationalAdmin = $CURRENT_USER_ROLE === 'national_admin';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // national_admin sees everyone; city_admin sees only users in their
    // own city (a city_admin with no city_id set sees nobody, rather
    // than silently falling through to "see everyone").
    $where  = '';
    $params = [];
    if (!$isNationalAdmin) {
        $where  = 'WHERE u.city_id = ?';
        $params = [$CURRENT_USER_CITY_ID];
    }

    $stmt = $pdo->prepare(
        "SELECT
            u.id,
            u.name,
            u.email,
            u.phone,
            u.role,
            u.city_id,
            c.name AS city_name,
            u.organisation,
            u.profile_picture,
            u.is_active,
            u.last_login,
            u.created_at,
            (SELECT COUNT(*) FROM roads r WHERE r.creator_id = u.id) AS roads_created,
            (SELECT COUNT(*) FROM segment_audits sa WHERE sa.surveyor_id = u.id) AS segments_audited,
            (SELECT MAX(sa2.created_at) FROM segment_audits sa2 WHERE sa2.surveyor_id = u.id) AS last_audit_at
         FROM users u
         LEFT JOIN cities c ON c.id = u.city_id
         $where
        ORDER BY u.is_active DESC, u.role = 'national_admin' DESC, u.role = 'city_admin' DESC, u.name ASC"
    );
    $stmt->execute($params);
    $surveyors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($surveyors as &$s) {
        $s['id']               = (int)$s['id'];
        $s['city_id']          = $s['city_id'] !== null ? (int)$s['city_id'] : null;
        $s['roads_created']    = (int)$s['roads_created'];
        $s['segments_audited'] = (int)$s['segments_audited'];
        $s['is_active']        = (bool)$s['is_active'];
        $s['is_current_user']  = $s['id'] === $CURRENT_USER_ID;
        $s['has_profile_picture'] = $s['profile_picture'] !== null && $s['profile_picture'] !== '';
        unset($s['profile_picture']);
    }
    unset($s);
    echo json_encode([
        'success'   => true,
        'surveyors' => $surveyors,
        // Only national admins can move users, so only they need the list.
        'cities'    => $isNationalAdmin ? listCities($pdo) : [],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── CSRF verification ──────────────────────────────────────
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $targetId = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);

    if ($targetId === false || $targetId === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit;
    }

    $check = $pdo->prepare("SELECT role, is_active, city_id FROM users WHERE id = ?");
    $check->execute([$targetId]);
    $target = $check->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }

    // A city_admin may only act on users within their own city, and
    // may never act on a national_admin account (theirs or anyone else's).
    if (!$isNationalAdmin) {
        if ($target['city_id'] === null || (int)$target['city_id'] !== $CURRENT_USER_CITY_ID) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You can only manage users in your own city.']);
            exit;
        }
        if (!canManageUser($CURRENT_USER_ROLE, (string)$target['role'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'City Leaders can only manage surveyors.']);
            exit;
        }
    }

    // ── City change (national_admin only) ───────────────────────
    if (array_key_exists('city_id', $body)) {
        if (!$isNationalAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only a national admin can move a user to another city.']);
            exit;
        }
        [$newCityId, $cityError] = validateUserCityChange(listCities($pdo), (string)$target['role'], $body['city_id']);
        if ($cityError !== null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $cityError]);
            exit;
        }
        $oldCityId = $target['city_id'] !== null ? (int)$target['city_id'] : null;
        if ($oldCityId !== $newCityId) {
            $pdo->prepare('UPDATE users SET city_id = ? WHERE id = ?')->execute([$newCityId, $targetId]);
            ActivityLogger::log($pdo, ActivityLogger::USER_CITY_CHANGED, $CURRENT_USER_ID, [
                'user_id'      => $targetId,
                'from_city_id' => $oldCityId,
                'to_city_id'   => $newCityId,
            ]);
        }
        echo json_encode(['success' => true, 'city_id' => $newCityId]);
        exit;
    }

    // ── Role change (promote/demote) ───────────────────────────
    if (array_key_exists('role', $body)) {
        $newRole = $body['role'];

        // Only the Admin changes roles (so only the
        // Admin creates City Leaders), and a City Leader needs a city.
        $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'national_admin'")->fetchColumn();
        $roleError  = validateRoleChange(
            $CURRENT_USER_ROLE,
            (string)$target['role'],
            $target['city_id'] !== null ? (int)$target['city_id'] : null,
            $newRole,
            $targetId === $CURRENT_USER_ID,
            $adminCount
        );
        if ($roleError !== null) {
            http_response_code($CURRENT_USER_ROLE === 'national_admin' ? 400 : 403);
            echo json_encode(['success' => false, 'error' => $roleError]);
            exit;
        }

        $update = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
        $update->execute([$newRole, $targetId]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── Active / inactive toggle ────────────────────────────────
    if (array_key_exists('is_active', $body)) {
        $active = $body['is_active'];
        if (!is_bool($active)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid request.']);
            exit;
        }

        if (!$active && $targetId === $CURRENT_USER_ID) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'You cannot deactivate your own account.']);
            exit;
        }

        if (!$active && $target['role'] === 'national_admin') {
            $activeAdminCount = (int)$pdo->query(
                "SELECT COUNT(*) FROM users WHERE role = 'national_admin' AND is_active = 1"
            )->fetchColumn();
            if ($activeAdminCount <= 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cannot deactivate the last remaining active admin.']);
                exit;
            }
        }

        $update = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $update->execute([$active ? 1 : 0, $targetId]);

        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
