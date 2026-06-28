<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/public/stats.php
//  GET — returns public landing page stats (no auth required).
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $totalRoads    = (int)$pdo->query('SELECT COUNT(*) FROM roads')->fetchColumn();
    $totalLengthM  = (float)$pdo->query('SELECT COALESCE(SUM(total_length), 0) FROM roads')->fetchColumn();
    $totalLengthKm = round($totalLengthM / 1000);
    $totalSegments = (int)$pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn();
    $goodSegments  = (int)$pdo->query(
        'SELECT COUNT(*) FROM segment_audits WHERE final_score <= 20 AND final_score IS NOT NULL'
    )->fetchColumn();
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
} catch (PDOException $e) {
    error_log('api/public/stats.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}
