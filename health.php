<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  health.php  (project root)
//  Railway health check + UptimeRobot ping endpoint.
//
//  Returns 200 JSON on success, 503 JSON on DB failure.
//  No auth required — Railway hits this before any session exists.
//  Leaks no sensitive information.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/config/constants.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$start  = hrtime(true);
$checks = [];
$ok     = true;

// ── 1. Database ping ──────────────────────────────────────────
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST, DB_PORT, DB_NAME
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_TIMEOUT            => 3,
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->query('SELECT 1');
    $checks['database'] = 'ok';
} catch (Throwable $e) {
    $checks['database'] = 'error';
    $ok = false;
    error_log('[health.php] DB check failed: ' . $e->getMessage());
}

// ── 2. Reports directory writable ─────────────────────────────
$reportsDir = __DIR__ . '/reports';
$checks['reports_dir'] = (is_dir($reportsDir) && is_writable($reportsDir))
    ? 'ok'
    : 'not_writable';   // non-fatal — degrades gracefully

// ── 3. PHP version ────────────────────────────────────────────
$checks['php'] = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

// ── 4. Response time ──────────────────────────────────────────
$ms = round((hrtime(true) - $start) / 1_000_000, 2);

// ── Response ──────────────────────────────────────────────────
http_response_code($ok ? 200 : 503);

echo json_encode([
    'status'      => $ok ? 'ok' : 'degraded',
    'app'         => APP_NAME,
    'org'         => APP_ORG,
    'checks'      => $checks,
    'response_ms' => $ms,
    'timestamp'   => date('c'),
], JSON_PRETTY_PRINT);
