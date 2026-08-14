<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/roads/segments/index.php
//  GET ?road_id= — returns all segments for a road plus road metadata.
//
//  Returns:
//  {
//    success: true,
//    road: { id, public_id, name, start_point, end_point,
//            total_length, gps_start, gps_end,
//            segment_method, segment_length, finalized_at },
//    segments: [ { id, public_id, segment_number, start_label,
//                  end_label, start_distance, end_distance,
//                  length, status, completed_at } ]
//  }
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$roadId = isset($_GET['road_id']) ? (int)$_GET['road_id'] : 0;

if ($roadId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid road_id query parameter is required.']);
    exit;
}

try {
    // ── Fetch road ─────────────────────────────────────────────
    $stmtRoad = $pdo->prepare(
        'SELECT id, public_id, creator_id, name, start_point, end_point,
                total_length, gps_start, gps_end, segment_method, segment_length,
                finalized_at
         FROM   roads
         WHERE  id = ?
         LIMIT  1'
    );
    $stmtRoad->execute([$roadId]);
    $road = $stmtRoad->fetch(PDO::FETCH_ASSOC);

    if ($road === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Road not found.']);
        exit;
    }

    // ── Ownership check ────────────────────────────────────────
    // Any logged-in user can VIEW segments; only creators can modify.
    // No restriction here — read is open to all authenticated surveyors.

    // ── Fetch segments ─────────────────────────────────────────
    $stmtSegs = $pdo->prepare(
        'SELECT id, public_id, segment_number, start_label, end_label,
                start_distance, end_distance, length, status, completed_at
         FROM   segments
         WHERE  road_id = ?
         ORDER  BY segment_number ASC'
    );
    $stmtSegs->execute([$roadId]);
    $segments = $stmtSegs->fetchAll(PDO::FETCH_ASSOC);

    // MySQL DATETIME values come back as "Y-m-d H:i:s" with no timezone
    // marker. NOW() on this server writes UTC, but a bare string like that
    // gets parsed as LOCAL time by JS's `new Date()` — on a UTC+5:30
    // browser that silently shifts every "time ago" display by ~5.5h
    // (e.g. a segment audited moments ago showing "5h ago"). Stamp these
    // explicitly as UTC before sending so the client parses them correctly.
    $toIsoUtc = static function (?string $val): ?string {
        if ($val === null || $val === '') {
            return null;
        }
        return str_replace(' ', 'T', $val) . 'Z';
    };

    // Cast numeric fields for clean JSON output
    $road['id']             = (int)$road['id'];
    $road['creator_id']     = (int)$road['creator_id'];
    $road['total_length']   = $road['total_length']   !== null ? (float)$road['total_length']   : null;
    $road['segment_length'] = $road['segment_length'] !== null ? (float)$road['segment_length'] : null;
    $road['finalized_at']   = $toIsoUtc($road['finalized_at']);

    $segments = array_map(static function (array $seg) use ($toIsoUtc): array {
        $seg['id']             = (int)$seg['id'];
        $seg['segment_number'] = (int)$seg['segment_number'];
        $seg['start_distance'] = (float)$seg['start_distance'];
        $seg['end_distance']   = (float)$seg['end_distance'];
        $seg['length']         = (float)$seg['length'];
        $seg['completed_at']   = $toIsoUtc($seg['completed_at']);
        return $seg;
    }, $segments);

    echo json_encode([
        'success'  => true,
        'road'     => $road,
        'segments' => $segments,
    ]);

} catch (PDOException $e) {
    error_log('api/roads/segments/index.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while fetching segments.']);
}