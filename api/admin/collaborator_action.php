<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/collaborator_action.php
//  POST { collaborator_id, action }   action = show | hide | delete | up | down
//  show / hide: put a collaborator on / take it off the home page without deleting it.
//  delete: remove it from the home page and this list (the row and logo stay in the database).
//  up / down: change the order it appears in.
//  admin only.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/collaborator_action.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../repositories/CollaboratorRepository.php';

$body = nationalApiContext($CURRENT_USER_ROLE);

$action = (string)($body['action'] ?? '');
if (!in_array($action, ['show', 'hide', 'delete', 'up', 'down'], true)) {
    cityApiFail(422, 'Unknown action.');
}
$id   = filter_var($body['collaborator_id'] ?? null, FILTER_VALIDATE_INT);
$repo = new CollaboratorRepository($pdo);
$row  = ($id !== false && $id !== null) ? $repo->find((int)$id) : null;
if ($row === null || $row['deleted_at'] !== null) {
    cityApiFail(404, 'This collaborator was not found. It may already be removed.');
}
$meta = ['collaborator_id' => (int)$id, 'name' => (string)$row['name']];

switch ($action) {
    case 'show':
        $repo->setVisible((int)$id, true);
        ActivityLogger::log($pdo, ActivityLogger::COLLABORATOR_SHOWN, $CURRENT_USER_ID, $meta);
        break;
    case 'hide':
        $repo->setVisible((int)$id, false);
        ActivityLogger::log($pdo, ActivityLogger::COLLABORATOR_HIDDEN, $CURRENT_USER_ID, $meta);
        break;
    case 'delete':
        $repo->delete((int)$id);
        ActivityLogger::log($pdo, ActivityLogger::COLLABORATOR_REMOVED, $CURRENT_USER_ID, $meta);
        break;
    default:
        $repo->move((int)$id, $action);
}
echo json_encode(['success' => true]);
