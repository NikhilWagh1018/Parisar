<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/team.php
//  A City Leader adds a surveyor to, or removes one from, their own team
//  (helpers/TeamRoster.php).
//
//  POST  {"action":"add","user_id":N}      -> N joins your team
//  POST  {"action":"remove","user_id":N}   -> N leaves your team
//
//  City Leaders only. The city comes from the signed-in account, never from
//  the request.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    error_log('api/admin/team.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/TeamRoster.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

if ($CURRENT_USER_ROLE !== 'city_leader') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only a City Leader has a team.']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true);
$body   = is_array($body) ? $body : [];
$userId = filter_var($body['user_id'] ?? null, FILTER_VALIDATE_INT);
$action = (string)($body['action'] ?? '');

if ($userId === false || $userId === null || $userId <= 0 || !in_array($action, ['add', 'remove'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$res = teamSetMember($pdo, (int)$CURRENT_USER_ID, $CURRENT_USER_CITY_ID, $userId, $action === 'add');
if ($res['ok']) {
    ActivityLogger::log(
        $pdo,
        $action === 'add' ? ActivityLogger::TEAM_MEMBER_ADDED : ActivityLogger::TEAM_MEMBER_REMOVED,
        (int)$CURRENT_USER_ID,
        ['target_user_id' => $userId]
    );
}

http_response_code($res['http']);
$out = ['success' => $res['ok']];
if (isset($res['error'])) { $out['error'] = $res['error']; }
echo json_encode($out);
