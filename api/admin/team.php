<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/team.php
//  A City Leader adds a surveyor to, or removes one from, their own team
//  (helpers/TeamRoster.php).
//
//  POST  {"action":"add","user_id":N}      -> N joins your team
//  POST  {"action":"remove","user_id":N}   -> N leaves your team
//  POST  {"action":"add_many","user_ids":[..]} -> up to 200 join your team; the ones that cannot are listed
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
$action = (string)($body['action'] ?? '');

if ($action === 'add_many') {
    $ids = $body['user_ids'] ?? null;
    if (!is_array($ids) || count($ids) === 0 || count($ids) > 200) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit;
    }
    $ids = array_values(array_filter(array_map(static fn($v) => filter_var($v, FILTER_VALIDATE_INT), $ids), static fn($v) => $v !== false && $v > 0));
    $res = teamAddMany($pdo, (int)$CURRENT_USER_ID, $CURRENT_USER_CITY_ID, $ids);
    if ($res['added']) {
        ActivityLogger::log($pdo, ActivityLogger::TEAM_MEMBER_ADDED, (int)$CURRENT_USER_ID, ['target_user_ids' => $res['added']]);
    }
    echo json_encode(['success' => true, 'added' => $res['added'], 'failed' => (object)$res['failed']]);
    exit;
}

$userId = filter_var($body['user_id'] ?? null, FILTER_VALIDATE_INT);

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
