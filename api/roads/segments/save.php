<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/roads/segments/save.php
//  POST — inserts segments for ONE road.
//         Replaces any existing segments for that road safely
//         inside a transaction (cascade deletes children first).
//
//  Required JSON body:
//    road_id  int
//    segments array of {
//      segment_number int,
//      start_label    string,
//      end_label      string,
//      start_distance float,
//      end_distance   float,
//      length         float
//    }
//
//  Ownership check: only the road's creator_id may save segments.
//  Returns: { success: true, segments_saved: int }
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/db.php';

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
$raw      = file_get_contents('php://input');
$data     = json_decode($raw, true);
$roadId   = isset($data['road_id'])  ? (int)$data['road_id']  : 0;
$segments = $data['segments'] ?? [];

if ($roadId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid road_id is required.']);
    exit;
}

if (empty($segments) || !is_array($segments)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'segments array is required and must not be empty.']);
    exit;
}

try {
    // ── Ownership check ────────────────────────────────────────
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
        echo json_encode(['success' => false, 'error' => 'You do not have permission to modify this road.']);
        exit;
    }

    $pdo->beginTransaction();

    // ── Safe cascade delete of existing segments ───────────────
    // ON DELETE CASCADE handles segment_audits → obstructions → intersections
    $pdo->prepare('DELETE FROM segments WHERE road_id = ?')->execute([$roadId]);

    // ── Insert new segments ────────────────────────────────────
    $stmt = $pdo->prepare(
        'INSERT INTO segments
           (road_id, segment_number, start_label, end_label,
            start_distance, end_distance, length, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\')'
    );

    $saved = 0;
    foreach ($segments as $seg) {
        $segNum  = isset($seg['segment_number']) ? (int)$seg['segment_number']     : 0;
        $startL  = trim((string)($seg['start_label']    ?? ''));
        $endL    = trim((string)($seg['end_label']      ?? ''));
        $startD  = isset($seg['start_distance']) ? (float)$seg['start_distance']   : 0.0;
        $endD    = isset($seg['end_distance'])   ? (float)$seg['end_distance']     : 0.0;
        $length  = isset($seg['length'])         ? (float)$seg['length']           : 0.0;

        if ($segNum <= 0) {
            continue; // skip malformed entries
        }

        $stmt->execute([
            $roadId,
            $segNum,
            $startL ?: null,
            $endL   ?: null,
            $startD,
            $endD,
            $length,
        ]);

        // FIX: Generate public_id in PHP (no trigger)
        $segId       = (int)$pdo->lastInsertId();
        $segPublicId = 'SEG-' . str_pad((string)$segId, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE segments SET public_id = ? WHERE id = ?')
            ->execute([$segPublicId, $segId]);

        $saved++;
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'segments_saved' => $saved]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('api/roads/segments/save.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while saving segments.']);
}