<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/city_audit_report.php?id=N  —  the overall audit report
//  Overall score and condition, how segments spread across the five
//  conditions, a table per road with every segment, and the actions
//  to close the audit and send it to the Admin.
//  Only approved segments are scored. Before the audit is closed the
//  report is marked provisional.
//  city_admin: own city. national_admin: any city, read only.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../repositories/AuditReviewRepository.php';
require_once __DIR__ . '/../repositories/AuditReportRepository.php';
require_once __DIR__ . '/../services/ScoreService.php';

$isNational = $CURRENT_USER_ROLE === 'national_admin';
$repo       = new CityAuditRepository($pdo);
$audit      = $repo->find((int)($_GET['id'] ?? 0));

if ($audit === null || (!$isNational && (int)$audit['city_id'] !== (int)($CURRENT_USER_CITY_ID ?? 0))) {
    header('Location: ' . ($isNational ? 'platform_dashboard.php' : 'city_dashboard.php'));
    exit;
}
if ($audit['status'] === 'draft' || $audit['status'] === 'voided') {
    header('Location: city_audit.php?id=' . (int)$audit['id']);
    exit;
}

$auditId  = (int)$audit['id'];
$status   = (string)$audit['status'];
$review   = new AuditReviewRepository($pdo);
$rv       = $review->counts($auditId);
$isFinal  = in_array($status, ['finalised', 'awaiting_approval', 'published'], true);
$canClose = !$isNational && auditReviewCanClose($status, $rv);
$canSend  = !$isNational && $status === 'finalised';

// ── one row per segment, scored when approved ─────────────────
$rows = (new AuditReportRepository($pdo))->reportSegments($auditId);
$scoreIds = [];
foreach ($rows as $r) {
    if ($r['assignment_status'] === 'approved' && $r['latest_audit_id'] !== null) {
        $scoreIds[] = (int)$r['latest_audit_id'];
    }
}
try { $scores = calculateScoresForAuditIds($scoreIds, $pdo); }
catch (Throwable $e) { $scores = []; }

$roads = [];   // road_id => ['name', 'length', 'rows']
$scored = [];
foreach ($rows as $r) {
    $rid = (int)$r['road_id'];
    $sc  = $scores[(int)($r['latest_audit_id'] ?? 0)] ?? null;
    if ($r['assignment_status'] !== 'approved') { $sc = null; }
    $r['length'] = (float)$r['length'];
    $r = array_merge($r, $sc ?? []);
    $roads[$rid] ??= ['name' => (string)$r['road_name'], 'length' => 0.0, 'rows' => []];
    $roads[$rid]['length'] += $r['length'];
    $roads[$rid]['rows'][] = $r;
    if ($sc !== null) { $scored[] = $r; }
}
$overall = cityReportAggregate($scored);
$condCounts = cityConditionCounts($scored);
$scoredN = count($scored);

