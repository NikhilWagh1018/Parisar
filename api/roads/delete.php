<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/roads/delete.php
//  DELETE — removes a road and all its cascading children
//           (audit_sessions → segment_audits → obstructions
//            → intersections, segments) via DB ON DELETE CASCADE.
//
//  Required JSON body: { "road_id": int }
//
//  Ownership check: only the creator_id may delete the road.
//  Returns: { success: true }
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

// ── Method check ───────────────────────────────────────────────
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

// ── Ownership check ────────────────────────────────────────────
try {
    $stmt = $pdo->prepare('SELECT creator_id FROM roads WHERE id = ? LIMIT 1');
    $stmt->execute([$roadId]);
    $road = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($road === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Road not found.']);
        exit;
    }

    if ((int)$road['creator_id'] !== $CURRENT_USER_ID) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have permission to delete this road.']);
        exit;
    }

    // ── Delete — cascade handles children ─────────────────────
    $pdo->prepare('DELETE FROM roads WHERE id = ?')->execute([$roadId]);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('api/roads/delete.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while deleting road.']);
}