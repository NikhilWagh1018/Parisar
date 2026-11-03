<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/admin/collaborator_logo.php?id=N
//  GET -> a collaborator's logo for the Admin's list (hidden ones too).
//  admin only. The home page uses api/public/collaborator_logo.php instead.
// ═══════════════════════════════════════════════════════════════

set_exception_handler(function (Throwable $e) {
    error_log('api/admin/collaborator_logo.php error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server error.';
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/Collaborators.php';
require_once __DIR__ . '/../../repositories/CollaboratorRepository.php';

if ($CURRENT_USER_ROLE !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Only an Admin can do this.';
    exit;
}
$logo = (new CollaboratorRepository($pdo))->logo((int)($_GET['id'] ?? 0), false);
if ($logo === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No logo.';
    exit;
}
collaboratorSendLogo($logo[0], $logo[1], false);
