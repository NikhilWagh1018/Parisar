<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/audit-sessions/create.php
//  POST — creates a new audit session for the logged-in surveyor
//         on a specific road.
//
//  Required JSON body: { "road_id": int }
//
//  A surveyor may have only ONE active session per road at a time.
//  Returns: { success: true, session_id: int, public_id: string }
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';

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

// ── Parse body ─────────────────────────────────────────────────
$raw    = file_get_contents('php://input');
$data   = json_decode($raw, true);
$roadId = isset($data['road_id']) ? (int)$data['road_id'] : 0;

if ($roadId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid road_id is required.']);
    exit;
}

try {
    // ── Verify the road exists ─────────────────────────────────
    $stmt = $pdo->prepare('SELECT id FROM roads WHERE id = ? LIMIT 1');
    $stmt->execute([$roadId]);
    if ($stmt->fetch() === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Road not found.']);
        exit;
    }

    // ── Check for an existing active session ───────────────────
    $stmt = $pdo->prepare(
        'SELECT id, public_id FROM audit_sessions
         WHERE  user_id = ? AND road_id = ? AND status = \'active\'
         LIMIT  1'
    );
    $stmt->execute([$CURRENT_USER_ID, $roadId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing !== false) {
        // Return the existing session rather than creating a duplicate
        echo json_encode([
            'success'    => true,
            'session_id' => (int)$existing['id'],
            'public_id'  => $existing['public_id'],
            'resumed'    => true,
        ]);
        exit;
    }

    // ── Insert new session ─────────────────────────────────────
    $pdo->prepare(
        'INSERT INTO audit_sessions (user_id, road_id, status, started_at)
         VALUES (?, ?, \'active\', NOW())'
    )->execute([$CURRENT_USER_ID, $roadId]);

    $sessionId = (int)$pdo->lastInsertId();

    // FIX: Generate public_id in PHP (no trigger)
    $sessionPublicId = 'AUD-' . str_pad((string)$sessionId, 4, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE audit_sessions SET public_id = ? WHERE id = ?')->execute([$sessionPublicId, $sessionId]);

    $row = $pdo->prepare('SELECT public_id FROM audit_sessions WHERE id = ? LIMIT 1');
    $row->execute([$sessionId]);
    $publicId = (string)($row->fetchColumn() ?: '');

    echo json_encode([
        'success'    => true,
        'session_id' => $sessionId,
        'public_id'  => $publicId,
        'resumed'    => false,
    ]);

} catch (PDOException $e) {
    error_log('api/audit-sessions/create.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while creating session.']);
}