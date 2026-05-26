<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/reports/session.php
//  GET ?session_id= — returns complete report data for one session.
//  UPDATED: uses AuditSessionRepository + RoadRepository + SegmentRepository
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/ScoreService.php';
require_once __DIR__ . '/../../repositories/AuditSessionRepository.php';
require_once __DIR__ . '/../../repositories/RoadRepository.php';
require_once __DIR__ . '/../../repositories/SegmentRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;

if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid session_id query parameter is required.']);
    exit;
}

try {
    $sessionRepo = new AuditSessionRepository($pdo);
    $roadRepo    = new RoadRepository($pdo);
    $segRepo     = new SegmentRepository($pdo);

    // ── Fetch session + ownership check ────────────────────────
    $session = $sessionRepo->findOwnedBy($sessionId, $CURRENT_USER_ID);

    if ($session === null) {
        // Try finding it without ownership to give the right error
        $exists = $sessionRepo->find($sessionId);
        http_response_code($exists ? 403 : 404);
        echo json_encode(['success' => false, 'error' => $exists
            ? 'You do not have access to this session.'
            : 'Session not found.'
        ]);
        exit;
    }

    // ── Fetch road ─────────────────────────────────────────────
    $road = $roadRepo->find((int)$session['road_id']);

    // ── Fetch surveyor ─────────────────────────────────────────
    $stmtUser = $pdo->prepare(
        'SELECT id, public_id, name, email, organisation
           FROM users WHERE id = ? LIMIT 1'
    );
    $stmtUser->execute([$session['user_id']]);
    $surveyor = $stmtUser->fetch(PDO::FETCH_ASSOC);

    // ── Fetch all segments for this road ───────────────────────
    $stmtSegs = $pdo->prepare(
        'SELECT id, public_id, segment_number, start_label, end_label,
                start_distance, end_distance, length, status, completed_at
           FROM segments
          WHERE road_id = ?
          ORDER BY segment_number ASC'
    );
    $stmtSegs->execute([$session['road_id']]);
    $segments = $stmtSegs->fetchAll(PDO::FETCH_ASSOC);

    // ── Build per-segment result ───────────────────────────────
    $result = [];

    foreach ($segments as $seg) {
        $segId = (int)$seg['id'];

        // Latest audit for this segment within this session
        $stmtAudit = $pdo->prepare(
            'SELECT * FROM segment_audits
              WHERE segment_id = ? AND session_id = ?
              ORDER BY id DESC LIMIT 1'
        );
        $stmtAudit->execute([$segId, $sessionId]);
        $audit = $stmtAudit->fetch(PDO::FETCH_ASSOC);

        $score         = null;
        $obstructions  = [];
        $intersections = [];

        if ($audit !== false) {
            $auditId = (int)$audit['id'];

            try {
                $score = calculateSegmentScore($auditId, $pdo);
            } catch (\InvalidArgumentException) {
                $score = null;
            }

            $obstructions  = $segRepo->obstructionsForAudit($auditId);
            $intersections = $segRepo->intersectionsForAudit($auditId);

            $audit['surface_issues']  = json_decode((string)($audit['surface_issues']  ?? '[]'), true);
            $audit['overhead_issues'] = json_decode((string)($audit['overhead_issues'] ?? '[]'), true);
            $audit['footpath_rating'] = json_decode((string)($audit['footpath_rating'] ?? '[]'), true);
        }

        $seg['id']             = (int)$seg['id'];
        $seg['segment_number'] = (int)$seg['segment_number'];
        $seg['length']         = (float)$seg['length'];

        $result[] = [
            'segment'       => $seg,
            'audit'         => $audit ?: null,
            'score'         => $score,
            'obstructions'  => $obstructions,
            'intersections' => $intersections,
        ];
    }

    echo json_encode([
        'success'  => true,
        'session'  => $session,
        'road'     => $road,
        'surveyor' => $surveyor,
        'segments' => $result,
    ]);

} catch (PDOException $e) {
    error_log('api/reports/session.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while loading report data.']);
}
