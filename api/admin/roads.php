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
//
//  city_admin is scoped to road_groups in their own city only, for
//  both listing and create/delete. national_admin is unscoped, same
//  as before.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/roads.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';

$isNationalAdmin = $CURRENT_USER_ROLE === 'national_admin';
$cityId          = $isNationalAdmin ? null : $CURRENT_USER_CITY_ID;

function logAudit(PDO $pdo, int $actorId, string $actorName, string $action, int $groupId, string $groupName): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (actor_id, actor_name, action, road_group_id, road_group_name)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$actorId, $actorName, $action, $groupId, $groupName]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // A city_admin with no city assigned sees nothing, rather than
    // falling through to every city's roads.
    if (!$isNationalAdmin && $cityId === null) {
        echo json_encode(['success' => true, 'road_groups' => []]);
        exit;
    }

    $groupStmt = $pdo->prepare(
        'SELECT g.id, g.canonical_name, g.city_id, g.is_verified, g.is_flagged, g.created_at,
                g.assigned_surveyor_id, u.name AS assigned_surveyor_name
           FROM road_groups g
           LEFT JOIN users u ON u.id = g.assigned_surveyor_id
          WHERE (:cid1 IS NULL OR g.city_id = :cid2)
          ORDER BY g.canonical_name ASC'
    );
    $groupStmt->execute(['cid1' => $cityId, 'cid2' => $cityId]);
    $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

    // Surveyors available to assign, grouped by city_id, so each
    // road_group's row can offer only surveyors from its own city
    // (relevant once more than one city exists; harmless with one).
    $survStmt = $pdo->query(
        "SELECT id, name, city_id FROM users WHERE role = 'surveyor' AND city_id IS NOT NULL ORDER BY name ASC"
    );
    $surveyorsByCity = [];
    foreach ($survStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $surveyorsByCity[(int)$s['city_id']][] = ['id' => (int)$s['id'], 'name' => $s['name']];
    }

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
            'id'                     => (int)$group['id'],
            'name'                   => $group['canonical_name'],
            'is_verified'            => (bool)$group['is_verified'],
            'is_flagged'             => (bool)$group['is_flagged'],
            'created_at'             => $group['created_at'],
            'entry_count'            => count($members),
            'total_segments'         => $totalSegments,
            'members'                => $members,
            'assigned_surveyor_id'   => $group['assigned_surveyor_id'] !== null ? (int)$group['assigned_surveyor_id'] : null,
            'assigned_surveyor_name' => $group['assigned_surveyor_name'],
            'available_surveyors'    => $surveyorsByCity[(int)$group['city_id']] ?? [],
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

        // road_groups.city_id is NOT NULL. A city_admin always creates
        // within their own city. A national_admin may pass city_id
        // explicitly (once multiple cities exist); until then, this
        // falls back to the sole existing city, same convention as
        // RoadRepository::resolveCityIdForNewRoadGroup().
        if (!$isNationalAdmin) {
            if ($cityId === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Your account has no city assigned — contact a national admin.']);
                exit;
            }
            $newCityId = $cityId;
        } elseif (isset($body['city_id'])) {
            $newCityId = filter_var($body['city_id'], FILTER_VALIDATE_INT);
            $cityCheck = $pdo->prepare('SELECT 1 FROM cities WHERE id = ? LIMIT 1');
            $cityCheck->execute([$newCityId]);
            if ($newCityId === false || $cityCheck->fetchColumn() === false) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Invalid city_id.']);
                exit;
            }
        } else {
            $cityCountStmt = $pdo->query('SELECT COUNT(*) FROM cities');
            if ((int)$cityCountStmt->fetchColumn() !== 1) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'city_id is required — more than one city exists.']);
                exit;
            }
            $newCityId = (int)$pdo->query('SELECT id FROM cities LIMIT 1')->fetchColumn();
        }

        // Road names are unique per city, not globally: the same name in
        // another city is a different road.
        $dupStmt = $pdo->prepare(
            'SELECT id FROM road_groups WHERE city_id = ? AND TRIM(UPPER(canonical_name)) = ? LIMIT 1'
        );
        $dupStmt->execute([$newCityId, $name]);
        if ($dupStmt->fetchColumn() !== false) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'A road with that name already exists in this city.']);
            exit;
        }

        // Admin-added roads are trusted by definition — no separate
        // verification step needed, so they go live immediately.
        $ins = $pdo->prepare('INSERT INTO road_groups (canonical_name, city_id, is_verified) VALUES (?, ?, 1)');
        $ins->execute([$name, $newCityId]);
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
        // Data deletion is Admin-only in this phase (confirmed scope) —
        // a city_admin can create/view roads in their city but not
        // delete them; only national_admin may.
        if (!$isNationalAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only a national admin can delete roads.']);
            exit;
        }

        $id = isset($body['id']) ? (int)$body['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid road group id.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT canonical_name, city_id FROM road_groups WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Road group not found.']);
            exit;
        }

        if (!$isNationalAdmin && ((int)$group['city_id'] !== $cityId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You can only manage roads in your own city.']);
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

    // ── Assign path: { action: 'assign', id, surveyor_id } ───────
    // surveyor_id may be null/0 to unassign. Available to both
    // national_admin and city_admin (city-scoped), unlike delete.
    if (isset($body['action']) && $body['action'] === 'assign') {
        $id = isset($body['id']) ? (int)$body['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid road group id.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT canonical_name, city_id FROM road_groups WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Road group not found.']);
            exit;
        }

        if (!$isNationalAdmin && ((int)$group['city_id'] !== $cityId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You can only manage roads in your own city.']);
            exit;
        }

        $surveyorId = isset($body['surveyor_id']) ? (int)$body['surveyor_id'] : 0;

        if ($surveyorId <= 0) {
            // Unassign.
            $pdo->prepare(
                'UPDATE road_groups SET assigned_surveyor_id = NULL, assigned_at = NULL, assigned_by = NULL WHERE id = ?'
            )->execute([$id]);
            echo json_encode(['success' => true, 'id' => $id, 'assigned_surveyor_id' => null]);
            exit;
        }

        // Surveyor must exist, actually be a surveyor, and belong to
        // the SAME city as the road — otherwise a city_admin could
        // hand their roads to someone outside their city.
        $survStmt = $pdo->prepare(
            "SELECT id, name, city_id FROM users WHERE id = ? AND role = 'surveyor' LIMIT 1"
        );
        $survStmt->execute([$surveyorId]);
        $surveyor = $survStmt->fetch(PDO::FETCH_ASSOC);

        if (!$surveyor) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Surveyor not found.']);
            exit;
        }
        if ((int)$surveyor['city_id'] !== (int)$group['city_id']) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'That surveyor is not in this road\'s city.']);
            exit;
        }

        $pdo->prepare(
            'UPDATE road_groups SET assigned_surveyor_id = ?, assigned_at = NOW(), assigned_by = ? WHERE id = ?'
        )->execute([$surveyorId, $CURRENT_USER_ID, $id]);

        echo json_encode([
            'success'                => true,
            'id'                     => $id,
            'assigned_surveyor_id'   => $surveyorId,
            'assigned_surveyor_name' => $surveyor['name'],
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unrecognized action.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
