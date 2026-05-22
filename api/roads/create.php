<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/roads/create.php
//  POST — creates a new road owned by the logged-in user.
//
//  Required JSON body fields:
//    name           string  (3–255 chars)
//    start_point    string
//    end_point      string
//    total_length   float   (metres)
//    gps_start      string
//    gps_end        string
//    segment_method string  "auto"|"manual"
//    segment_length float   (metres, required when method=auto)
//
//  Returns: { success: true, road_id: int, public_id: string }
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';

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

// ── Parse JSON body ────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

// ── Input extraction & sanitisation ───────────────────────────
$name          = trim((string)($data['name']           ?? ''));
$startPoint    = trim((string)($data['start_point']    ?? ''));
$endPoint      = trim((string)($data['end_point']      ?? ''));
$totalLength   = isset($data['total_length'])   ? (float)$data['total_length']   : null;
$gpsStart      = trim((string)($data['gps_start']      ?? ''));
$gpsEnd        = trim((string)($data['gps_end']        ?? ''));
$segmentMethod = trim((string)($data['segment_method'] ?? 'auto'));
$segmentLength = isset($data['segment_length']) ? (float)$data['segment_length'] : null;

// ── Validation ─────────────────────────────────────────────────
$errors = [];

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

// ── Insert ─────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        'INSERT INTO roads
           (creator_id, name, start_point, end_point, total_length,
            gps_start, gps_end, segment_method, segment_length)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $CURRENT_USER_ID,
        strtoupper(strip_tags($name)),
        $startPoint  ?: null,
        $endPoint    ?: null,
        $totalLength,
        $gpsStart    ?: null,
        $gpsEnd      ?: null,
        $segmentMethod,
        $segmentLength,
    ]);

    $roadId = (int)$pdo->lastInsertId();

    // Generate public_id in PHP (no trigger needed)
    $roadPublicId = 'ROAD-' . str_pad((string)$roadId, 4, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE roads SET public_id = ? WHERE id = ?')->execute([$roadPublicId, $roadId]);

    echo json_encode([
        'success'   => true,
        'road_id'   => $roadId,
        'public_id' => $roadPublicId,
    ]);

} catch (PDOException $e) {
    error_log('api/roads/create.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while creating road.']);
}