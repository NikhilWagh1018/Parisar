<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  api/public/audits.php
//  GET (no login) -> the published city audits for the landing page's
//  "Audit data" section: name, city, date, size and overall condition.
//  The landing page groups them by year and month and applies the filters.
//
//  Only PUBLISHED audits (approved by the Admin, report final) are listed.
//  Same protection as api/public/stats.php: per-IP request limit and a
//  short disk cache, so a burst of visitors costs one computation.
// ═══════════════════════════════════════════════════════════════

const PUBLIC_AUDITS_CACHE_TTL = 300; // seconds

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

set_exception_handler(function (Throwable $e) {
    error_log('api/public/audits.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/rate_limit.php';
require_once __DIR__ . '/../../services/ScoreService.php';
require_once __DIR__ . '/../../helpers/CityDashboard.php';
require_once __DIR__ . '/../../helpers/PublicAudits.php';
require_once __DIR__ . '/../../repositories/PublicAuditRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$rl = checkAndRecordApiRequest($pdo, getClientIp(), 'public_audits', 20, 60);
if (!$rl['allowed']) {
    header('Retry-After: ' . $rl['retry_after']);
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => $rl['message']]);
    exit;
}

$cacheFile = sys_get_temp_dir() . '/cycleaudit_public_audits.json';
if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < PUBLIC_AUDITS_CACHE_TTL) {
    $cached = file_get_contents($cacheFile);
    if (is_string($cached) && $cached !== '') {
        echo $cached;
        exit;
    }
}

$repo = new PublicAuditRepository($pdo);
$rows = $repo->published();

// Each segment is scored from its latest audit; an audit's score is the length-weighted
// average over its segments (the same rule as the City Leader's report).
$roadToAudit = $repo->roadAuditMap(array_column($rows, 'id'));
$byAudit     = [];
foreach (loadLatestSegmentScores(array_keys($roadToAudit), $pdo) as $s) {
    $byAudit[$roadToAudit[(int)$s['road_id']]][] = ['final' => $s['final'], 'length' => $s['length']];
}

$audits = [];
foreach ($rows as $row) {
    $audits[] = publicAuditShape($row, cityReportAggregate($byAudit[(int)$row['id']] ?? []));
}
$audits = publicSortAudits($audits);

$payload = json_encode(['success' => true, 'audits' => $audits, 'cities' => publicAuditCities($audits)]);

$tmp = tempnam(sys_get_temp_dir(), 'caaud');
if ($tmp !== false) {
    if (@file_put_contents($tmp, $payload) !== false) {
        @rename($tmp, $cacheFile);
    } else {
        @unlink($tmp);
    }
}
echo $payload;
