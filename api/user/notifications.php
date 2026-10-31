<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/user/notifications.php
//  GET                       -> { success, unread, notifications: [...] }  (the user's own, unread)
//  POST { action: "read_all" } -> marks all of the user's notifications read
//  Login required. POST needs the CSRF token (X-CSRF-Token header).
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/user/notifications.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../repositories/NotificationRepository.php';

$repo   = new NotificationRepository($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    echo json_encode([
        'success'       => true,
        'unread'        => $repo->unreadCount((int)$CURRENT_USER_ID),
        'notifications' => $repo->recent((int)$CURRENT_USER_ID),
    ]);
    exit;
}

if ($method === 'POST') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    $body = json_decode((string)file_get_contents('php://input'), true);
    $action = is_array($body) ? ($body['action'] ?? '') : '';
    if ($action === 'read') {
        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
        echo json_encode(['success' => true, 'marked' => ($id !== false && $id !== null) ? ($repo->markRead((int)$CURRENT_USER_ID, (int)$id) ? 1 : 0) : 0]);
        exit;
    }
    if ($action !== 'read_all') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        exit;
    }
    echo json_encode(['success' => true, 'marked' => $repo->markAllRead((int)$CURRENT_USER_ID)]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
