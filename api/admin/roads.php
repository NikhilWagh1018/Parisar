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

        $ins = $pdo->prepare('INSERT INTO road_groups (canonical_name, is_verified) VALUES (?, 0)');
        $ins->execute([$name]);
        $newId = (int)$pdo->lastInsertId();

        logAudit($pdo, $CURRENT_USER_ID, $CURRENT_USER_NAME, 'create', $newId, $name);

        echo json_encode(['success' => true, 'id' => $newId, 'name' => $name, 'is_verified' => false]);
        exit;
    }

    // ── Bulk path: { ids: [1,2,3], action: 'verify'|'flag', value: true|false } ──
    if (isset($body['ids']) && is_array($body['ids'])) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $body['ids']), fn($v) => $v > 0)));
        $bulkAction = isset($body['action']) && $body['action'] === 'flag' ? 'flag' : 'verify';
        $value      = !empty($body['value']) ? 1 : 0;

        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No valid road group ids provided.']);
            exit;
        }

        $column     = $bulkAction === 'flag' ? 'is_flagged' : 'is_verified';
        $logVerb    = $value
            ? ($bulkAction === 'flag' ? 'flag' : 'verify')
            : ($bulkAction === 'flag' ? 'unflag' : 'unverify');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $nameStmt = $pdo->prepare("SELECT id, canonical_name FROM road_groups WHERE id IN ($placeholders)");
        $nameStmt->execute($ids);
        $names = $nameStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        if (empty($names)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No matching road groups found.']);
            exit;
        }

        $foundIds = array_map('intval', array_keys($names));

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE road_groups SET $column = ? WHERE id IN ($placeholders)");
            $upd->execute(array_merge([$value], $foundIds));

            foreach ($foundIds as $gid) {
                logAudit($pdo, $CURRENT_USER_ID, $CURRENT_USER_NAME, $logVerb, $gid, $names[$gid]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        echo json_encode(['success' => true, 'updated_ids' => $foundIds, 'action' => $logVerb]);
        exit;
    }

    // ── Single path: { id, action: 'verify'|'flag' } ──
    $id     = isset($body['id']) ? (int)$body['id'] : 0;
    $action = isset($body['action']) && $body['action'] === 'flag' ? 'flag' : 'verify';

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid road group id.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT canonical_name, is_verified, is_flagged FROM road_groups WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Road group not found.']);
        exit;
    }

    if ($action === 'flag') {
        $newValue = $row['is_flagged'] ? 0 : 1;
        $upd = $pdo->prepare('UPDATE road_groups SET is_flagged = ? WHERE id = ?');
        $upd->execute([$newValue, $id]);
        logAudit($pdo, $CURRENT_USER_ID, $CURRENT_USER_NAME, $newValue ? 'flag' : 'unflag', $id, $row['canonical_name']);
        echo json_encode(['success' => true, 'id' => $id, 'is_flagged' => (bool)$newValue]);
        exit;
    }

    $newValue = $row['is_verified'] ? 0 : 1;
    $upd = $pdo->prepare('UPDATE road_groups SET is_verified = ? WHERE id = ?');
    $upd->execute([$newValue, $id]);
    logAudit($pdo, $CURRENT_USER_ID, $CURRENT_USER_NAME, $newValue ? 'verify' : 'unverify', $id, $row['canonical_name']);

    echo json_encode(['success' => true, 'id' => $id, 'is_verified' => (bool)$newValue]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
