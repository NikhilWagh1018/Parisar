<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/leaderboard/data.php
//  GET — surveyor rankings for the Leaderboard page (pages/leaderboard.php).
//  ?window=week (default) — current ISO week (Mon–Sun) only.
//  ?window=all             — all-time totals.
//  Ranked by segments_completed, distance_m as tiebreaker (matches
//  SegmentRepository::leaderboardRows' ORDER BY). Also flags which
//  row (if any) is the logged-in user, so the client can highlight
//  it even if it's off the top-50 list.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../repositories/SegmentRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$window = $_GET['window'] ?? 'week';
if (!in_array($window, ['week', 'all'], true)) {
    $window = 'week';
}

try {
    $repo = new SegmentRepository($pdo);
    $rows = $repo->leaderboardRows($window === 'week');

    $yourRank = null;
    $out      = [];
    foreach ($rows as $i => $r) {
        $rank    = $i + 1;
        $isYou   = $r['surveyor_id'] === $CURRENT_USER_ID;
        if ($isYou) {
            $yourRank = $rank;
        }
        $out[] = [
            'rank'                => $rank,
            'surveyor_id'         => $r['surveyor_id'],
            'surveyor_name'       => $r['surveyor_name'],
            'segments_completed'  => $r['segments_completed'],
            'distance_m'          => $r['distance_m'],
            'is_you'              => $isYou,
        ];
    }

    echo json_encode([
        'success'   => true,
        'window'    => $window,
        'rows'      => $out,
        'your_rank' => $yourRank, // null if you have no audits in this window
    ]);

} catch (PDOException $e) {
    error_log('api/leaderboard/data.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while loading leaderboard.']);
}
