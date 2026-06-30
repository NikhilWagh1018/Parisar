<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/roads.php
//  GET  — all roads (not deduped), with is_verified + creator info,
//         for the admin verification panel.
//  POST — toggle is_verified for a single road id (CSRF protected).
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/roads.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query(
        "SELECT
            r.id,
            r.name,
            r.is_verified,
            r.creator_id,
            r.created_at,
            u.name AS creator_name,
            (SELECT COUNT(*) FROM segments s WHERE s.road_id = r.id) AS segment_count
         FROM roads r
         LEFT JOIN users u ON u.id = r.creator_id
         ORDER BY r.name ASC, r.created_at ASC"
    );
    $roads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($roads as &$row) {
        $row['id']            = (int)$row['id'];
        $row['is_verified']   = (bool)$row['is_verified'];
        $row['creator_id']    = $row['creator_id'] !== null ? (int)$row['creator_id'] : null;
        $row['segment_count'] = (int)$row['segment_count'];
    }
    unset($row);

    echo json_encode(['success' => true, 'roads' => $roads]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── CSRF verification ──────────────────────────────────────
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $id   = isset($body['id']) ? (int)$body['id'] : 0;

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid road id.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT is_verified FROM roads WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Road not found.']);
        exit;
    }

    $newValue = $row['is_verified'] ? 0 : 1;
    $upd = $pdo->prepare('UPDATE roads SET is_verified = ? WHERE id = ?');
    $upd->execute([$newValue, $id]);

    echo json_encode(['success' => true, 'id' => $id, 'is_verified' => (bool)$newValue]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
