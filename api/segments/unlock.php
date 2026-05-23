<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/segments/unlock.php
//  POST — resets a completed segment back to 'pending' so the
//          auditor can correct and re-submit their answers.
//
//  Audit data (segment_audits, obstructions, intersections) is
//  intentionally PRESERVED so form.js can pre-fill the form with
//  the existing answers for the user to review and correct.
//
//  Only the road's creator may unlock a segment.
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
$raw       = file_get_contents('php://input');
$data      = json_decode($raw, true);
$segmentId = isset($data['segment_id']) ? (int)$data['segment_id'] : 0;

if ($segmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid segment_id.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // ── 1. Fetch segment + road ownership ──────────────────────
    $stmt = $pdo->prepare(
        'SELECT s.id, s.status, s.road_id, r.creator_id
           FROM segments s
           JOIN roads    r ON r.id = s.road_id
          WHERE s.id = ?
          LIMIT 1'
    );
    $stmt->execute([$segmentId]);
    $segment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$segment) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Segment not found.']);
        exit;
    }

    // ── 2. Authorisation: only the road's creator may unlock ───
    if ((int)$segment['creator_id'] !== (int)$CURRENT_USER_ID) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have permission to unlock this segment.']);
        exit;
    }

    // ── 3. Only completed segments can be unlocked ─────────────
    if ($segment['status'] !== 'completed') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Segment is not locked — nothing to unlock.']);
        exit;
    }

    // ── 4. Reset segment status only — keep all audit data ─────
    //       segment_audits / obstructions / intersections rows are
    //       preserved so the edit form can pre-fill them.
    //       submit.php will UPDATE (not INSERT) when edit_mode=1.
    $pdo->prepare(
        "UPDATE segments
            SET status = 'pending', completed_at = NULL
          WHERE id = ?"
    )->execute([$segmentId]);

    // ── 5. Re-open the audit session so the user can submit ────
    //       If no active session exists, the form will create one.
    $pdo->prepare(
        "UPDATE audit_sessions
            SET status = 'active'
          WHERE segment_id = ? AND status = 'completed'
          ORDER BY id DESC
          LIMIT 1"
    )->execute([$segmentId]);

    $pdo->commit();

    echo json_encode([
        'success'    => true,
        'segment_id' => $segmentId,
        'message'    => 'Segment unlocked — existing answers pre-loaded for editing.',
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('unlock.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
