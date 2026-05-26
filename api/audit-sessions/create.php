<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/audit-sessions/create.php
//  POST — creates or resumes an audit session for a road.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    error_log('audit-sessions/create.php uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../helpers/Validator.php';
require_once __DIR__ . '/../../repositories/RoadRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

$v = Validator::make($data)
    ->required('road_id')
    ->integer('road_id')
    ->min('road_id', 1);

if ($v->fails()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $v->firstError()]);
    exit;
}

$roadId = (int)$data['road_id'];

try {
    $repo = new RoadRepository($pdo);

    // Resume existing active session if one exists
    $existing = $repo->findActiveSession($CURRENT_USER_ID, $roadId);
    if ($existing) {
        echo json_encode([
            'success'    => true,
            'session_id' => $existing['id'],
            'public_id'  => $existing['public_id'],
            'resumed'    => true,
        ]);
        exit;
    }

    // Create new session
    $result = $repo->createSession($CURRENT_USER_ID, $roadId);

    echo json_encode([
        'success'    => true,
        'session_id' => $result['session_id'],
        'public_id'  => $result['public_id'],
        'resumed'    => false,
    ]);

} catch (Throwable $e) {
    error_log('api/audit-sessions/create.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
