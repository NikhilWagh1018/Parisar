<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/public/collaborator_logo.php?id=N
//  GET (no login) -> the logo picture of a collaborator shown on the home page.
//  Hidden or removed collaborators and ones without a logo answer 404.
//  Same per-IP request limit as the other public endpoints.
// ═══════════════════════════════════════════════════════════════

set_exception_handler(function (Throwable $e) {
    error_log('api/public/collaborator_logo.php error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server error.';
    exit;
});

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/rate_limit.php';
require_once __DIR__ . '/../../helpers/Collaborators.php';
require_once __DIR__ . '/../../repositories/CollaboratorRepository.php';

function collabLogoPlain(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    collabLogoPlain(405, 'Method not allowed.');
}
$rl = checkAndRecordApiRequest($pdo, getClientIp(), 'public_collab_logo', 120, 60);
if (!$rl['allowed']) {
    header('Retry-After: ' . $rl['retry_after']);
    collabLogoPlain(429, 'Too many requests. Please try again in a minute.');
}

$repo = new CollaboratorRepository($pdo);
$id   = (int)($_GET['id'] ?? 0);
$logo = $repo->logo($id, true);
if ($logo === null) {
    collabLogoPlain(404, 'No logo.');
}
// A logo from before logos were normalised is converted (and kept) the first time it is shown.
$logo = $repo->upgradeLogo($id, $logo);
collaboratorSendLogo($logo[0], $logo[1], true);
