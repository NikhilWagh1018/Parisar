<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/segments/audit-data.php
//  GET ?segment_id=N — returns latest audit record for pre-filling.
//  UPDATED: uses SegmentRepository + gate()
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../repositories/SegmentRepository.php';

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
    $repo = new SegmentRepository($pdo);

    // ── 1. Fetch segment + road ownership via repository ───────
    $segment = $repo->findWithRoad($segmentId);

    if ($segment === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Segment not found.']);
        exit;
    }

    // ── 2. RBAC gate ───────────────────────────────────────────
    gate('view_audit_data', $CURRENT_USER_ID, $CURRENT_USER_ROLE, ['owner_id' => $segment['creator_id']]);

    // ── 3. Fetch latest audit via repository ───────────────────
    $audit = $repo->latestAudit($segmentId);

    if ($audit === null) {
        echo json_encode(['success' => true, 'audit' => null, 'obstructions' => [], 'intersections' => []]);
        exit;
    }

    $auditId = (int)$audit['id'];

    // ── 4. Fetch obstructions + intersections via repository ───
    $obstructions  = $repo->obstructionsForAudit($auditId);
    $intersections = $repo->intersectionsForAudit($auditId);

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
