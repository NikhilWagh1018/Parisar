<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/roads.php  (v2 — road_groups based)
//  GET  — all road_groups, each with its member `roads` rows
//         (id, creator, segment_count, created_at) nested inside,
//         for the admin verification panel.
//  POST — toggle is_verified for a single road_groups id.
//         One toggle now affects every duplicate row under it.
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
    $groupStmt = $pdo->query(
        'SELECT id, canonical_name, is_verified, created_at
           FROM road_groups
          ORDER BY canonical_name ASC'
    );
    $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

    $memberStmt = $pdo->prepare(
        "SELECT
            r.id,
            r.road_group_id,
            r.creator_id,
            r.created_at,
            u.name AS creator_name,
            (SELECT COUNT(*) FROM segments s WHERE s.road_id = r.id) AS segment_count
         FROM roads r
         LEFT JOIN users u ON u.id = r.creator_id
         WHERE r.road_group_id = ?
         ORDER BY r.created_at ASC"
    );

    $result = [];
    foreach ($groups as $group) {
        $memberStmt->execute([$group['id']]);
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalSegments = 0;
        foreach ($members as &$m) {
            $m['id']            = (int)$m['id'];
            $m['creator_id']    = $m['creator_id'] !== null ? (int)$m['creator_id'] : null;
            $m['segment_count'] = (int)$m['segment_count'];
            $totalSegments     += $m['segment_count'];
        }
        unset($m);

        $result[] = [
            'id'             => (int)$group['id'],
            'name'           => $group['canonical_name'],
            'is_verified'    => (bool)$group['is_verified'],
            'created_at'     => $group['created_at'],
            'entry_count'    => count($members),
            'total_segments' => $totalSegments,
            'members'        => $members,
        ];
    }

    echo json_encode(['success' => true, 'road_groups' => $result]);
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
        echo json_encode(['success' => false, 'error' => 'Invalid road group id.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT is_verified FROM road_groups WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Road group not found.']);
        exit;
    }

    $newValue = $row['is_verified'] ? 0 : 1;
    $upd = $pdo->prepare('UPDATE road_groups SET is_verified = ? WHERE id = ?');
    $upd->execute([$newValue, $id]);

    echo json_encode(['success' => true, 'id' => $id, 'is_verified' => (bool)$newValue]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
