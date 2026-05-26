<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/roads/create.php
//  POST — creates a new road owned by the logged-in user.
//  UPDATED: uses RoadRepository + Validator
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../helpers/Validator.php';
require_once __DIR__ . '/../../repositories/RoadRepository.php';

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

// ── Parse + validate body ──────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$v = Validator::make($data)
    ->required('name')
    ->maxLength('name', 255)
    ->in('segment_method', ['auto', 'manual'])
    ->numeric('total_length', 'segment_length');

// Extra business rules
if (isset($data['name']) && mb_strlen(trim((string)$data['name'])) < 3) {
    $v->addError('Road name must be at least 3 characters.');
}
$segmentMethod = trim((string)($data['segment_method'] ?? 'auto'));
$segmentLength = isset($data['segment_length']) ? (float)$data['segment_length'] : null;
if ($segmentMethod === 'auto' && ($segmentLength === null || $segmentLength <= 0)) {
    $v->addError('segment_length is required and must be > 0 when segment_method is "auto".');
}
$totalLength = isset($data['total_length']) ? (float)$data['total_length'] : null;
if ($totalLength !== null && $totalLength <= 0) {
    $v->addError('total_length must be a positive number.');
}

if ($v->fails()) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $v->allErrors()]);
    exit;
}

// ── Create via repository ──────────────────────────────────────
try {
    $repo   = new RoadRepository($pdo);
    $result = $repo->create($CURRENT_USER_ID, $data);

    echo json_encode([
        'success'   => true,
        'road_id'   => $result['road_id'],
        'public_id' => $result['public_id'],
    ]);

} catch (PDOException $e) {
    error_log('api/roads/create.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while creating road.']);
}
