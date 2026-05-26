<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/dashboard/stats.php
//  GET — returns all dashboard data for the logged-in user.
//
//  FIXES APPLIED:
//    1. Now shows roads the user is AUDITING (has a session for)
//       in addition to roads they CREATED.
//       Previously only creator's own roads were returned.
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    // ── FIX: Include roads the user created OR has a session on ──
    $stmt = $pdo->prepare(
        'SELECT
             r.id                                          AS road_id,
             r.public_id                                   AS road_public_id,
             r.name                                        AS road_name,
             r.total_length,
             r.creator_id,
             COUNT(s.id)                                   AS total_segments,
             SUM(s.status = \'completed\')                 AS completed_segments,
             SUM(s.status = \'pending\')                   AS pending_segments,
             sess.id                                       AS session_id,
             sess.public_id                                AS session_public_id,
             sess.status                                   AS session_status,
             sess.updated_at                               AS last_activity
         FROM roads r
         LEFT JOIN segments s
               ON  s.road_id = r.id
         LEFT JOIN audit_sessions sess
               ON  sess.id = (
                     SELECT id FROM audit_sessions
                     WHERE  road_id = r.id AND user_id = ?
                     ORDER  BY updated_at DESC
                     LIMIT  1
                   )
         WHERE r.creator_id = ?
            OR r.id IN (
                 SELECT road_id FROM audit_sessions WHERE user_id = ?
               )
         GROUP BY r.id, sess.id
         ORDER BY r.created_at DESC'
    );
    // Pass user ID three times: once for sess subquery, once for creator check, once for session check
    $stmt->execute([$CURRENT_USER_ID, $CURRENT_USER_ID, $CURRENT_USER_ID]);
    $roads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Aggregate summary stats ────────────────────────────────
    $totalRoads        = count($roads);
    $totalSegments     = 0;
    $completedSegments = 0;
    $activeSessions    = 0;

    foreach ($roads as &$road) {
        $road['road_id']            = (int)$road['road_id'];
        $road['creator_id']         = (int)$road['creator_id'];
        $road['is_owner']           = ($road['creator_id'] === $CURRENT_USER_ID);
        $road['total_segments']     = (int)$road['total_segments'];
        $road['completed_segments'] = (int)$road['completed_segments'];
        $road['pending_segments']   = (int)$road['pending_segments'];
        $road['total_length']       = $road['total_length'] !== null ? (float)$road['total_length'] : null;
        $road['session_id']         = $road['session_id'] !== null ? (int)$road['session_id'] : null;

        $totalSegments     += $road['total_segments'];
        $completedSegments += $road['completed_segments'];

        if ($road['session_status'] === 'active') {
            $activeSessions++;
        }
    }
    unset($road);

    echo json_encode([
        'success' => true,
        'stats'   => [
            'total_roads'        => $totalRoads,
            'total_segments'     => $totalSegments,
            'completed_segments' => $completedSegments,
            'active_sessions'    => $activeSessions,
        ],
        'roads' => $roads,
    ]);

} catch (PDOException $e) {
    error_log('api/dashboard/stats.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while loading dashboard.']);
}
