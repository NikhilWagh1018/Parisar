<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/project_status.php
//  GET ?audit_id=N | ?road_id=N | ?segment_id=N   →  { success, deleted }
//  Lets an open screen find out that the Admin deleted its project, so the
//  work can stop at once (js/project_watch.js asks every few seconds).
//  A person only learns about projects they are part of: the Admin, the
//  City Leader of the project's city, or a surveyor holding a segment in it.
//  Anyone else always gets deleted:false.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
header('Cache-Control: no-store');

set_exception_handler(function (Throwable $e) {
    error_log('api/project_status.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/db.php';

$auditId = null;
if (isset($_GET['audit_id'])) {
    $auditId = (int)$_GET['audit_id'];
} elseif (isset($_GET['road_id'])) {
    $q = $pdo->prepare('SELECT audit_id FROM roads WHERE id = ?');
    $q->execute([(int)$_GET['road_id']]);
    $v = $q->fetchColumn();
    $auditId = ($v === false || $v === null) ? null : (int)$v;
} elseif (isset($_GET['segment_id'])) {
    $q = $pdo->prepare('SELECT r.audit_id FROM segments s JOIN roads r ON r.id = s.road_id WHERE s.id = ?');
    $q->execute([(int)$_GET['segment_id']]);
    $v = $q->fetchColumn();
    $auditId = ($v === false || $v === null) ? null : (int)$v;
}
if ($auditId === null || $auditId <= 0) {
    echo json_encode(['success' => true, 'deleted' => false]);
    exit;
}

$q = $pdo->prepare('SELECT city_id, status, leader_user_id FROM city_audits WHERE id = ?');
$q->execute([$auditId]);
$audit = $q->fetch(PDO::FETCH_ASSOC);
if ($audit === false || (string)$audit['status'] !== 'voided') {
    echo json_encode(['success' => true, 'deleted' => false]);
    exit;
}

// It is deleted: say so only to people who were part of it.
$involved = false;
if ($CURRENT_USER_ROLE === 'admin') {
    $involved = true;
} elseif ($CURRENT_USER_ROLE === 'city_leader') {
    $involved = (int)$audit['city_id'] === (int)($CURRENT_USER_CITY_ID ?? 0)
        && (int)($audit['leader_user_id'] ?? 0) === (int)$CURRENT_USER_ID;
} else {
    $m = $pdo->prepare('SELECT COUNT(*) FROM segment_assignments WHERE audit_id = ? AND surveyor_id = ?');
    $m->execute([$auditId, (int)$CURRENT_USER_ID]);
    $involved = (int)$m->fetchColumn() > 0;
}
echo json_encode(['success' => true, 'deleted' => $involved]);
