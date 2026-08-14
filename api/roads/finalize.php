<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/roads/finalize.php
//  PUT — permanently locks a fully-audited road against further
//  edits. Requires every segment on the road to already be
//  'completed'. Idempotent: finalizing an already-finalized road
//  returns success with already_finalized = true rather than error.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../helpers/Validator.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../repositories/RoadRepository.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

$v = Validator::make($data)
    ->required('road_id')
    ->integer('road_id')
    ->min('road_id', 1);

if ($v->fails()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $v->firstError()]);
    exit;
}

$roadId = (int)$data['road_id'];

try {
    $roadRepo = new RoadRepository($pdo);

    $road = $roadRepo->find($roadId);
    if ($road === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Road not found.']);
        exit;
    }

    gate('finalize_road', $CURRENT_USER_ID, $CURRENT_USER_ROLE, ['owner_id' => $road['creator_id']]);

    if ($road['finalized_at'] !== null) {
        echo json_encode(['success' => true, 'already_finalized' => true]);
        exit;
    }

    if (!$roadRepo->allSegmentsCompleted($roadId)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'All segments must be audited before this road can be finalized.']);
        exit;
    }

    $roadRepo->finalize($roadId);

    ActivityLogger::log($pdo, ActivityLogger::ROAD_FINALIZED, $CURRENT_USER_ID, [
        'road_id' => $roadId,
    ]);

    echo json_encode(['success' => true, 'already_finalized' => false]);

} catch (Throwable $e) {
    error_log('api/roads/finalize.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}
