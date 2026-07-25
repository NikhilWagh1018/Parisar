<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/roads.php  (v3 — simple Add/Delete, no verify/flag)
//  GET  — all road_groups, each with its member `roads` rows
//         (id, creator, segment_count, created_at) nested inside,
//         for the Roads admin page.
//  POST — { action: 'create', name } to add a road (auto-visible),
//         { action: 'delete', id, confirm_name } to remove one,
//         where confirm_name must match the road's name exactly.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/roads.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';

function logAudit(PDO $pdo, int $actorId, string $actorName, string $action, int $groupId, string $groupName): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (actor_id, actor_name, action, road_group_id, road_group_name)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$actorId, $actorName, $action, $groupId, $groupName]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $groupStmt = $pdo->query(
        'SELECT id, canonical_name, is_verified, is_flagged, created_at
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
            'is_flagged'     => (bool)$group['is_flagged'],
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

    // ── Create path: { action: 'create', name: '...' } ──────────
    if (isset($body['action']) && $body['action'] === 'create') {
        $name = trim(strtoupper((string)($body['name'] ?? '')));

        if (mb_strlen($name) < 3) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Road name must be at least 3 characters.']);
            exit;
        }
        if (!preg_match('/^[A-Z0-9\s\.\-\/]+$/', $name)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Road name contains invalid characters.']);
            exit;
        }

        $dupStmt = $pdo->prepare('SELECT id FROM road_groups WHERE TRIM(UPPER(canonical_name)) = ? LIMIT 1');
        $dupStmt->execute([$name]);
        if ($dupStmt->fetchColumn() !== false) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'A road with that name already exists.']);
            exit;
        }

        // Admin-added roads are trusted by definition — no separate
        // verification step needed, so they go live immediately.
        $ins = $pdo->prepare('INSERT INTO road_groups (canonical_name, is_verified) VALUES (?, 1)');
        $ins->execute([$name]);
        $newId = (int)$pdo->lastInsertId();

        logAudit($pdo, $CURRENT_USER_ID, $CURRENT_USER_NAME, 'create', $newId, $name);

        echo json_encode(['success' => true, 'id' => $newId, 'name' => $name, 'is_verified' => true]);
        exit;
    }

    // ── Delete path: { action: 'delete', id, confirm_name } ──
    // confirm_name must exactly match the road's canonical_name (case-
    // insensitive) — the type-to-confirm safeguard that replaces the old
    // "only allowed when empty" restriction now that Delete is available
    // for every road, including ones with real audit entries under them.
    if (isset($body['action']) && $body['action'] === 'delete') {
        $id = isset($body['id']) ? (int)$body['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid road group id.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT canonical_name FROM road_groups WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Road group not found.']);
            exit;
        }

        $confirmName = trim((string)($body['confirm_name'] ?? ''));
        if (mb_strtoupper($confirmName) !== mb_strtoupper($group['canonical_name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Typed name did not match. Nothing was deleted.']);
            exit;
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM roads WHERE road_group_id = ?');
        $countStmt->execute([$id]);
        $entryCount = (int)$countStmt->fetchColumn();

        $pdo->beginTransaction();
        try {
            // road_group_id's own FK behaviour (if any) is unknown, since
            // road_groups isn't in any tracked migration. So member `roads`
            // rows are removed explicitly here rather than relying on it —
            // this cascades to segments/audit_sessions/segment_audits/
            // obstructions/intersections via the confirmed, migration-
            // tracked ON DELETE CASCADE chain on roads.id.
            if ($entryCount > 0) {
                $delRoads = $pdo->prepare('DELETE FROM roads WHERE road_group_id = ?');
                $delRoads->execute([$id]);
            }

            $del = $pdo->prepare('DELETE FROM road_groups WHERE id = ?');
            $del->execute([$id]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('api/admin/roads.php: delete failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Delete failed — nothing was removed. Please try again.']);
            exit;
        }

        if ($entryCount > 0) {
            error_log("api/admin/roads.php: deleted road group #{$id} ({$group['canonical_name']}) with {$entryCount} audit entries, by user {$CURRENT_USER_ID}");
        }
        try {
            logAudit($pdo, $CURRENT_USER_ID, $CURRENT_USER_NAME, 'delete', $id, $group['canonical_name']);
        } catch (PDOException $e) {
            error_log('api/admin/roads.php: delete succeeded but audit log write failed: ' . $e->getMessage());
        }

        echo json_encode(['success' => true, 'id' => $id, 'entries_deleted' => $entryCount]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unrecognized action.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
