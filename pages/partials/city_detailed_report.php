<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/partials/city_detailed_report.php
//  The detailed audit report (summary, segment scores, dimension
//  breakdown, observations, critical issues, recommendations and
//  segment cards, once per road). Shared by:
//    · pages/city_audit_report_detail.php  (City Leader / Admin)
//    · pages/public_audit_report.php       (anyone, published audits)
//
//  The including page has already checked access, loaded the helpers
//  and set:
//    $audit      audit row (CityAuditRepository::find)
//    $pdo        PDO connection
//    $isPublic   true for the public page: hides names of people
//                (who prepared the audit, who surveyed each segment)
//    $backUrl / $backLabel   the toolbar's back link
//    $nonce      CSP nonce, already HTML-escaped
//    $viewerName / $viewerRole   who is viewing (not set on the public page)
//    $autoPrint  true to open the print dialog on load
// ═══════════════════════════════════════════════════════════════

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
$printWhen = cityLocalTime(gmdate('Y-m-d H:i:s')) . ' IST';
$cityLabel = (string)($audit['city_name'] ?? '');
$logoPath  = __DIR__ . '/../../assets/parisar-logo.png';
$logoB64   = is_file($logoPath) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath)) : '';
$orgName   = defined('APP_ORG') ? APP_ORG : 'Parisar';
// The City Leader who runs this audit, and the organisation they belong to.
try { $leader = (new CityAuditRepository($pdo))->leaderDetails($audit); } catch (Throwable $e) { $leader = null; }
$manyRoads = count($roads) > 1;
// Each score dimension is explained once, in the summary, instead of under every road.
$dimInfo = [
    ['Safety',     'safety',     'var(--safety-c)', 'Buffer zones, after-sunset lighting, intersection traffic devices, obstruction density'],
    ['Continuity', 'continuity', 'var(--cont-c)',   'Ramps at intersections, markings and signage, obstruction count, missing track sections'],
    ['Comfort',    'comfort',    'var(--comf-c)',   'Surface material, cyclists slowed by obstructions, shade, footpath quality'],
];
$statusTone = $status === 'published' ? 'ok' : (in_array($status, ['closed', 'awaiting_approval', 'with_admin'], true) ? 'wait' : 'plain');
$initial    = static fn(string $n): string => mb_strtoupper(mb_substr(trim($n) !== '' ? trim($n) : '?', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Report — <?= $h($audit['name']) ?></title>
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/report.css?v=<?= (int)@filemtime(__DIR__ . '/../../css/report.css') ?>">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/report_detail.css?v=<?= (int)@filemtime(__DIR__ . '/../../css/report_detail.css') ?>">
</head>
<body>

<?php if ($logoB64): ?><div class="watermark"><img src="<?= $logoB64 ?>" alt=""></div><?php endif; ?>

<div class="toolbar">
  <div class="toolbar-title">Detailed report</div>
  <div class="toolbar-btns">
    <a href="<?= $h($backUrl) ?>" class="tbtn tbtn-back"><?= $h($backLabel) ?></a>
    <button class="tbtn tbtn-print" type="button" id="rdPrint"><?= $isPublic ? '⬇ Download PDF' : '🖨 Print / Save PDF' ?></button>
  </div>
</div>

<div class="rv">

  <!-- ── Header: everything that identifies the audit lives here, and only here ── -->
  <header class="rv-head">
    <div class="rv-head-top">
      <div class="rv-eyebrow"><?= $h($orgName) ?> · Cycle Track Audit Programme</div>
      <?php if ($logoB64): ?><div class="rv-logo"><img src="<?= $logoB64 ?>" alt="<?= $h($orgName) ?>"></div><?php endif; ?>
    </div>
    <h1 class="rv-title"><?= $h($audit['name']) ?></h1>
    <div class="rv-chips">
      <?php $where = trim(($cityLabel !== '' ? $cityLabel : '') . (($audit['state'] ?? '') !== '' ? ($cityLabel !== '' ? ', ' : '') . $audit['state'] : '')); ?>
      <?php if ($where !== ''): ?><span class="rv-chip">📍 <?= $h($where) ?></span><?php endif; ?>
      <span class="rv-chip">📅 <?= $h(cityAuditDateLabel($audit['audit_date'] ?? null, (int)$audit['audit_year'])) ?></span>
      <span class="rv-chip rv-status rv-status-<?= $statusTone ?>"><?= $h(cityStatusLabel($status)) ?></span>
    </div>

    <div class="rv-facts">
      <div class="rv-fact"><div class="k">Roads</div><div class="v"><?= count($roads) ?></div></div>
      <div class="rv-fact"><div class="k">Total length</div><div class="v"><?= $totalLen > 0 ? number_format($totalLen) . ' m' : '—' ?></div></div>
      <div class="rv-fact"><div class="k">Segments</div><div class="v"><?= $totalSegs ?></div></div>
      <div class="rv-fact"><div class="k">Approved</div><div class="v"><?= $approvedSegs ?> <span>of <?= $totalSegs ?></span></div></div>
    </div>

    <?php if ($leader !== null || !$isPublic): ?>
    <div class="rv-people">
      <?php if ($leader !== null): ?>
      <div class="rv-person">
        <div class="rv-avatar"><?= $h($initial((string)$leader['name'])) ?></div>
        <div>
          <div class="k">City Leader</div>
          <div class="v"><?= $h($leader['name']) ?></div>
          <?php if ($leader['organisation'] !== null): ?><div class="org"><?= $h($leader['organisation']) ?></div><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if (!$isPublic): ?>
      <div class="rv-person">
        <div class="rv-avatar rv-avatar-alt"><?= $h($initial((string)($audit['created_by_name'] ?? ''))) ?></div>
        <div>
          <div class="k">Prepared by</div>
          <div class="v"><?= $h($audit['created_by_name'] ?? '—') ?></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </header>

  <!-- ── Overall score: shown once. Per-road scores are not repeated unless there are several roads ── -->
  <?php if ($overall):
    $oc   = ratingColour(scoreToCondition((float)$overall['score']));
    $ocon = scoreToCondition((float)$overall['score']); ?>
  <section class="rv-hero">
    <div class="rv-score">
      <div class="rv-badge" style="--c:<?= $oc ?>"><span class="num"><?= $h($overall['score']) ?></span><span class="den">/ 100</span></div>
      <div class="rv-rating" style="color:<?= $oc ?>"><?= $h($ocon) ?></div>
      <div class="rv-score-cap">Overall audit score<br>lower is better</div>
    </div>
    <div class="rv-dims">
      <?php foreach ($dimInfo as [$dn, $dk, $dc, $dtext]): $dv = $overall[$dk]; ?>
      <div class="rv-dim">
        <div class="rv-dim-top"><span class="rv-dim-name"><?= $dn ?></span><span class="rv-dim-val" style="color:<?= $dc ?>"><?= $h($dv) ?></span></div>
        <div class="rv-bar"><div style="width:<?= max(0, min(100, (float)$dv)) ?>%;background:<?= $dc ?>"></div></div>
        <div class="rv-dim-note"><?= $h($dtext) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <div class="rv-body">
    <p class="rv-note">Scores run from 0 to 100 and are length-weighted across approved segments only.</p>

    <?php if (!$allDone): ?>
    <div class="rv-notice"><span>⏳</span><span><b>Provisional report.</b> <?= $totalSegs - $approvedSegs ?> segment<?= $totalSegs - $approvedSegs === 1 ? ' is' : 's are' ?> not approved yet and left out of the scores below.</span></div>
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
    <section class="rv-road"<?= $roadNo > 1 ? ' style="page-break-before:always;break-before:page"' : '' ?>>
      <div class="rv-road-head" style="--c:<?= $rcol ?>">
        <div>
          <?php if ($manyRoads): ?><div class="rv-road-no">Road <?= $roadNo ?> of <?= count($roads) ?></div><?php endif; ?>
          <h2 class="rv-road-name"><?= $h($road['name']) ?></h2>
          <div class="rv-road-sub"><?= number_format($road['length']) ?> m · <?= $segCount ?> segment<?= $segCount !== 1 ? 's' : '' ?><?= $an['scored'] !== $segCount ? ' · ' . $an['scored'] . ' approved' : '' ?></div>
        </div>
        <?php if ($manyRoads): ?>
        <div class="rv-road-score">
          <?php if ($agg): ?>
            <div class="n" style="color:<?= $rcol ?>"><?= $h($agg['score']) ?><small> / 100</small></div>
            <div class="r" style="color:<?= $rcol ?>"><?= $h($roadCond) ?></div>
            <div class="mini">Safety <b><?= $h($agg['safety']) ?></b> · Continuity <b><?= $h($agg['continuity']) ?></b> · Comfort <b><?= $h($agg['comfort']) ?></b></div>
          <?php else: ?><div class="r">Not scored yet</div><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Summary: only what the numbers around it do not already say -->
      <?php
        $worstBest = $an['worst'] && $an['best'] && $an['scored'] > 1;
        $concern   = $an['weak'] ? [
            'comfort'    => 'surface quality, pedestrian conflict and lack of shade reduce usability.',
            'safety'     => 'buffer zones, lighting and obstruction density need attention.',
            'continuity' => 'missing sections, ramps and signage gaps disrupt route continuity.',
        ][(string)$an['weak']] ?? '' : '';
      ?>
      <div class="rv-h"><span class="rv-num"><?= $sec() ?></span> Summary</div>
      <div class="rv-summary">
        <?php if (!$agg): ?>
          <em>No segment on this road is approved yet, so there is no score.</em>
        <?php elseif (!$worstBest && $concern === ''): ?>
          Nothing stands out on this road.
        <?php else: ?>
          <?php if ($worstBest): ?>
            Weakest is <strong>Segment <?= (int)$an['worst']['segment_number'] ?></strong> (<?= $h($an['worst']['final']) ?>/100); strongest is <strong>Segment <?= (int)$an['best']['segment_number'] ?></strong> (<?= $h($an['best']['final']) ?>/100).
          <?php endif; ?>
          <?php if ($concern !== ''): ?>
            <strong><?= $h(ucfirst((string)$an['weak'])) ?></strong> is the main concern — <?= $h($concern) ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Segment-wise scores -->
      <div class="rv-h"><span class="rv-num"><?= $sec() ?></span> Segment scores</div>
      <table class="rv-table">
        <thead><tr><th>#</th><th>Stretch</th><?php if (!$isPublic): ?><th>Surveyed by</th><?php endif; ?><th class="c">Safety</th><th class="c">Continuity</th><th class="c">Comfort</th><th>Score</th></tr></thead>
        <tbody>
        <?php $cum = 0.0; foreach ($road['rows'] as $sr):
            $start = $cum; $cum += (float)$sr['length'];
            $isScored = isset($sr['final']);
            $col = $isScored ? ratingColour((string)$sr['rating']) : '#95a5a6'; ?>
          <tr>
            <td data-l="Segment"><strong><?= (int)$sr['segment_number'] ?></strong></td>
            <td data-l="Stretch" class="rv-stretch"><?= number_format($start) ?> – <?= number_format($cum) ?> m</td>
            <?php if (!$isPublic): ?><td data-l="Surveyed by"><?= $h($sr['surveyor_name'] ?? '—') ?></td><?php endif; ?>
            <?php if ($isScored): ?>
              <td data-l="Safety" class="c"><?= $h($sr['safety_score']) ?></td>
              <td data-l="Continuity" class="c"><?= $h($sr['continuity_score']) ?></td>
              <td data-l="Comfort" class="c"><?= $h($sr['comfort_score']) ?></td>
              <td data-l="Score"><span class="rv-pill" style="--c:<?= $col ?>"><b><?= $h($sr['final']) ?></b> <?= $h($sr['rating']) ?></span></td>
            <?php else: ?>
              <td colspan="4"><span class="rv-pill rv-pill-wait">⏳ <?= $h(['submitted' => 'Waiting for review', 'needs_revisit' => 'Sent back', 'assigned' => 'With surveyor'][$sr['assignment_status'] ?? ''] ?? 'Not assigned') ?></span></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($an['observations']): ?>
      <div class="rv-h"><span class="rv-num"><?= $sec() ?></span> Key observations</div>
      <ul class="rv-list">
        <?php foreach ($an['observations'] as $o): ?>
        <li class="rv-obs rv-obs-<?= $h($o['type']) ?>"><span class="ic"><?= $o['type'] === 'bad' ? '⚠️' : '📌' ?></span><span><?= $h($o['text']) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <?php if ($an['critical']): ?>
      <div class="rv-h"><span class="rv-num"><?= $sec() ?></span> Critical issues</div>
      <div class="rv-critical">
        <?php foreach ($an['critical'] as $ci): ?>
        <div class="item"><i></i><span><?= $h($ci) ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($an['recommend']): ?>
      <div class="rv-h"><span class="rv-num"><?= $sec() ?></span> Recommendations</div>
      <ol class="rv-list rv-recs"><?php foreach ($an['recommend'] as $rec): ?><li><?= $h($rec) ?></li><?php endforeach; ?></ol>
      <?php endif; ?>

      <?php
        $cards = array_values(array_filter($road['rows'], static fn($x) => isset($x['final']) && isset($details[(int)($x['latest_audit_id'] ?? 0)])));
      ?>
      <?php if ($cards): ?>
      <div class="rv-h"><span class="rv-num"><?= $sec() ?></span> Segment details</div>
      <div class="rv-cards">
        <?php
        $cum = 0.0; $spans = [];
        foreach ($road['rows'] as $sr) { $spans[(int)$sr['segment_number']] = [$cum, $cum + (float)$sr['length']]; $cum += (float)$sr['length']; }
        foreach ($cards as $sr):
            $det = $details[(int)$sr['latest_audit_id']]; $a = $det['audit'];
            $col = ratingColour((string)$sr['rating']);
            [$f, $t] = $spans[(int)$sr['segment_number']] ?? [0, (float)$sr['length']];
            $rowsOut = [
              'Surface material' => $a['surface_material'] ?? null, 'Track missing' => $a['cycle_track_missing'] ?? null,
              'Buffer zone' => $a['buffer_zone'] ?? null, 'Lighting' => $a['light_after_sunset'] ?? null,
              'Shade' => $a['shade'] ?? null, 'Cyclist can use' => $a['cyclist_use'] ?? null,
              'Better surface' => $a['better_surface'] ?? null, 'Signage count' => $a['seg_signage_count'] ?? 0,
              'People walking' => $a['people_walking'] ?? null, 'Intersections' => $det['intersections'],
              'Obstructions (total)' => $det['obs_total'],
            ]; ?>
        <article class="rv-card">
          <div class="rv-card-head" style="--c:<?= $col ?>">
            <div><div class="t">Segment <?= (int)$sr['segment_number'] ?></div><div class="s"><?= number_format($f) ?> – <?= number_format($t) ?> m</div></div>
            <span class="rv-pill" style="--c:<?= $col ?>"><b><?= $h($sr['final']) ?></b> <?= $h($sr['rating']) ?></span>
          </div>
          <div class="rv-card-rows">
            <?php foreach ($rowsOut as $k => $v): [$disp, $cls] = cityDetailValue($v); ?>
            <div class="row"><span class="k"><?= $h($k) ?></span><span class="v <?= $cls ?>"><?= $h($disp) ?></span></div>
            <?php endforeach; ?>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
  </div>

  <!-- ── Footer: branding and the download line only. Audit details are in the header. ── -->
  <footer class="rv-foot">
    <div><strong><?= $h($orgName) ?></strong> — Cycle Track Audit Programme · parisar.org</div>
    <div>Generated by CycleAudit · <?= $isPublic
        ? $h($printWhen)
        : 'Downloaded by ' . $h(cityReportDownloadLine($viewerName ?? '', $viewerRole ?? '', $printWhen)) ?></div>
  </footer>
</div>

<script nonce="<?= $nonce ?>">
document.getElementById('rdPrint').addEventListener('click', function () { window.print(); });
<?php if (!empty($autoPrint)): ?>
// "Download PDF" opens the browser's print dialog; choose "Save as PDF" as the destination.
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 500); });
<?php endif; ?>
</script>
</body>
</html>
