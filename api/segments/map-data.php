<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/segments/map-data.php
//  GET — the logged-in user's audited segments that have a captured
//        GPS start point, for the Map View page (pages/map.php).
//  gps_start is stored as "lat, lng" text (validated by the same
//  regex api/segments/submit.php enforces) — parsed to floats here
//  so the client never has to.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../repositories/SegmentRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $repo = new SegmentRepository($pdo);
    $rows = $repo->mapDataForUser($CURRENT_USER_ID);

    $points = [];
    foreach ($rows as $r) {
        // "lat, lng" -> [lat, lng]; skip defensively if a row somehow
        // doesn't match despite the NOT NULL/!= '' filter in the query.
        if (!preg_match('/^\s*(-?\d{1,3}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*$/', $r['gps_start'], $m)) {
            continue;
        }

        $points[] = [
            'segment_id'     => (int)$r['segment_id'],
            'road_id'        => (int)$r['road_id'],
            'road_name'      => $r['road_name'],
            'segment_number' => (int)$r['segment_number'],
            'status'         => $r['status'],
            'start_label'    => $r['start_label'],
            'lat'            => (float)$m[1],
            'lng'            => (float)$m[2],
        ];
    }

    echo json_encode(['success' => true, 'points' => $points]);

} catch (PDOException $e) {
    error_log('api/segments/map-data.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while loading map data.']);
}
