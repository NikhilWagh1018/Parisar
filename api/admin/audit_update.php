<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/audit_update.php
//  POST { audit_id, name, audit_date (YYYY-MM-DD), programme_info?,
//         leader_user_id?, replace_leader? }
//  The Admin edits a project's name, audit date and programme info, and may
//  choose the City Leader of its city. The city and state do not change (the
//  roads and surveyors belong to the city). Everyone sees the change the next
//  time their screen loads, and the City Leader is told what changed.
//  A city has ONE City Leader: choosing someone else is refused unless
//  replace_leader is true, which makes the current leader a Surveyor.
//  admin only.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/audit_update.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/CityAudit.php';
require_once __DIR__ . '/../../helpers/Cities.php';
require_once __DIR__ . '/../../helpers/CityLeaderAssign.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../helpers/Notifications.php';
require_once __DIR__ . '/../../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../../repositories/NotificationRepository.php';

$body = nationalApiContext($CURRENT_USER_ROLE);
$repo = new CityAuditRepository($pdo);

$auditId = filter_var($body['audit_id'] ?? null, FILTER_VALIDATE_INT);
$audit   = ($auditId !== false && $auditId !== null) ? $repo->find((int)$auditId) : null;
if ($audit === null) {
    cityApiFail(404, 'Project not found.');
}
cityApiFailIfDeleted($audit);

// The state stays as it is; only name, date and programme info are edited here.
$in = $body;
$in['state'] = (string)$audit['state'];
$v = cityAuditValidate($in);
if ($v['errors']) {
    cityApiFail(422, (string)reset($v['errors']), ['errors' => $v['errors']]);
}

$cityId   = (int)$audit['city_id'];
$changed  = [];
$newLeader = null;       // id of the person made City Leader by this edit
$replaced  = null;       // the City Leader who was replaced
try {
    $pdo->beginTransaction();
    $changed = $repo->updateAudit($audit, $v['clean']);

    $leaderId = filter_var($body['leader_user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($leaderId !== false && $leaderId !== null && $leaderId > 0) {
        $u = $pdo->prepare('SELECT role, is_active, city_id FROM users WHERE id = ?');
        $u->execute([(int)$leaderId]);
        $leader = $u->fetch(PDO::FETCH_ASSOC) ?: null;
        $other  = cityLeaderOther($pdo, $cityId, (int)$leaderId);
        $alreadyLeader = $leader !== null && (string)$leader['role'] === 'city_leader' && (int)($leader['city_id'] ?? 0) === $cityId;
        if (!$alreadyLeader) {
            $replace = ($body['replace_leader'] ?? false) === true;
            $err = cityLeaderAssignError($leader, $cityId, ($other !== null && !$replace) ? $other['name'] : null);
            if ($err !== null) {
                throw new DomainException($err);
            }
            if ($other !== null) {
                $pdo->prepare("UPDATE users SET role = 'surveyor' WHERE id = ? AND role = 'city_leader'")->execute([$other['id']]);
                $replaced = $other;
            }
            $pdo->prepare("UPDATE users SET role = 'city_leader', city_id = ? WHERE id = ?")->execute([$cityId, (int)$leaderId]);
            $newLeader = (int)$leaderId;
        }
    }
    $pdo->commit();
} catch (DomainException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cityApiFail(409, $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

if ($changed) {
    ActivityLogger::log($pdo, ActivityLogger::AUDIT_UPDATED, $CURRENT_USER_ID, [
        'audit_id' => (int)$audit['id'], 'changed' => $changed, 'name' => $v['clean']['name'],
    ]);
}
if ($newLeader !== null) {
    ActivityLogger::log($pdo, ActivityLogger::USER_ROLE_CHANGED, $CURRENT_USER_ID, [
        'user_id' => $newLeader, 'to_role' => 'city_leader', 'city_id' => $cityId,
    ]);
    if ($replaced !== null) {
        ActivityLogger::log($pdo, ActivityLogger::USER_ROLE_CHANGED, $CURRENT_USER_ID, [
            'user_id' => $replaced['id'], 'from_role' => 'city_leader', 'to_role' => 'surveyor', 'city_id' => $cityId, 'replaced_by' => $newLeader,
        ]);
    }
}

// Tell the City Leader what changed. A failure here must never undo the edit.
try {
    $notes = new NotificationRepository($pdo);
    $link  = 'city_audit.php?id=' . (int)$audit['id'];
    if ($changed) {
        $leaderRow = cityLeaderOther($pdo, $cityId, null);
        if ($leaderRow !== null && $leaderRow['id'] !== $newLeader) {
            $t = projectUpdatedNotificationText((string)$v['clean']['name'], $changed);
            $notes->create($leaderRow['id'], NOTIFY_PROJECT_UPDATED, $t['title'], $t['body'], $link);
        }
    }
    if ($newLeader !== null) {
        $cn = $pdo->prepare('SELECT name FROM cities WHERE id = ?');
        $cn->execute([$cityId]);
        $cityName = (string)$cn->fetchColumn();
        $t = cityLeaderChangedNotificationText($cityName, true);
        $notes->create($newLeader, NOTIFY_LEADER_CHANGED, $t['title'], $t['body'], null);
        if ($replaced !== null) {
            $t = cityLeaderChangedNotificationText($cityName, false);
            $notes->create($replaced['id'], NOTIFY_LEADER_CHANGED, $t['title'], $t['body'], null);
        }
    }
} catch (Throwable $e) {
    error_log('project update notification failed: ' . $e->getMessage());
}

echo json_encode(['success' => true, 'changed' => $changed, 'leader_changed' => $newLeader !== null]);
