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
require_once __DIR__ . '/../../helpers/CityScope.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// Only roads in the viewer's own city (national_admin sees all).
$cityScope = resolveViewerCityScope($pdo, $CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID);

$stmt = $pdo->prepare(
    'SELECT canonical_name
       FROM road_groups
      WHERE is_flagged = 0
        AND (:cid1 IS NULL OR city_id = :cid2)
      ORDER BY canonical_name ASC'
);
$stmt->execute(['cid1' => $cityScope, 'cid2' => $cityScope]);
$roads = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'success' => true,
    'roads'   => array_values($roads),
]);
