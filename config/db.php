<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/db.php
//  PDO connection — singleton pattern so requiring this file
//  multiple times in one request never opens a second connection.
//  Database: parisar_db
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/constants.php';

(static function (): void {
    if (isset($GLOBALS['pdo'])) {
        return;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // use real prepared statements
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];

    try {
        $GLOBALS['pdo'] = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Never expose credentials or internal details to the browser
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        exit(json_encode([
            'success' => false,
            'error'   => 'Database unavailable. Please try again later.',
        ]));
    }
})();

/** @var PDO $pdo — available in every file that require_once's db.php */
$pdo = $GLOBALS['pdo'];