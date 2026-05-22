<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/roads/update.php
//  POST — updates an existing road owned by the logged-in user.
//
//  Required JSON body fields:
//    road_id        int
//    name           string  (3–255 chars)
//    start_point    string
//    end_point      string
//    total_length   float   (metres)
//    gps_start      string
//    gps_end        string
//    segment_method string  "auto"|"manual"
//    segment_length float   (metres, required when method=auto)
//
//  Returns: { success: true, road_id: int }
// ═══════════════════════════════════════════════════════════════

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
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

// ── Input extraction ───────────────────────────────────────────
$roadId        = isset($data['road_id'])       ? (int)$data['road_id']           : null;
$name          = trim((string)($data['name']           ?? ''));
$startPoint    = trim((string)($data['start_point']    ?? ''));
$endPoint      = trim((string)($data['end_point']      ?? ''));
$totalLength   = isset($data['total_length'])  ? (float)$data['total_length']    : null;
$gpsStart      = trim((string)($data['gps_start']      ?? ''));
$gpsEnd        = trim((string)($data['gps_end']        ?? ''));
$segmentMethod = trim((string)($data['segment_method'] ?? 'auto'));
$segmentLength = isset($data['segment_length']) ? (float)$data['segment_length'] : null;

// ── Validation ─────────────────────────────────────────────────
$errors = [];

if (!$roadId || $roadId <= 0) {
    $errors[] = 'A valid road_id is required.';
}
if (strlen($name) < 3) {
    $errors[] = 'Road name must be at least 3 characters.';
} elseif (strlen($name) > 255) {
    $errors[] = 'Road name must be under 255 characters.';
}
if (!in_array($segmentMethod, ['auto', 'manual'], true)) {
    $errors[] = 'segment_method must be "auto" or "manual".';
}
if ($segmentMethod === 'auto' && ($segmentLength === null || $segmentLength <= 0)) {
    $errors[] = 'segment_length is required and must be > 0 when segment_method is "auto".';
}
if ($totalLength !== null && $totalLength <= 0) {
    $errors[] = 'total_length must be a positive number.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// ── Verify ownership ───────────────────────────────────────────
try {
    $chk = $pdo->prepare('SELECT id FROM roads WHERE id = ? AND creator_id = ?');
    $chk->execute([$roadId, $CURRENT_USER_ID]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Road not found or access denied.']);
        exit;
    }

    // ── Update ─────────────────────────────────────────────────
    $stmt = $pdo->prepare(
        'UPDATE roads SET
           name           = ?,
           start_point    = ?,
           end_point      = ?,
           total_length   = ?,
           gps_start      = ?,
           gps_end        = ?,
           segment_method = ?,
           segment_length = ?
         WHERE id = ? AND creator_id = ?'
    );
    $stmt->execute([
        strtoupper(strip_tags($name)),
        $startPoint  ?: null,
        $endPoint    ?: null,
        $totalLength,
        $gpsStart    ?: null,
        $gpsEnd      ?: null,
        $segmentMethod,
        $segmentLength,
        $roadId,
        $CURRENT_USER_ID,
    ]);

    echo json_encode([
        'success' => true,
        'road_id' => $roadId,
    ]);

} catch (PDOException $e) {
    error_log('api/roads/update.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while updating road.']);
}