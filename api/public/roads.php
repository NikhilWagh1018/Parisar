<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/public/roads.php  (v2 — road_groups based)
//  GET — returns verified road names for the landing page.
//  Now reads from road_groups, the canonical per-road record,
//  instead of GROUP-BY-ing raw `roads` rows. One real road in,
//  one name out — no dedup logic needed here anymore, since
//  road_groups already represents the deduped truth.
//
//  Throttled: cheap single indexed query, but public and unlimited
//  before this change — capped to reduce scraping/abuse headroom.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/rate_limit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Request throttle: max 60 requests per 60 seconds per IP ────────
$clientIp = getClientIp();
$rl = checkAndRecordApiRequest($pdo, $clientIp, 'public_roads', 60, 60);
if (!$rl['allowed']) {
    header('Retry-After: ' . $rl['retry_after']);
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => $rl['message']]);
    exit;
}

// Optional ?city_id=<int> — same scoping as api/public/stats.php so the
// homepage road list always matches the stat strip for the chosen city.
$cityId = null;
if (isset($_GET['city_id']) && $_GET['city_id'] !== '' && $_GET['city_id'] !== 'all') {
    $cityId = filter_var($_GET['city_id'], FILTER_VALIDATE_INT);
    if ($cityId === false || $cityId < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid city_id.']);
        exit;
    }
}

try {
    $sql = "SELECT canonical_name
              FROM road_groups
             WHERE is_verified = 1";
    $params = [];
    if ($cityId !== null) {
        $sql .= " AND city_id = ?";
        $params[] = $cityId;
    }
    $sql .= " ORDER BY canonical_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $roads = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'roads'   => array_values($roads),
        'count'   => count($roads),
    ]);
} catch (Throwable $e) {
    error_log('api/public/roads.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}
