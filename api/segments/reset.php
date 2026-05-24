<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/segments/reset.php
//  POST (JSON body) — clears all audit data for a segment and
//                     resets it back to 'pending' status.
//
//  Body: { "segment_id": <int>, "session_id": <int> }
//
//  - Deletes segment_audits, obstructions, intersections rows
//    for this segment.
//  - Resets segments.status = 'pending'
//  - Only the current active session owner may reset.
// ═══════════════════════════════════════════════════════════════

ini_set('display_errors', '0');
error_reporting(E_ALL);

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

// ── Parse JSON body ────────────────────────────────────────────
$raw       = file_get_contents('php://input');
$body      = json_decode($raw, true);
$segmentId = isset($body['segment_id']) ? (int)$body['segment_id'] : 0;
$sessionId = isset($body['session_id']) ? (int)$body['session_id'] : 0;

if ($segmentId <= 0 || $sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'segment_id and session_id are required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // ── 1. Verify segment exists and fetch road info ────────────
    $stmtSeg = $pdo->prepare(
        'SELECT s.id, s.road_id, r.creator_id
           FROM segments s
           JOIN roads r ON r.id = s.road_id
          WHERE s.id = ?
          LIMIT 1'
    );
    $stmtSeg->execute([$segmentId]);
    $segment = $stmtSeg->fetch(PDO::FETCH_ASSOC);

    if (!$segment) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Segment not found.']);
        exit;
    }

    // ── 2. Verify session ownership ────────────────────────────
    $stmtSess = $pdo->prepare(
        'SELECT id FROM audit_sessions
          WHERE id = ? AND user_id = ?
          LIMIT 1'
    );
    $stmtSess->execute([$sessionId, $CURRENT_USER_ID]);
    $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Session not found or not owned by you.']);
        exit;
    }

    // ── 3. Find all audit rows for this segment ────────────────
    $stmtAudits = $pdo->prepare(
        'SELECT id FROM segment_audits WHERE segment_id = ?'
    );
    $stmtAudits->execute([$segmentId]);
    $auditIds = $stmtAudits->fetchAll(PDO::FETCH_COLUMN);

    // ── 4. Delete obstructions + intersections for each audit ──
    if (!empty($auditIds)) {
        $placeholders = implode(',', array_fill(0, count($auditIds), '?'));

        $pdo->prepare(
            "DELETE FROM obstructions WHERE audit_id IN ({$placeholders})"
        )->execute($auditIds);

        $pdo->prepare(
            "DELETE FROM intersections WHERE audit_id IN ({$placeholders})"
        )->execute($auditIds);

        // ── 5. Delete the audit rows themselves ─────────────────
        $pdo->prepare(
            "DELETE FROM segment_audits WHERE segment_id = ?"
        )->execute([$segmentId]);
    }

    // ── 6. Reset segment status to pending ────────────────────
    $pdo->prepare(
        "UPDATE segments
            SET status = 'pending', completed_at = NULL
          WHERE id = ?"
    )->execute([$segmentId]);

    // ── 7. Re-open the audit session if it was completed ───────
    $pdo->prepare(
        "UPDATE audit_sessions
            SET status = 'active', completed_at = NULL
          WHERE road_id = (SELECT road_id FROM segments WHERE id = ?)
            AND user_id = ?
            AND status  = 'completed'
          ORDER BY id DESC
          LIMIT 1"
    )->execute([$segmentId, $CURRENT_USER_ID]);

    $pdo->commit();

    echo json_encode([
        'success'    => true,
        'segment_id' => $segmentId,
        'message'    => 'Form reset — all audit data cleared.',
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('api/segments/reset.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
