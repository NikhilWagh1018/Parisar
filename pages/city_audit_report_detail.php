<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/city_audit_report_detail.php?id=N  —  detailed audit report
//  Printable / save-as-PDF. Same layout as the per-road report
//  (summary, segment scores, dimension breakdown, observations,
//  critical issues, recommendations, segment detail cards), once
//  for every road in the audit. Only approved segments are scored.
//  city_admin: own city. national_admin: any city, read only.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../helpers/CityDetailedReport.php';
require_once __DIR__ . '/../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../repositories/AuditReportRepository.php';
require_once __DIR__ . '/../services/ScoreService.php';

$isNational = $CURRENT_USER_ROLE === 'national_admin';
$audit      = (new CityAuditRepository($pdo))->find((int)($_GET['id'] ?? 0));

if ($audit === null || (!$isNational && (int)$audit['city_id'] !== (int)($CURRENT_USER_CITY_ID ?? 0))) {
    header('Location: ' . ($isNational ? 'platform_dashboard.php' : 'city_dashboard.php'));
    exit;
}
if ($audit['status'] === 'draft' || $audit['status'] === 'voided') {
    header('Location: city_audit.php?id=' . (int)$audit['id']);
    exit;
}

$auditId = (int)$audit['id'];
$status  = (string)$audit['status'];
$reports = new AuditReportRepository($pdo);
$rows    = $reports->reportSegments($auditId);

$scoreIds = [];
foreach ($rows as $r) {
    if ($r['assignment_status'] === 'approved' && $r['latest_audit_id'] !== null) {
        $scoreIds[] = (int)$r['latest_audit_id'];
    }
}
try { $scores = calculateScoresForAuditIds($scoreIds, $pdo); } catch (Throwable $e) { $scores = []; }
try { $details = $reports->detailsForAuditIds($scoreIds); }    catch (Throwable $e) { $details = []; }

$roads = []; $scored = []; $totalSegs = 0; $approvedSegs = 0;
foreach ($rows as $r) {
    $rid = (int)$r['road_id'];
    $sc  = $r['assignment_status'] === 'approved' ? ($scores[(int)($r['latest_audit_id'] ?? 0)] ?? null) : null;
    $r['length'] = (float)$r['length'];
    $r = array_merge($r, $sc ?? []);
    $roads[$rid] ??= ['name' => (string)$r['road_name'], 'length' => 0.0, 'rows' => []];
    $roads[$rid]['length'] += $r['length'];
    $roads[$rid]['rows'][]  = $r;
    $totalSegs++;
    if ($sc !== null) { $scored[] = $r; $approvedSegs++; }
}
$overall    = cityReportAggregate($scored);
$allDone    = $totalSegs > 0 && $approvedSegs === $totalSegs;
$totalLen   = array_sum(array_column($roads, 'length'));

$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$backUrl   = 'city_audit_report.php?id=' . $auditId;
$printDate = date('d M Y');
$cityLabel = (string)($audit['city_name'] ?? '');
$logoPath  = __DIR__ . '/../assets/parisar-logo.png';
$logoB64   = is_file($logoPath) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath)) : '';
$orgName   = defined('APP_ORG') ? APP_ORG : 'Parisar';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Report — <?= $h($audit['name']) ?></title>
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/report.css">
<style nonce="<?= $nonce ?>">
/* Keep headings with their content and small blocks in one piece when printed. */
@media print {
  .section-h { break-after: avoid; page-break-after: avoid; }
  .section-h + * { break-before: avoid; page-break-before: avoid; }
  .summary-box, .critical-box, .obs-list li, .rec-list li, .segs-table tr, .dim-breakdown-grid { break-inside: avoid; }
  .segs-table thead { display: table-header-group; }
}
</style>
</head>
<body>

<?php if ($logoB64): ?><div class="watermark"><img src="<?= $logoB64 ?>" alt=""></div><?php endif; ?>

