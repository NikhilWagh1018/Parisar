<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/public/stats.php  (v2 — city-aware, verified road_groups)
//  GET — returns public landing page stats (no auth required).
//
//  Optional query param:
//    ?city_id=<int>   limit every figure to one city.
//                     Omitted (or "all") = all cities combined.
//
//  Counting rules (match the Score Sheet export and the dashboards):
//    - Only VERIFIED road_groups count (one row per real-world road).
//    - Each segment is scored from its LATEST audit only.
//    - "Rated Good" = latest-audit final score <= 20, as a share of
//      AUDITED segments (not of all planned segments).
//
//  Cost: scoring is batched (3 queries total, see
//  loadLatestSegmentScores()) and the finished JSON is cached on disk
//  for STATS_CACHE_TTL seconds per scope, so a burst of public hits
//  costs one computation, not one per request.
// ═══════════════════════════════════════════════════════════════

const STATS_CACHE_TTL = 300; // seconds

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/rate_limit.php';
require_once __DIR__ . '/../../services/ScoreService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Request throttle: max 20 requests per 60 seconds per IP ────────
$clientIp = getClientIp();
$rl = checkAndRecordApiRequest($pdo, $clientIp, 'public_stats', 20, 60);
if (!$rl['allowed']) {
    header('Retry-After: ' . $rl['retry_after']);
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => $rl['message']]);
    exit;
}

// ── Scope: one city, or all cities combined ────────────────────────
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
    // ── Cities that actually have public (verified) roads ──────────
    // Feeds the homepage dropdown; also validates ?city_id.
    $cities = [];
    try {
        $cityRows = $pdo->query(
            'SELECT c.id, c.name
               FROM cities c
              WHERE EXISTS (SELECT 1 FROM road_groups g
                             WHERE g.city_id = c.id AND g.is_verified = 1)
              ORDER BY c.name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cityRows as $c) {
            $cities[] = ['id' => (int)$c['id'], 'name' => (string)$c['name']];
        }
    } catch (Throwable $e) {
        // `cities` isn't in a tracked migration — if the name column is
        // ever missing, fall back to ids so the stats still work.
        $cityRows = $pdo->query(
            'SELECT DISTINCT g.city_id AS id
               FROM road_groups g
              WHERE g.is_verified = 1 AND g.city_id IS NOT NULL
              ORDER BY g.city_id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cityRows as $c) {
            $cities[] = ['id' => (int)$c['id'], 'name' => 'City #' . (int)$c['id']];
        }
    }

    $scopeName = 'All cities';
    if ($cityId !== null) {
        $match = array_values(array_filter($cities, fn($c) => $c['id'] === $cityId));
        if (empty($match)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'City not found.']);
            exit;
        }
        $scopeName = $match[0]['name'];
    }

    // ── Server-side cache (per scope) ──────────────────────────────
    $cacheFile = sys_get_temp_dir() . '/cycleaudit_public_stats_' . ($cityId ?? 'all') . '.json';
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < STATS_CACHE_TTL) {
        $cached = file_get_contents($cacheFile);
        if (is_string($cached) && $cached !== '') {
            echo $cached;
            exit;
        }
    }

    // ── Member roads of every verified road_group in scope ─────────
    $roadSql =
        'SELECT r.id AS road_id, r.road_group_id
           FROM roads r
           JOIN road_groups g ON g.id = r.road_group_id
          WHERE g.is_verified = 1';
    $params = [];
    if ($cityId !== null) {
        $roadSql .= ' AND g.city_id = ?';
        $params[] = $cityId;
    }
    $roadStmt = $pdo->prepare($roadSql);
    $roadStmt->execute($params);
    $roadToGroup = [];
    foreach ($roadStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $roadToGroup[(int)$r['road_id']] = (int)$r['road_group_id'];
    }
    $roadIds = array_keys($roadToGroup);

    // ── All segments planned in scope (audited or not) ─────────────
    $totalSegments = 0;
    if (!empty($roadIds)) {
        $ph = implode(',', array_fill(0, count($roadIds), '?'));
        $segStmt = $pdo->prepare("SELECT COUNT(*) FROM segments WHERE road_id IN ({$ph})");
        $segStmt->execute($roadIds);
        $totalSegments = (int)$segStmt->fetchColumn();
    }

    // ── Audited segments: latest audit only, batched scoring ───────
    $scored = loadLatestSegmentScores($roadIds, $pdo);

    $auditedSegments = count($scored);
    $goodSegments    = 0;
    $auditedLengthM  = 0.0;
    $auditedGroups   = [];
    foreach ($scored as $row) {
        $auditedLengthM += $row['length'];
        $auditedGroups[$roadToGroup[$row['road_id']]] = true;
        if (ScoreHelpers::scoreToCondition($row['final']) === 'Good') {
            $goodSegments++;
        }
    }

    $payload = json_encode([
        'success' => true,
        'scope'   => ['city_id' => $cityId, 'name' => $scopeName],
        'stats'   => [
            'total_roads'      => count($auditedGroups),
            'total_length_km'  => round($auditedLengthM / 1000, 1),
            'total_segments'   => $totalSegments,
            'audited_segments' => $auditedSegments,
            'good_segments'    => $goodSegments,
            'good_pct'         => $auditedSegments > 0
                ? (int)round(($goodSegments / $auditedSegments) * 100)
                : 0,
        ],
        'cities'  => $cities,
    ]);

    // Atomic write so a concurrent reader never sees a half-written file.
    $tmp = tempnam(sys_get_temp_dir(), 'castats');
    if ($tmp !== false) {
        if (@file_put_contents($tmp, $payload) !== false) {
            @rename($tmp, $cacheFile);
        } else {
            @unlink($tmp);
        }
    }

    echo $payload;
} catch (Throwable $e) {
    error_log('api/public/stats.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}
