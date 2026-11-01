<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/public/past_audit_report.php?id=N[&download=1]
//  GET (no login) -> the PDF report of a past audit shown on the landing page.
//  Deleted past audits and ones without a report answer 404, the same as an unknown id.
//  Same per-IP request limit as the other public endpoints.
// ═══════════════════════════════════════════════════════════════

set_exception_handler(function (Throwable $e) {
    error_log('api/public/past_audit_report.php error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server error.';
    exit;
});

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/rate_limit.php';
require_once __DIR__ . '/../../repositories/PastAuditRepository.php';

function pastReportPlain(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    pastReportPlain(405, 'Method not allowed.');
}
$rl = checkAndRecordApiRequest($pdo, getClientIp(), 'public_report', 30, 60);
if (!$rl['allowed']) {
    header('Retry-After: ' . $rl['retry_after']);
    pastReportPlain(429, 'Too many requests. Please try again in a minute.');
}

$file = (new PastAuditRepository($pdo))->report((int)($_GET['id'] ?? 0));
if ($file === null) {
    pastReportPlain(404, 'This report is not available.');
}
[$name, $bytes] = $file;

$disposition = (($_GET['download'] ?? '') === '1') ? 'attachment' : 'inline';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . strlen($bytes));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
echo $bytes;
