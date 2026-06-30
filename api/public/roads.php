<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/public/roads.php
//  GET — returns distinct, ADMIN-VERIFIED road names for the
//  landing page, no auth required.
//  MODIFIED: now filters to is_verified = 1 (see migrations/006).
//  Deploy order matters: run migration 006 BEFORE pushing this file,
//  or this query will fail with "Unknown column is_verified".
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
    // differ only by case or stray whitespace. is_verified = 1 hides
    // surveyor-created junk/duplicate roads from the public landing page
    // until an admin has explicitly confirmed them via pages/admin.php.
    $stmt = $pdo->query(
        "SELECT MIN(name) AS name
         FROM   roads
         WHERE  name IS NOT NULL AND TRIM(name) <> ''
           AND  is_verified = 1
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
