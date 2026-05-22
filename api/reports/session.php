<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/reports/session.php
//  GET ?session_id= — returns complete report data for one
//                     audit session, ready for report.php to render.
//
//  Ownership check: session must belong to the logged-in user.
//
//  Returns:
//  {
//    success: true,
//    session: { id, public_id, status, started_at, completed_at },
//    road:    { id, public_id, name, start_point, end_point, total_length },
//    surveyor:{ id, public_id, name, email, organisation },
//    segments: [
//      {
//        segment: { id, public_id, segment_number, start_label,
//                   end_label, length, status },
//        audit:   { ...all segment_audit fields... } | null,
//        score:   { safety_score, continuity_score, comfort_score,
//                   final, rating } | null,
//        obstructions: [ ...rows... ],
//        intersections: [ ...rows... ]
//      }
//    ]
//  }
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/ScoreService.php';

header('Content-Type: application/json');

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
    // ── Fetch session + ownership check ────────────────────────
    $stmtSess = $pdo->prepare(
        'SELECT s.id, s.public_id, s.user_id, s.road_id,
                s.status, s.started_at, s.completed_at
         FROM   audit_sessions s
         WHERE  s.id = ?
         LIMIT  1'
    );
    $stmtSess->execute([$sessionId]);
    $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if ($session === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Session not found.']);
        exit;
    }

    if ((int)$session['user_id'] !== $CURRENT_USER_ID) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have access to this session.']);
        exit;
    }

    // ── Fetch road ─────────────────────────────────────────────
    $stmtRoad = $pdo->prepare(
        'SELECT id, public_id, name, start_point, end_point, total_length, gps_start, gps_end
         FROM   roads WHERE id = ? LIMIT 1'
    );
    $stmtRoad->execute([$session['road_id']]);
    $road = $stmtRoad->fetch(PDO::FETCH_ASSOC);

    // ── Fetch surveyor ─────────────────────────────────────────
    $stmtUser = $pdo->prepare(
        'SELECT id, public_id, name, email, organisation
         FROM   users WHERE id = ? LIMIT 1'
    );
    $stmtUser->execute([$session['user_id']]);
    $surveyor = $stmtUser->fetch(PDO::FETCH_ASSOC);

    // ── Fetch all segments for this road ───────────────────────
    $stmtSegs = $pdo->prepare(
        'SELECT id, public_id, segment_number, start_label, end_label,
                start_distance, end_distance, length, status, completed_at
         FROM   segments
         WHERE  road_id = ?
         ORDER  BY segment_number ASC'
    );
    $stmtSegs->execute([$session['road_id']]);
    $segments = $stmtSegs->fetchAll(PDO::FETCH_ASSOC);

    // ── Fetch audit, obstructions, intersections per segment ───
    $result = [];

    foreach ($segments as $seg) {
        $segId = (int)$seg['id'];

        // Latest audit for this segment within this session
        $stmtAudit = $pdo->prepare(
            'SELECT * FROM segment_audits
             WHERE  segment_id = ? AND session_id = ?
             ORDER  BY id DESC LIMIT 1'
        );
        $stmtAudit->execute([$segId, $sessionId]);
        $audit = $stmtAudit->fetch(PDO::FETCH_ASSOC);

        $score         = null;
        $obstructions  = [];
        $intersections = [];

        if ($audit !== false) {
            $auditId = (int)$audit['id'];

            // Score via ScoreService — the ONLY place scoring logic lives
            try {
                $score = calculateSegmentScore($auditId, $pdo);
            } catch (\InvalidArgumentException) {
                $score = null;
            }

            // Obstructions
            $stmtObs = $pdo->prepare(
                'SELECT obstruction_category, obstruction_type,
                        cyclist_slowed, partial_obstructions, total_obstructions
                 FROM   obstructions WHERE audit_id = ?
                 ORDER  BY obstruction_category, obstruction_type'
            );
            $stmtObs->execute([$auditId]);
            $obstructions = $stmtObs->fetchAll(PDO::FETCH_ASSOC);

            // Intersections
            $stmtInt = $pdo->prepare(
                'SELECT intersection_num, gps_coords, landmark_name,
                        off_ramp, on_ramp, markings, signage
                 FROM   intersections WHERE audit_id = ?
                 ORDER  BY intersection_num ASC'
            );
            $stmtInt->execute([$auditId]);
            $intersections = $stmtInt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSON fields for the response
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