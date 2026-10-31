<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/city_audit_report_detail.php?id=N  —  detailed audit report
//  Printable / save-as-PDF. Same layout as the per-road report
//  (summary, segment scores, dimension breakdown, observations,
//  critical issues, recommendations, segment detail cards), once
//  for every road in the audit. Only approved segments are scored.
//  city_leader: own city. admin: any city, read only.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/CityLeaderAssign.php';
require_once __DIR__ . '/../helpers/ProjectStatus.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../helpers/CityDetailedReport.php';
require_once __DIR__ . '/../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../repositories/AuditReportRepository.php';
require_once __DIR__ . '/../services/ScoreService.php';

$isNational = $CURRENT_USER_ROLE === 'admin';
$audit      = (new CityAuditRepository($pdo))->find((int)($_GET['id'] ?? 0));

if ($audit === null || !projectOpenableBy($audit, $CURRENT_USER_ROLE, $CURRENT_USER_ID, (int)($CURRENT_USER_CITY_ID ?? 0))) {
    header('Location: ' . ($isNational ? 'platform_dashboard.php' : 'city_dashboard.php'));
    exit;
}
if (projectIsDeleted($audit)) {
    header('Location: ' . ($isNational ? 'platform_dashboard.php?notice=project_deleted_ok' : 'city_dashboard.php?notice=project_deleted'));
    exit;
}
if ($audit['status'] === 'draft' || $audit['status'] === 'voided') {
    header('Location: city_audit.php?id=' . (int)$audit['id']);
    exit;
}

$isPublic  = false;
$viewerName = (string)$CURRENT_USER_NAME;
$viewerRole = roleLabel((string)$CURRENT_USER_ROLE);
$backUrl   = 'city_audit_report.php?id=' . (int)$audit['id'];
$backLabel = '← Back to report';
$autoPrint = false;
$nonce     = htmlspecialchars((string)($_SESSION['csp_nonce'] ?? ''), ENT_QUOTES, 'UTF-8');

require __DIR__ . '/partials/city_detailed_report.php';