<div class="toolbar">
  <div class="toolbar-title">📄 <?= $h($audit['name']) ?> — Detailed Report</div>
  <div class="toolbar-btns">
    <a href="<?= $h($backUrl) ?>" class="tbtn tbtn-back">← Back to report</a>
    <button class="tbtn tbtn-print" type="button" id="rdPrint">🖨 Print / Save PDF</button>
  </div>
</div>

<div class="report">

  <div class="rpt-header">
    <div class="rpt-header-top">
      <div>
        <div class="rpt-org"><?= $h($orgName) ?> — Cycle Track Audit Programme<?= $cityLabel !== '' ? ', ' . $h($cityLabel) : '' ?></div>
        <div class="rpt-title"><?= $h($audit['name']) ?></div>
        <div class="rpt-sub"><?= $h(cityAuditDateLabel($audit['audit_date'] ?? null, (int)$audit['audit_year'])) ?> · <?= $h($audit['state'] ?? '') ?></div>
      </div>
      <?php if ($logoB64): ?>
      <div class="rpt-logo"><img src="<?= $logoB64 ?>" alt="Parisar"><div class="rpt-logo-text">parisar.org</div></div>
      <?php endif; ?>
    </div>
    <div class="rpt-meta">
      <div class="rpt-meta-item"><div class="k">City</div><div class="v"><?= $h($cityLabel !== '' ? $cityLabel : '—') ?></div></div>
      <div class="rpt-meta-item"><div class="k">Prepared by</div><div class="v"><?= $h($audit['created_by_name'] ?? '—') ?></div></div>
      <div class="rpt-meta-item"><div class="k">Status</div><div class="v"><?= $h(cityStatusLabel($status)) ?></div></div>
      <div class="rpt-meta-item"><div class="k">Roads</div><div class="v"><?= count($roads) ?></div></div>
      <div class="rpt-meta-item"><div class="k">Total Length</div><div class="v"><?= $totalLen > 0 ? number_format($totalLen) . ' m' : '—' ?></div></div>
      <div class="rpt-meta-item"><div class="k">Segments</div><div class="v"><?= $approvedSegs ?>/<?= $totalSegs ?> approved</div></div>
      <div class="rpt-meta-item"><div class="k">Report Date</div><div class="v"><?= $h($printDate) ?></div></div>
    </div>
  </div>

  <?php if ($overall):
    $oc = ratingColour(scoreToCondition((float)$overall['score'])); ?>
  <div class="score-hero">
    <div class="final-score-wrap">
      <div class="score-badge" style="border-color:<?= $oc ?>;background:<?= $oc ?>18;">
        <div class="num" style="color:<?= $oc ?>"><?= $h($overall['score']) ?></div>
        <div class="denom">/ 100</div>
      </div>
      <div class="score-rating" style="color:<?= $oc ?>"><?= $h(scoreToCondition((float)$overall['score'])) ?></div>
      <div class="score-label">Overall Audit Score</div>
    </div>
    <div class="dim-scores">
      <?php foreach ([['🛡', 'Safety', $overall['safety'], 'var(--safety-c)'], ['🔗', 'Continuity', $overall['continuity'], 'var(--cont-c)'], ['🌿', 'Comfort', $overall['comfort'], 'var(--comf-c)']] as [$ic, $dl, $dv, $dc]): ?>
      <div class="dim-card">
        <div class="dim-icon"><?= $ic ?></div>
        <div class="dim-val" style="color:<?= $dc ?>"><?= $h($dv) ?></div>
        <div class="dim-lbl"><?= $dl ?></div>
        <div class="dim-bar"><div class="dim-fill" style="width:<?= max(0, min(100, (float)$dv)) ?>%;background:<?= $dc ?>"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="rpt-body">
    <p style="font-size:.78rem;color:#5e6b54;margin-bottom:14px">Scores run from 0 to 100. <strong>Lower is better.</strong> Length-weighted across approved segments only.</p>

    <?php if (!$allDone): ?>
    <div class="incomplete-notice">
      <span>⏳</span>
      <span><b>Provisional report.</b> <?= $totalSegs - $approvedSegs ?> segment(s) are not approved yet and are left out of the scores below.</span>
    </div>
    <?php endif; ?>

    <?php if (!$roads): ?>
      <p>This audit has no roads yet.</p>
    <?php endif; ?>

    <?php $roadNo = 0; foreach ($roads as $road):
        $roadNo++;
        $an  = cityRoadAnalysis($road['rows'], $details);
        $agg = cityReportAggregate(array_values(array_filter($road['rows'], static fn($x) => isset($x['final']))));
        $segCount = count($road['rows']);
        $secN = 0;
        $sec  = static function () use (&$secN): string { return (string)(++$secN); };
        $roadCond = $agg ? scoreToCondition((float)$agg['score']) : null;
        $rcol     = $roadCond ? ratingColour($roadCond) : '#95a5a6';
    ?>
    <div<?= $roadNo > 1 ? ' style="page-break-before:always"' : '' ?>>
      <div class="section-h" style="font-size:1.05rem;margin-top:<?= $roadNo > 1 ? '0' : '8px' ?>">
        <span class="sec-num"><?= $roadNo ?></span> <?= $h($road['name']) ?>
        <span style="margin-left:auto;color:<?= $rcol ?>;font-weight:800"><?= $agg ? $h($agg['score']) . ' / 100 · ' . $h($roadCond) : 'Not scored yet' ?></span>
      </div>
      <p style="font-size:.78rem;color:#5e6b54;margin:-4px 0 12px"><?= number_format($road['length']) ?> m · <?= $segCount ?> segment<?= $segCount !== 1 ? 's' : '' ?> · <?= $an['scored'] ?> approved</p>

      <!-- Quick Summary -->
      <div class="section-h"><span class="sec-num"><?= $sec() ?></span> Quick Summary</div>
      <div class="summary-box">
        <strong><?= $h($road['name']) ?></strong> has
        <strong><?= $segCount ?> segment<?= $segCount !== 1 ? 's' : '' ?></strong>
        covering approximately <strong><?= number_format($road['length']) ?> metres</strong>.
        <?php if ($agg): ?>
          The road score is <?= $h($agg['score']) ?>/100, rated <strong><?= $h($roadCond) ?></strong>, from <?= $an['scored'] ?> approved segment<?= $an['scored'] !== 1 ? 's' : '' ?> (<?= number_format($an['audited_len']) ?> m).
          <?php if ($an['worst'] && $an['best'] && $an['scored'] > 1): ?>
            Weakest is Segment <?= (int)$an['worst']['segment_number'] ?> (<?= $h($an['worst']['final']) ?>/100); strongest is Segment <?= (int)$an['best']['segment_number'] ?> (<?= $h($an['best']['final']) ?>/100).
          <?php endif; ?>
          <?php if ($an['weak']): ?>
            <strong><?= $h(ucfirst((string)$an['weak'])) ?></strong> is the main concern —
            <?= $an['weak'] === 'comfort' ? 'surface quality, pedestrian conflict, and lack of shade reduce usability.'
               : ($an['weak'] === 'safety' ? 'buffer zones, lighting, and obstruction density need attention.'
               : 'missing sections, ramps, and signage gaps disrupt route continuity.') ?>
          <?php endif; ?>
        <?php else: ?>
          <em>No segment on this road is approved yet, so there is no score.</em>
        <?php endif; ?>
      </div>

      <!-- Segment-wise Scores -->
      <div class="section-h"><span class="sec-num"><?= $sec() ?></span> Segment-wise Scores</div>
      <table class="segs-table">
        <thead><tr><th>#</th><th>Route</th><th>Length</th><th>Surveyor</th><th>Safety</th><th>Continuity</th><th>Comfort</th><th>Final</th><th>Rating</th></tr></thead>
        <tbody>
        <?php $cum = 0.0; foreach ($road['rows'] as $sr):
            $start = $cum; $cum += (float)$sr['length'];
            $isScored = isset($sr['final']);
            $col = $isScored ? ratingColour((string)$sr['rating']) : '#aaa'; ?>
          <tr>
            <td><strong><?= (int)$sr['segment_number'] ?></strong></td>
            <td class="segs-route"><?= number_format($start) ?>m → <?= number_format($cum) ?>m</td>
            <td><?= number_format((float)$sr['length']) ?> m</td>
            <td><?= $h($sr['surveyor_name'] ?? '—') ?></td>
            <?php if ($isScored): ?>
              <td><?= $h($sr['safety_score']) ?></td><td><?= $h($sr['continuity_score']) ?></td><td><?= $h($sr['comfort_score']) ?></td>
              <td><span class="score-pill" style="background:<?= $col ?>22;color:<?= $col ?>"><?= $h($sr['final']) ?></span></td>
              <td style="color:<?= $col ?>;font-weight:700"><?= $h($sr['rating']) ?></td>
            <?php else: ?>
              <td colspan="5"><span class="pill-pending">⏳ <?= $h(['submitted' => 'Waiting for review', 'needs_revisit' => 'Sent back', 'assigned' => 'With surveyor'][$sr['assignment_status'] ?? ''] ?? 'Not assigned') ?></span></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($an['scored'] > 0): ?>
      <!-- Score Breakdown by Dimension -->
      <div class="section-h"><span class="sec-num"><?= $sec() ?></span> Score Breakdown by Dimension</div>
      <div class="dim-breakdown-grid">
        <?php foreach ([
          ['🛡', 'Safety',     $an['avg']['safety'],     'var(--safety-c)', ['Buffer zone presence', 'After-sunset lighting', 'Intersection traffic devices', 'Partial obstruction density']],
          ['🔗', 'Continuity', $an['avg']['continuity'], 'var(--cont-c)',   ['Missing ramps at intersections', 'Absent markings and signage', 'Total obstruction count', 'Missing track sections']],
          ['🌿', 'Comfort',    $an['avg']['comfort'],    'var(--comf-c)',   ['Surface material type', 'Cyclist slowed by obstructions', 'Shade availability', 'Footpath quality rating']],
        ] as [$ic, $nm, $val, $col, $factors]): ?>
        <div class="dim-breakdown-card">
          <div class="head"><span><?= $ic ?></span><span><?= $nm ?></span></div>
          <div class="dim-big-score" style="color:<?= $col ?>"><?= $h($val) ?> / 100</div>
          <div class="dim-breakdown-bar"><div class="dim-breakdown-fill" style="width:<?= max(0, min(100, (float)$val)) ?>%;background:<?= $col ?>"></div></div>
          <div class="dim-factors"><?php foreach ($factors as $f): ?><span class="factor-bullet"><?= $h($f) ?></span><?php endforeach; ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($an['observations']): ?>
      <div class="section-h"><span class="sec-num"><?= $sec() ?></span> Key Observations</div>
      <ul class="obs-list">
        <?php foreach ($an['observations'] as $o): ?>
        <li class="<?= $h($o['type']) ?>"><span class="obs-icon"><?= $o['type'] === 'bad' ? '⚠️' : '📌' ?></span><span><?= $h($o['text']) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <?php if ($an['critical']): ?>
      <div class="section-h"><span class="sec-num"><?= $sec() ?></span> Critical Issues</div>
      <div class="critical-box">
        <?php foreach ($an['critical'] as $ci): ?>
        <div class="critical-item"><div class="critical-dot"></div><span><?= $h($ci) ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($an['recommend']): ?>
      <div class="section-h"><span class="sec-num"><?= $sec() ?></span> Recommendations</div>
      <ol class="rec-list"><?php foreach ($an['recommend'] as $rec): ?><li><?= $h($rec) ?></li><?php endforeach; ?></ol>
      <?php endif; ?>

      <?php
        $cards = array_values(array_filter($road['rows'], static fn($x) => isset($x['final']) && isset($details[(int)($x['latest_audit_id'] ?? 0)])));
      ?>
      <?php if ($cards): ?>
      <div class="section-h"><span class="sec-num"><?= $sec() ?></span> Segment Detail Cards</div>
      <div class="seg-cards">
        <?php foreach ($cards as $sr):
            $det = $details[(int)$sr['latest_audit_id']]; $a = $det['audit'];
            $col = ratingColour((string)$sr['rating']);
            $rowsOut = [
              'Surface Material' => $a['surface_material'] ?? null, 'Track Missing' => $a['cycle_track_missing'] ?? null,
              'Buffer Zone' => $a['buffer_zone'] ?? null, 'Lighting' => $a['light_after_sunset'] ?? null,
              'Shade' => $a['shade'] ?? null, 'Cyclist Can Use' => $a['cyclist_use'] ?? null,
              'Better Surface' => $a['better_surface'] ?? null, 'Signage Count' => $a['seg_signage_count'] ?? 0,
              'People Walking' => $a['people_walking'] ?? null, 'Intersections' => $det['intersections'],
              'Obstructions (total)' => $det['obs_total'],
            ]; ?>
        <div class="seg-card">
          <div class="seg-card-header" style="background:<?= $col ?>12;border-bottom:2px solid <?= $col ?>33;">
            <div>
              <div class="seg-card-title">Segment <?= (int)$sr['segment_number'] ?></div>
              <div class="seg-card-sub">Length: <?= number_format((float)$sr['length']) ?>m · Surveyor: <?= $h($sr['surveyor_name'] ?? '—') ?></div>
            </div>
            <div class="seg-card-score">
              <div class="val" style="color:<?= $col ?>"><?= $h($sr['final']) ?>/100</div>
              <div class="label" style="color:<?= $col ?>"><?= $h($sr['rating']) ?></div>
            </div>
          </div>
          <div class="seg-card-dims">
            <?php foreach ([['Safety', $sr['safety_score'], 'var(--safety-c)'], ['Continuity', $sr['continuity_score'], 'var(--cont-c)'], ['Comfort', $sr['comfort_score'], 'var(--comf-c)']] as [$dn, $dv, $dc]): ?>
            <div class="seg-dim-item"><div class="seg-dim-val" style="color:<?= $dc ?>"><?= $h($dv) ?></div><div class="seg-dim-lbl"><?= $dn ?></div></div>
            <?php endforeach; ?>
          </div>
          <div class="seg-card-details">
            <?php foreach ($rowsOut as $k => $v): [$disp, $cls] = cityDetailValue($v); ?>
            <div class="detail-row"><span class="detail-key"><?= $h($k) ?></span><span class="detail-val <?= $cls ?>"><?= $h($disp) ?></span></div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="rpt-footer">
    <div class="footer-left">
      <?php if ($logoB64): ?><img src="<?= $logoB64 ?>" alt="Parisar"><?php endif; ?>
      <div class="footer-meta">
        <div><strong><?= $h($orgName) ?> — Cycle Track Audit Programme</strong></div>
        <div>parisar.org<?= $cityLabel !== '' ? ' | ' . $h($cityLabel) : '' ?></div>
        <div>Generated by CycleAudit · <?= $h($orgName) ?></div>
      </div>
    </div>
    <div class="footer-right">
      <div>Audit: <strong><?= $h($audit['name']) ?></strong></div>
      <?php if ($overall): ?><div>Overall Score: <strong><?= $h($overall['score']) ?>/100</strong> (<?= $h(scoreToCondition((float)$overall['score'])) ?>)</div><?php endif; ?>
      <div style="margin-top:4px">Printed <?= $h($printDate) ?></div>
    </div>
  </div>
</div>

<script nonce="<?= $nonce ?>">
document.getElementById('rdPrint').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
