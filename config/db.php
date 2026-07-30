<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/db.php
//  PDO connection — singleton pattern.
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
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        PDO::ATTR_TIMEOUT             => 5,
    ];

    try {
        $GLOBALS['pdo'] = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Log full details server-side, never expose to browser
        error_log(sprintf(
            'DB connection failed — host=%s port=%d db=%s user=%s — %s',
            DB_HOST, DB_PORT, DB_NAME, DB_USER, $e->getMessage()
        ));
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(503);
        }
        echo json_encode([
            'success' => false,
            'error'   => 'Database unavailable. Please try again later.',
        ]);
        exit;
    }
})();

/** @var PDO $pdo — available in every file that require_once's db.php */
$pdo = $GLOBALS['pdo'];
