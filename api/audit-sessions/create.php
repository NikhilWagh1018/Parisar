<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/audit-sessions/create.php
//  POST — creates a new audit session for the logged-in surveyor.
//  UPDATED: uses RoadRepository + Validator
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../helpers/Validator.php';
require_once __DIR__ . '/../../repositories/RoadRepository.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── CSRF verification ──────────────────────────────────────────
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

// ── Parse + validate body ──────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

$v = Validator::make($data)
    ->required('road_id')
    ->integer('road_id')
    ->min('road_id', 1);

if ($v->fails()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $v->firstError()]);
    exit;
}

$roadId = (int)$data['road_id'];

try {
    $roadRepo = new RoadRepository($pdo);

    // ── Verify the road exists ─────────────────────────────────
    if ($roadRepo->find($roadId) === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Road not found.']);
        exit;
    }

    // ── Check for an existing active session ───────────────────
    $existing = $roadRepo->findActiveSession($CURRENT_USER_ID, $roadId);

    if ($existing !== null) {
        echo json_encode([
            'success'    => true,
            'session_id' => $existing['id'],
            'public_id'  => $existing['public_id'],
            'resumed'    => true,
        ]);
        exit;
    }

    // ── Create new session via repository ──────────────────────
    $result = $roadRepo->createSession($CURRENT_USER_ID, $roadId);

    echo json_encode([
        'success'    => true,
        'session_id' => $result['session_id'],
        'public_id'  => $result['public_id'],
        'resumed'    => false,
    ]);

} catch (PDOException $e) {
    error_log('api/audit-sessions/create.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while creating session.']);
}
