<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/roads/groups.php
//  GET — road_group names for the Road Audit "search or type a
//  road name" dropdown (pages/segment.php). Any logged-in user
//  (surveyor or admin) can read this — it's the live replacement
//  for the old hardcoded ROAD_LIST array in js/segment-roads.js,
//  which had drifted out of sync with the actual road_groups table.
//  Flagged (illegitimate) groups are excluded; verification status
//  is irrelevant here since is_verified only governs public-site
//  visibility, not whether a surveyor can attach an audit.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('api/roads/groups.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/auth_guard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$stmt = $pdo->query(
    'SELECT canonical_name
       FROM road_groups
      WHERE is_flagged = 0
      ORDER BY canonical_name ASC'
);
$roads = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'success' => true,
    'roads'   => array_values($roads),
]);
