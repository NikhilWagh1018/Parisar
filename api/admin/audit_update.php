<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/audit_update.php
//  POST { audit_id, name, audit_date (YYYY-MM-DD), programme_info?,
//         leader_user_id? }
//  The Admin edits a project's name, audit date and programme info, and may
//  assign the project to one of the city's City Leaders. The city and state do not change (the
//  roads and surveyors belong to the city). Everyone sees the change the next
//  time their screen loads, and the City Leader is told what changed.
//  A city can have several City Leaders; the project belongs to the one chosen here.
//  Only a City Leader of the project's city can be chosen, never a Surveyor.
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
$newLeader = null;       // id of the City Leader this edit assigned the project to
$oldLeader = $audit['leader_user_id'] !== null ? (int)$audit['leader_user_id'] : null;
try {
    $pdo->beginTransaction();
    $changed = $repo->updateAudit($audit, $v['clean']);

    $leaderId = filter_var($body['leader_user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($leaderId !== false && $leaderId !== null && $leaderId > 0 && (int)$leaderId !== $oldLeader) {
        $u = $pdo->prepare('SELECT role, is_active, city_id FROM users WHERE id = ?');
        $u->execute([(int)$leaderId]);
        $err = projectLeaderError($u->fetch(PDO::FETCH_ASSOC) ?: null, $cityId);
        if ($err !== null) {
            throw new DomainException($err);
        }
        $repo->setLeader((int)$audit['id'], (int)$leaderId);
        $newLeader = (int)$leaderId;
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
    ActivityLogger::log($pdo, ActivityLogger::AUDIT_UPDATED, $CURRENT_USER_ID, [
        'audit_id' => (int)$audit['id'], 'changed' => ['City Leader'], 'leader_user_id' => $newLeader, 'previous_leader_user_id' => $oldLeader,
    ]);
}

// Tell the City Leaders involved. A failure here must never undo the edit.
try {
    $notes = new NotificationRepository($pdo);
    $link  = 'city_audit.php?id=' . (int)$audit['id'];
    if ($changed && $oldLeader !== null && $oldLeader !== $newLeader) {
        $t = projectUpdatedNotificationText((string)$v['clean']['name'], $changed);
        $notes->create($oldLeader, NOTIFY_PROJECT_UPDATED, $t['title'], $t['body'], $link);
    }
    if ($newLeader !== null) {
        $t = projectAssignedNotificationText((string)$v['clean']['name']);
        $notes->create($newLeader, NOTIFY_PROJECT_ASSIGNED, $t['title'], $t['body'], $link);
        if ($oldLeader !== null) {
            $t = projectUnassignedNotificationText((string)$v['clean']['name']);
            $notes->create($oldLeader, NOTIFY_PROJECT_ASSIGNED, $t['title'], $t['body'], null);
        }
    }
} catch (Throwable $e) {
    error_log('project update notification failed: ' . $e->getMessage());
}

echo json_encode(['success' => true, 'changed' => $changed, 'leader_changed' => $newLeader !== null]);
