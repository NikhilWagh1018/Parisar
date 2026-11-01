<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/password_requests.php
//  Approve or dismiss a "forgot password" request (helpers/PasswordRequest.php).
//
//  POST  {"action":"approve","id":N,"code":"123456"}   -> the person's chosen password goes live
//  POST  {"action":"dismiss","id":N}                   -> closed, nothing changes
//
//  Who may act is decided on the server from the signed-in account, never from
//  the page: an Admin acts on requests addressed to Admins; a City Leader on
//  requests addressed to City Leaders for their own city. Anything else gets
//  "Request not found.".
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    error_log('api/admin/password_requests.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/PasswordRequest.php';
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

$body   = json_decode(file_get_contents('php://input'), true);
$body   = is_array($body) ? $body : [];
$id     = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
$action = (string)($body['action'] ?? '');

if ($id === false || $id === null || $id <= 0 || !in_array($action, ['approve', 'dismiss'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

if ($action === 'approve') {
    $res = passwordRequestApprove($pdo, $id, (string)($body['code'] ?? ''), $CURRENT_USER_ROLE, $CURRENT_USER_ID, $CURRENT_USER_CITY_ID);
    if ($res['ok']) {
        ActivityLogger::log($pdo, ActivityLogger::PASSWORD_REQUEST_APPROVED, $CURRENT_USER_ID, [
            'request_id' => $id, 'target_user_id' => $res['user_id'],
        ]);
    }
} else {
    $res = passwordRequestDismiss($pdo, $id, $CURRENT_USER_ROLE, $CURRENT_USER_ID, $CURRENT_USER_CITY_ID);
    if ($res['ok']) {
        ActivityLogger::log($pdo, ActivityLogger::PASSWORD_REQUEST_DISMISSED, $CURRENT_USER_ID, [
            'request_id' => $id, 'target_user_id' => $res['user_id'],
        ]);
    }
}

http_response_code($res['http']);
$out = ['success' => $res['ok']];
if (isset($res['error']))      { $out['error']      = $res['error']; }
if (isset($res['tries_left'])) { $out['tries_left'] = $res['tries_left']; }
echo json_encode($out);
