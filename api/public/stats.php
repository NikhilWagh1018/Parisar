<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/public/stats.php
//  GET — returns public landing page stats (no auth required).
//
//  Throttled: this endpoint loops over every segment_audits row and
//  recomputes scores on each call, so it's a real resource-exhaustion
//  risk if hit repeatedly and directly (the Cache-Control header only
//  helps browsers/CDNs — it doesn't cache anything server-side).
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');

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

try {
    $totalRoads    = (int)$pdo->query('SELECT COUNT(*) FROM roads')->fetchColumn();
    $totalLengthM  = (float)$pdo->query('SELECT COALESCE(SUM(total_length), 0) FROM roads')->fetchColumn();
    $totalLengthKm = round($totalLengthM / 1000);
    $totalSegments = (int)$pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn();

    // final_score is not persisted anywhere — it's computed on demand by
    // ScoreService::calculateSegmentScore(). Pull every audited segment's
    // id and tally how many score <= 20 ("Good", per ScoreHelpers::scoreToCondition).
    // This endpoint is cached 1 hour (see header above), so the per-row
    // computation cost here is fine.
    $auditIds = $pdo->query('SELECT id FROM segment_audits')->fetchAll(PDO::FETCH_COLUMN);

    $goodSegments = 0;
    foreach ($auditIds as $auditId) {
        $result = calculateSegmentScore((int)$auditId, $pdo);
        if ($result['final'] <= 20) {
            $goodSegments++;
        }
    }

    $goodPct = $totalSegments > 0 ? round(($goodSegments / $totalSegments) * 100) : 0;

    echo json_encode([
        'success' => true,
        'stats'   => [
            'total_roads'     => $totalRoads,
            'total_length_km' => $totalLengthKm,
            'total_segments'  => $totalSegments,
            'good_pct'        => $goodPct,
        ],
    ]);
} catch (Throwable $e) {
    error_log('api/public/stats.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}
