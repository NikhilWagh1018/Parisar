<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/segments/complete.php
//  PUT — manually marks a segment as completed.
//
//  FIXES APPLIED:
//    1. Session ownership check now requires status = 'active'.
//       Previously a completed/archived session could still mark
//       segments as complete.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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
$raw       = file_get_contents('php://input');
$data      = json_decode($raw, true);
$segmentId = isset($data['segment_id']) ? (int)$data['segment_id'] : 0;
$sessionId = isset($data['session_id']) ? (int)$data['session_id'] : 0;

if ($segmentId <= 0 || $sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'segment_id and session_id are required.']);
    exit;
}

try {
    // ── FIX: Verify session ownership AND require active status ──
    $stmtSess = $pdo->prepare(
        'SELECT road_id FROM audit_sessions
         WHERE  id = ? AND user_id = ? AND status = \'active\'
         LIMIT  1'
    );
    $stmtSess->execute([$sessionId, $CURRENT_USER_ID]);
    $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if ($session === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Session not found, not owned by you, or not active.']);
        exit;
    }

    // ── Verify segment belongs to the session's road ───────────
    $stmtSeg = $pdo->prepare(
        'SELECT id FROM segments WHERE id = ? AND road_id = ? LIMIT 1'
    );
    $stmtSeg->execute([$segmentId, $session['road_id']]);

    if ($stmtSeg->fetch() === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Segment does not belong to this session\'s road.']);
        exit;
    }

    // ── Update status ──────────────────────────────────────────
    $affected = $pdo->prepare(
        'UPDATE segments SET status = \'completed\', completed_at = NOW()
         WHERE id = ? AND status != \'completed\''
    );
    $affected->execute([$segmentId]);

    if ($affected->rowCount() === 0) {
        echo json_encode(['success' => true, 'already_completed' => true]);
        exit;
    }

    echo json_encode(['success' => true, 'already_completed' => false]);

} catch (PDOException $e) {
    error_log('api/segments/complete.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}
