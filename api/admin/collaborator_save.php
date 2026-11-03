<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/collaborator_save.php
//  POST (multipart form) [collaborator_id], name, role_label (optional),
//       website_url (optional), logo (optional picture, up to 1 MB), remove_logo (edit only)
//  The Admin adds a collaborator (no collaborator_id) or changes one. It shows on the
//  home page's Collaborations section straight away (when visible).
//  admin only.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/collaborator_save.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/CityApi.php';
require_once __DIR__ . '/../../helpers/Collaborators.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';
require_once __DIR__ . '/../../repositories/CollaboratorRepository.php';

$in   = nationalFormContext($CURRENT_USER_ROLE);
$repo = new CollaboratorRepository($pdo);

$rawId = trim((string)($in['collaborator_id'] ?? ''));
$id    = null;
if ($rawId !== '') {
    $id = filter_var($rawId, FILTER_VALIDATE_INT);
    $existing = ($id !== false) ? $repo->find((int)$id) : null;
    if ($existing === null || $existing['deleted_at'] !== null) {
        cityApiFail(404, 'This collaborator was not found. It may already be removed.');
    }
}

$v = collaboratorValidate($in, $_FILES['logo'] ?? null);
if ($v['errors']) {
    cityApiFail(422, (string)reset($v['errors']), ['errors' => $v['errors']]);
}

if ($id === null) {
    $newId = $repo->create($v['clean'], (int)$CURRENT_USER_ID);
    ActivityLogger::log($pdo, ActivityLogger::COLLABORATOR_ADDED, $CURRENT_USER_ID, [
        'collaborator_id' => $newId, 'name' => $v['clean']['name'], 'has_logo' => $v['clean']['logo'] !== null,
    ]);
    echo json_encode(['success' => true, 'id' => $newId]);
    exit;
}

$repo->update((int)$id, $v['clean']);
ActivityLogger::log($pdo, ActivityLogger::COLLABORATOR_UPDATED, $CURRENT_USER_ID, [
    'collaborator_id' => (int)$id, 'name' => $v['clean']['name'],
]);
echo json_encode(['success' => true, 'id' => (int)$id]);