$h   = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = static fn($v): string => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$csrf      = $h($_SESSION['csrf_token'] ?? '');
$activeNav = 'home';
$auditUrl  = 'city_audit.php?id=' . $auditId;
$barClass  = static fn(float $v): string => $v <= 20 ? '' : ($v <= 60 ? 'mid' : 'bad');
$totalLen  = array_sum(array_column($roads, 'length'));
$reviewUrl = static fn(array $r): string => 'city_segment_review.php?audit_id=' . $auditId . '&segment_id=' . (int)$r['segment_id'];
$statusText = ['approved' => 'Approved', 'submitted' => 'To review', 'needs_revisit' => 'Sent back', 'assigned' => 'With surveyor'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title>Report — <?= $h($audit['name']) ?> — CycleAudit</title>
<link nonce="<?= $nonce ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/city_audit.css?v=<?= filemtime(__DIR__ . '/../css/city_audit.css') ?>">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/city_dashboard.css?v=<?= filemtime(__DIR__ . '/../css/city_dashboard.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/role_sidebar.php'; ?>
<main>
  <div class="topbar">
    <button class="sb-hamburger" id="sb-toggle" aria-label="Menu">&#9776;</button>
    <div class="topbar-left">
      <h1><?= $h($audit['name']) ?> — Report</h1>
      <p><a href="<?= $h($auditUrl) ?>">← Back to audit</a></p>
    </div>
    <span class="ca-badge <?= $h($status) ?>"><?= $h(cityStatusLabel($status)) ?></span>
  </div>

  <div class="content" id="caApp" data-csrf="<?= $csrf ?>" data-audit-id="<?= $auditId ?>">

    <?php if (!$isFinal): ?>
      <p class="rp-prov"><b>Provisional report.</b> The audit is still open. Only approved segments are scored (<?= (int)$rv['approved'] ?> of <?= (int)$rv['total'] ?> so far), and the numbers can still change.</p>
    <?php endif; ?>

    <div class="cd-actions no-print" style="margin-bottom:16px">
      <?php if ($canClose): ?><button class="ca-btn" type="button" id="rpClose">Close audit</button><?php endif; ?>
      <?php if ($canSend): ?><button class="ca-btn" type="button" id="rpSend">Send to Admin</button><?php endif; ?>
      <button class="ca-btn ghost" type="button" id="rpPrint">Print report</button>
      <?php if (!$isFinal && !$canClose): ?>
        <?php $why = auditReviewCloseBlockReason($status, $rv); ?>
        <?php if ($why !== null): ?><span class="cd-sub">Close audit unlocks when every segment is approved. <?= $h($why) ?></span><?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="rp-top">
      <div class="card">
        <div class="card-head"><h3>Overall result</h3></div>
        <?php if ($overall !== null): ?>
        <div class="rv-hero">
          <div class="rv-score cond-<?= $h(cityConditionClass($overall['condition'])) ?>">
            <b><?= $h($num($overall['score'])) ?></b><span><?= $h($overall['condition']) ?></span>
          </div>
          <div class="rv-bars">
            <?php foreach (['Safety' => 'safety', 'Continuity' => 'continuity', 'Comfort' => 'comfort'] as $label => $k): $v = (float)$overall[$k]; ?>
              <div class="rv-bar-row">
                <span><?= $h($label) ?></span>
                <div class="cd-bar"><i class="<?= $h($barClass($v)) ?>" style="width:<?= (int)round(max(0, min(100, $v))) ?>%"></i></div>
                <b><?= $h($num($v)) ?></b>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <p class="cd-sub" style="margin-top:12px">Length-weighted across <?= cityPlural($scoredN, 'scored segment', 'scored segments') ?>. Scores run from 0 to 100. Lower is better.</p>
        <?php else: ?>
          <p class="cd-empty"><b>No scores yet</b>Segments are scored once they are approved.</p>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-head"><h3>The audit at a glance</h3></div>
        <dl class="rv-dl">
          <div><dt>City</dt><dd><?= $h($audit['city_name']) ?></dd></div>
          <div><dt>Year</dt><dd><?= (int)$audit['audit_year'] ?></dd></div>
          <div><dt>Roads</dt><dd><?= count($roads) ?></dd></div>
          <div><dt>Segments</dt><dd><?= (int)$rv['approved'] ?> approved of <?= (int)$rv['total'] ?></dd></div>
          <div><dt>Total length</dt><dd><?= $h($num($totalLen / 1000)) ?> km</dd></div>
          <div><dt>Prepared by</dt><dd><?= $h($audit['created_by_name'] ?? '—') ?></dd></div>
        </dl>
        <?php if ($scoredN > 0): ?>
          <h4 class="ca-sub" style="margin-top:18px">Segments by condition</h4>
          <div class="rp-dist">
            <?php foreach ($condCounts as $c => $n): if ($n === 0) { continue; } ?>
              <i class="<?= $h(cityConditionClass($c)) ?>" style="width:<?= $h(round($n * 100 / $scoredN, 2)) ?>%" title="<?= $h($c) ?>: <?= (int)$n ?>"></i>
            <?php endforeach; ?>
          </div>
          <div class="rp-legend">
            <?php foreach ($condCounts as $c => $n): ?>
              <span class="<?= $h(cityConditionClass($c)) ?>"><?= $h($c) ?> <b><?= (int)$n ?></b></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" style="margin-top:18px">
      <div class="card-head"><h3>Roads and segments</h3></div>
      <?php if (!$roads): ?><p class="cd-empty"><b>No roads yet</b>This audit has no roads.</p><?php endif; ?>
      <?php foreach ($roads as $road):
          $agg = cityReportAggregate(array_values(array_filter($road['rows'], static fn($r) => isset($r['final'])))); ?>
      <div class="rp-road">
        <div class="rp-road-head">
          <div>
            <h4><?= $h($road['name']) ?></h4>
            <small><?= $h($num($road['length'])) ?> m · <?= cityPlural(count($road['rows']), 'segment', 'segments') ?></small>
          </div>
          <?php if ($agg !== null): ?>
            <span><b class="rp-num"><?= $h($num($agg['score'])) ?></b> <span class="rp-cond <?= $h(cityConditionClass($agg['condition'])) ?>"><?= $h($agg['condition']) ?></span></span>
          <?php else: ?><span class="cd-sub">Not scored yet</span><?php endif; ?>
        </div>
        <div class="rd-scroll"><table class="rd-table">
          <thead><tr><th>#</th><th>Length</th><th>Surveyor</th><th>Status</th><th>Score</th><th>Safety</th><th>Continuity</th><th>Comfort</th><th>Condition</th><th class="no-print"></th></tr></thead>
          <tbody>
          <?php foreach ($road['rows'] as $r): $hasScore = isset($r['final']); $st = (string)($r['assignment_status'] ?? ''); ?>
            <tr>
              <td><?= (int)$r['segment_number'] ?></td>
              <td><?= $h($num($r['length'])) ?> m</td>
              <td><?= $h($r['surveyor_name'] ?? '—') ?></td>
              <td><span class="ca-badge <?= $h($st) ?>"><?= $h($statusText[$st] ?? 'Unassigned') ?></span></td>
              <?php if ($hasScore): ?>
                <td class="rp-num"><b><?= $h($num($r['final'])) ?></b></td>
                <td class="rp-num"><?= $h($num($r['safety_score'])) ?></td>
                <td class="rp-num"><?= $h($num($r['continuity_score'])) ?></td>
                <td class="rp-num"><?= $h($num($r['comfort_score'])) ?></td>
                <td><span class="rp-cond <?= $h(cityConditionClass($r['condition'])) ?>"><?= $h($r['condition']) ?></span></td>
              <?php else: ?><td colspan="5" class="cd-sub">Not scored</td><?php endif; ?>
              <td class="no-print"><?php if ($st !== '' && $st !== 'assigned'): ?><a href="<?= $h($reviewUrl($r)) ?>">Open →</a><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
      <?php endforeach; ?>
    </div>

  </div>
</main>
<div class="ca-toast" id="caToast"></div>
<div class="sb-overlay" id="sb-overlay"></div>
<script nonce="<?= $nonce ?>">
const tog = document.getElementById('sb-toggle'), ovl = document.getElementById('sb-overlay'), aside = document.querySelector('aside');
tog.addEventListener('click', () => { aside.classList.add('open'); ovl.classList.add('show'); });
ovl.addEventListener('click', () => { aside.classList.remove('open'); ovl.classList.remove('show'); });
</script>
<script nonce="<?= $nonce ?>" src="../js/city_review.js?v=<?= filemtime(__DIR__ . '/../js/city_review.js') ?>"></script>
</body>
</html>
