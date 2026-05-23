<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/segments/audit-data.php
//  GET ?segment_id=N
//  Returns the most-recent audit record for a segment so the
//  edit form can pre-fill every field with the existing answers.
//
//  Response shape:
//  {
//    "success": true,
//    "audit": { ...all segment_audits columns... },
//    "obstructions": [
//      { "category":"fixed", "type":"Trees",
//        "slowed":0, "partial":1, "total":0 }, ...
//    ],
//    "intersections": [
//      { "intersection_num":1, "gps_coords":"...", "landmark_name":"...",
//        "off_ramp":"...", "on_ramp":"...", "markings":"...",
//        "signage":"...", "traffic_calming":"...",
//        "discontinuity":"...", "tapering":"...",
//        "obstruction_type":"..." }, ...
//    ]
//  }
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$segmentId = isset($_GET['segment_id']) ? (int)$_GET['segment_id'] : 0;

if ($segmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid segment_id.']);
    exit;
}

try {
    // ── 1. Verify segment exists and belongs to a road owned by
    //       this user (or the user is the surveyor) ─────────────
    $stmtSeg = $pdo->prepare(
        'SELECT s.id, s.road_id, r.creator_id
           FROM segments s
           JOIN roads    r ON r.id = s.road_id
          WHERE s.id = ?
          LIMIT 1'
    );
    $stmtSeg->execute([$segmentId]);
    $segment = $stmtSeg->fetch(PDO::FETCH_ASSOC);

    if (!$segment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Segment not found.']);
        exit;
    }

    if ((int)$segment['creator_id'] !== (int)$CURRENT_USER_ID) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }

    // ── 2. Fetch the most-recent audit record ──────────────────
    $stmtAudit = $pdo->prepare(
        'SELECT id, session_id,
                start_landmark, end_landmark, gps_start, gps_end,
                cycle_track_missing, missing_length, cyclist_use, better_surface,
                surface_material, people_walking, signage_count, shade,
                light_after_sunset, track_geometry, buffer_zone,
                segment_width, segment_length, comments,
                surface_issues, overhead_issues, footpath_rating
           FROM segment_audits
          WHERE segment_id = ?
          ORDER BY id DESC
          LIMIT 1'
    );
    $stmtAudit->execute([$segmentId]);
    $audit = $stmtAudit->fetch(PDO::FETCH_ASSOC);

    if (!$audit) {
        // No audit data yet — nothing to pre-fill
        echo json_encode(['success' => true, 'audit' => null,
                          'obstructions' => [], 'intersections' => []]);
        exit;
    }

    $auditId = (int)$audit['id'];

    // Decode JSON-stored arrays
    $audit['surface_issues']  = json_decode($audit['surface_issues']  ?? '[]', true) ?? [];
    $audit['overhead_issues'] = json_decode($audit['overhead_issues'] ?? '[]', true) ?? [];
    $audit['footpath_rating'] = json_decode($audit['footpath_rating'] ?? '[]', true) ?? [];

    // ── 3. Fetch obstructions ──────────────────────────────────
    $stmtObs = $pdo->prepare(
        'SELECT obstruction_category AS category,
                obstruction_type     AS type,
                cyclist_slowed       AS slowed,
                partial_obstructions AS partial,
                total_obstructions   AS total
           FROM obstructions
          WHERE audit_id = ?'
    );
    $stmtObs->execute([$auditId]);
    $obstructions = $stmtObs->fetchAll(PDO::FETCH_ASSOC);

    // Cast counts to int
    foreach ($obstructions as &$o) {
        $o['slowed']  = (int)$o['slowed'];
        $o['partial'] = (int)$o['partial'];
        $o['total']   = (int)$o['total'];
    }
    unset($o);

    // ── 4. Fetch intersections ─────────────────────────────────
    $stmtInt = $pdo->prepare(
        'SELECT intersection_num, gps_coords, landmark_name,
                off_ramp, on_ramp, markings, signage,
                traffic_calming, discontinuity, tapering, obstruction_type
           FROM intersections
          WHERE audit_id = ?
          ORDER BY intersection_num ASC'
    );
    $stmtInt->execute([$auditId]);
    $intersections = $stmtInt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'       => true,
        'audit'         => $audit,
        'obstructions'  => $obstructions,
        'intersections' => $intersections,
    ]);

} catch (Throwable $e) {
    error_log('audit-data.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}
