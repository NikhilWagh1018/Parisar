<?php
declare(strict_types=1);
// ════════════════════════════════════════════════════════════════
//  api/user/audit_compare.php
//  GET — before/after comparison for a segment the logged-in user
//        has audited more than once (Reporting roadmap item 2 of 2).
//
//  Query params:
//    segment_id = int (required)
//
//  Compares this user's FIRST audit ever submitted on the segment
//  ("before") against their MOST RECENT audit ("after") — i.e. the
//  oldest and newest rows in segment_audits for that segment_id +
//  surveyor_id pair. Any audits in between exist in the data but are
//  not surfaced here; only two-point before/after was scoped for this
//  delivery.
//
//  404s (not a 200 with an error payload) if the segment has fewer
//  than 2 audits by this user, or doesn't belong to them at all —
//  there's nothing to compare.
//
//  Reuses ScoreService's calculateScoresForAuditIds() (same scoring
//  path as the on-screen "My Audits" list and the Excel export) so
//  the comparison view can never disagree with either about what a
//  segment's condition/score is.
// ════════════════════════════════════════════════════════════════
header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../repositories/SegmentRepository.php';
require_once __DIR__ . '/../../services/ScoreService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $segmentId = (int)($_GET['segment_id'] ?? 0);
    if ($segmentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing or invalid segment_id.']);
        exit;
    }

    $repo = new SegmentRepository($pdo);

    $segment = $repo->findWithRoad($segmentId);
    if ($segment === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Segment not found.']);
        exit;
    }

    // Oldest-first list of this user's own audits on this segment.
    $history = $repo->auditHistoryForSegment($segmentId, $CURRENT_USER_ID);

    if (count($history) < 2) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'This segment has not been re-audited by you yet — nothing to compare.',
        ]);
        exit;
    }

    $before = $history[0];
    $after  = $history[count($history) - 1];

    // Batch-score both audits through the same path the on-screen list
    // and Excel export use, so condition/score can never drift here.
    $scores = calculateScoresForAuditIds([(int)$before['id'], (int)$after['id']], $pdo);

    $beforeObstructions = $repo->obstructionsForAudit((int)$before['id']);
    $afterObstructions  = $repo->obstructionsForAudit((int)$after['id']);

    $sumObstructions = static function (array $obstructions): array {
        $slowed = 0;
        $partial = 0;
        $total = 0;
        foreach ($obstructions as $o) {
            $slowed  += $o['slowed'];
            $partial += $o['partial'];
            $total   += $o['total'];
        }
        return ['slowed' => $slowed, 'partial' => $partial, 'total' => $total];
    };

    $shapeAudit = static function (array $audit, array $obstructionTotals) use ($scores): array {
        $auditId = (int)$audit['id'];
        $score   = $scores[$auditId] ?? null;

        return [
            'audit_id'        => $auditId,
            'created_at'      => $audit['created_at'],
            'segment_width'   => $audit['segment_width'] !== null ? (float)$audit['segment_width'] : null,
            'surface_material'=> $audit['surface_material'],
            'shade'           => $audit['shade'],
            'buffer_zone'     => $audit['buffer_zone'],
            'light_after_sunset' => $audit['light_after_sunset'],
            'cycle_track_missing' => $audit['cycle_track_missing'],
            'missing_length'  => $audit['missing_length'] !== null ? (float)$audit['missing_length'] : null,
            'surface_issues'  => $audit['surface_issues'],
            'overhead_issues' => $audit['overhead_issues'],
            'footpath_rating' => $audit['footpath_rating'],
            'comments'        => $audit['comments'],
            'obstructions'    => $obstructionTotals,
            'condition'       => $score['condition'] ?? null,
            'score'           => $score['final']      ?? null,
            'safety_score'    => $score['safety_score']     ?? null,
            'continuity_score'=> $score['continuity_score'] ?? null,
            'comfort_score'   => $score['comfort_score']    ?? null,
        ];
    };

    $beforeShaped = $shapeAudit($before, $sumObstructions($beforeObstructions));
    $afterShaped  = $shapeAudit($after, $sumObstructions($afterObstructions));

    $scoreDelta = ($beforeShaped['score'] !== null && $afterShaped['score'] !== null)
        ? round($afterShaped['score'] - $beforeShaped['score'], 2)
        : null;

    echo json_encode([
        'success' => true,
        'segment' => [
            'segment_id'     => (int)$segment['id'],
            'road_id'        => (int)$segment['road_id'],
            'road_name'      => $segment['road_name'],
            'segment_number' => (int)$segment['segment_number'],
        ],
        'audits_compared' => count($history),
        'before'    => $beforeShaped,
        'after'     => $afterShaped,
        'score_delta' => $scoreDelta,
    ]);
} catch (PDOException $e) {
    error_log('api/user/audit_compare.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while loading comparison.']);
}
