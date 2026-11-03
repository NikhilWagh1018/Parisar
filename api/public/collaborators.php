<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/public/collaborators.php
//  GET (no login) -> the organisations the Admin lists under "Collaborations" on the
//  home page: name, role, website and logo address, in the Admin's order. Hidden and
//  removed ones are not listed. No cache on the server, so an Admin's change shows up
//  at once. Same per-IP request limit as the other public endpoints.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
header('Cache-Control: public, max-age=30');

set_exception_handler(function (Throwable $e) {
    error_log('api/public/collaborators.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/rate_limit.php';
require_once __DIR__ . '/../../helpers/Collaborators.php';
require_once __DIR__ . '/../../repositories/CollaboratorRepository.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$rl = checkAndRecordApiRequest($pdo, getClientIp(), 'public_collaborators', 30, 60);
if (!$rl['allowed']) {
    header('Retry-After: ' . $rl['retry_after']);
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => $rl['message']]);
    exit;
}

$items = array_map('collaboratorShape', (new CollaboratorRepository($pdo))->visible());
echo json_encode(['success' => true, 'collaborators' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
