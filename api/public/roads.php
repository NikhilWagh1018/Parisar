<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/public/roads.php
//  GET — returns distinct road names for the landing page,
//  no auth required.
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
    // GROUP BY TRIM(UPPER(name)) guards against duplicate-looking rows that
    // differ only by case or stray whitespace (the DB has a case-insensitive
    // unique constraint on name already, but this keeps the public list
    // defensively deduped regardless of future schema changes).
    $stmt = $pdo->query(
        "SELECT MIN(name) AS name
         FROM   roads
         WHERE  name IS NOT NULL AND TRIM(name) <> ''
         GROUP BY TRIM(UPPER(name))
         ORDER BY MIN(name) ASC"
    );
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
