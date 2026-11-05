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
// Vector logo first: it stays sharp at any size and, unlike the transparent PNG, prints with no box behind it.
$logoSvg   = __DIR__ . '/../../assets/parisar-logo.svg';
$logoPng   = __DIR__ . '/../../assets/parisar-logo.png';
$logoB64   = is_file($logoSvg) ? 'data:image/svg+xml;base64,' . base64_encode((string)file_get_contents($logoSvg))
           : (is_file($logoPng) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPng)) : '');
$orgName   = defined('APP_ORG') ? APP_ORG : 'Parisar';
// The City Leader who runs this audit, and the organisation they belong to.
try { $leader = (new CityAuditRepository($pdo))->leaderDetails($audit); } catch (Throwable $e) { $leader = null; }
// ── Small chart helpers (inline SVG/CSS so they print and work without scripts) ──
$pct   = static fn($v): float => max(0.0, min(100.0, (float)$v));
$zoneCol = ['#27ae60', '#f1c40f', '#e67e22', '#e74c3c', '#8e1010']; // Good, OK, Poor, Bad, Very Bad (every 20 points)
// Ring gauge: the arc is filled in proportion to the 0-100 score.
$ring = static function ($v, string $colour, int $size, int $stroke) use ($pct): string {
    $r = ($size - $stroke) / 2; $c = 2 * M_PI * $r; $f = $c * $pct($v) / 100;
    return '<svg class="rv-ring" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" aria-hidden="true">'
        . '<circle cx="' . $size / 2 . '" cy="' . $size / 2 . '" r="' . $r . '" fill="none" stroke="#e3edd3" stroke-width="' . $stroke . '"/>'
        . '<circle cx="' . $size / 2 . '" cy="' . $size / 2 . '" r="' . $r . '" fill="none" stroke="' . $colour . '" stroke-width="' . $stroke . '" stroke-linecap="round"'
        . ' stroke-dasharray="' . round($f, 2) . ' ' . round($c, 2) . '" transform="rotate(-90 ' . $size / 2 . ' ' . $size / 2 . ')"/></svg>';
};
// Condition scale: five coloured zones (Good to Very Bad) with a marker at the score.
$zones = static function ($v) use ($pct): string {
    return '<div class="rv-zones"><span></span><span></span><span></span><span></span><span></span><i style="left:' . round($pct($v), 2) . '%"></i></div>';
};
$svg = static fn(string $d): string => '<svg class="ic" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$icon = [
    'pin'   => $svg('<path d="M12 21s7-5.7 7-11a7 7 0 1 0-14 0c0 5.3 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>'),
    'cal'   => $svg('<rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>'),
    'road'  => $svg('<path d="M8 3 4 21M16 3l4 18M12 4v3M12 11v3M12 18v3"/>'),
    'ruler' => $svg('<rect x="2.5" y="8" width="19" height="8" rx="1.5"/><path d="M7 8v3M11 8v4M15 8v3M19 8v4"/>'),
    'seg'   => $svg('<path d="M3 12h4M10 12h4M17 12h4"/><circle cx="8.5" cy="12" r="0.6"/><circle cx="15.5" cy="12" r="0.6"/>'),
    'check' => $svg('<circle cx="12" cy="12" r="9"/><path d="m8 12.3 2.8 2.8L16.3 9.5"/>'),
];
$printDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('d M Y');
// The three score dimensions and what each one measures (explained in each road's breakdown).
$dimInfo = [
    ['🛡', 'Safety',     'safety',     'var(--safety-c)', ['Buffer zone presence', 'After-sunset lighting', 'Intersection traffic devices', 'Partial obstruction density']],
    ['🔗', 'Continuity', 'continuity', 'var(--cont-c)',   ['Missing ramps at intersections', 'Absent markings and signage', 'Total obstruction count', 'Missing track sections']],
    ['🌿', 'Comfort',    'comfort',    'var(--comf-c)',   ['Surface material type', 'Cyclist slowed by obstructions', 'Shade availability', 'Footpath quality rating']],
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

<table class="rv-sheet" role="presentation"><thead><tr><td><div class="rv-sp"></div></td></tr></thead><tfoot><tr><td><div class="rv-sp"></div></td></tr></tfoot><tbody><tr><td>
<div class="rv">

  <!-- ── Header: everything that identifies the audit lives here, and only here ── -->
  <header class="rv-head">
    <div class="rv-head-top">
      <?php if ($logoB64): ?><img class="rv-logo" src="<?= $logoB64 ?>" alt="<?= $h($orgName) ?>"><?php else: ?><span class="rv-eyebrow"><?= $h($orgName) ?></span><?php endif; ?>
      <div class="rv-head-meta"><span>Report generated</span><b><?= $h($printDate) ?></b></div>
    </div>
    <h1 class="rv-title"><?= $h($audit['name']) ?></h1>
    <div class="rv-chips">
      <?php $where = trim(($cityLabel !== '' ? $cityLabel : '') . (($audit['state'] ?? '') !== '' ? ($cityLabel !== '' ? ', ' : '') . $audit['state'] : '')); ?>
      <?php if ($where !== ''): ?><span class="rv-chip"><?= $icon['pin'] ?><?= $h($where) ?></span><?php endif; ?>
      <span class="rv-chip"><?= $icon['cal'] ?>Audit date · <?= $h(cityAuditDateLabel($audit['audit_date'] ?? null, (int)$audit['audit_year'])) ?></span>
      <span class="rv-chip"><?= $icon['road'] ?><?= count($roads) ?> <?= count($roads) === 1 ? 'road' : 'roads' ?><?= $totalLen > 0 ? ' · ' . number_format($totalLen) . ' m' : '' ?></span>
    </div>

    <?php if ($leader !== null || !$isPublic): ?>
    <div class="rv-people">
      <?php if ($leader !== null): ?>
      <div class="rv-person">
        <div class="rv-avatar"><?= $h($initial((string)$leader['name'])) ?></div>
        <div><div class="k">City Leader</div><div class="v"><?= $h($leader['name']) ?><?php if ($leader['organisation'] !== null): ?> <span>· <?= $h($leader['organisation']) ?></span><?php endif; ?></div></div>
      </div>
      <?php endif; ?>
      <?php if (!$isPublic): ?>
      <div class="rv-person">
        <div class="rv-avatar rv-avatar-alt"><?= $h($initial((string)($audit['created_by_name'] ?? ''))) ?></div>
        <div><div class="k">Prepared by</div><div class="v"><?= $h($audit['created_by_name'] ?? '—') ?></div></div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="rv-lane" aria-hidden="true"></div>
  </header>

  <!-- ── Overall score ── -->
  <?php if ($overall):
    $oc   = ratingColour(scoreToCondition((float)$overall['score']));
    $ocon = scoreToCondition((float)$overall['score']); ?>
  <section class="rv-hero">
    <div class="rv-score">
      <div class="rv-gauge">
        <?= $ring($overall['score'], $oc, 150, 13) ?>
        <div class="in"><span class="num" style="color:<?= $oc ?>"><?= $h($overall['score']) ?></span><span class="den">/ 100</span></div>
      </div>
      <div class="rv-rating" style="color:<?= $oc ?>"><?= $h($ocon) ?></div>
      <div class="rv-score-cap">Overall audit score<br>lower is better</div>
    </div>
    <div class="rv-dims">
      <?php foreach ($dimInfo as [$di, $dn, $dk, $dc, $dfac]): $dv = $overall[$dk]; ?>
      <div class="rv-dim">
        <div class="rv-dim-top"><span class="rv-dim-name"><span class="dot" style="background:<?= $dc ?>"></span><?= $dn ?></span><span class="rv-dim-val" style="color:<?= $dc ?>"><?= $h($dv) ?></span></div>
        <?= $zones($dv) ?>
      </div>
      <?php endforeach; ?>
      <div class="rv-legend">
        <?php foreach ([['Good', '0–20'], ['OK', '20–40'], ['Poor', '40–60'], ['Bad', '60–80'], ['Very bad', '80–100']] as $zi => [$ln, $lr]): $lc = $zoneCol[$zi]; ?>
        <span><i style="background:<?= $lc ?>"></i><?= $ln ?> <em><?= $lr ?></em></span>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <div class="rv-body">
    <p class="rv-note">Scores run from 0 to 100. <strong>Lower is better.</strong> Length-weighted across approved segments only.</p>

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
          <div class="rv-road-no">Road <?= $roadNo ?> of <?= count($roads) ?></div>
          <h2 class="rv-road-name"><?= $h($road['name']) ?></h2>
          <div class="rv-road-sub"><?= number_format($road['length']) ?> m · <?= $segCount ?> segment<?= $segCount !== 1 ? 's' : '' ?> · <?= $an['scored'] ?> approved</div>
        </div>
        <div class="rv-road-score">
          <?php if ($agg): ?>
            <div class="n" style="color:<?= $rcol ?>"><?= $h($agg['score']) ?><small> / 100</small></div>
            <div class="r" style="color:<?= $rcol ?>"><?= $h($roadCond) ?></div>
            <div class="mini">Safety <b><?= $h($agg['safety']) ?></b> · Continuity <b><?= $h($agg['continuity']) ?></b> · Comfort <b><?= $h($agg['comfort']) ?></b></div>
          <?php else: ?><div class="r">Not scored yet</div><?php endif; ?>
        </div>
      </div>

      <!-- Quick summary -->
      <div class="rv-h"><span class="rv-num"><?= $sec() ?></span> Quick summary</div>
      <div class="rv-summary">
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

      <!-- Segment-wise scores -->
      <div class="rv-h"><span class="rv-num"><?= $sec() ?></span> Segment-wise scores</div>
      <table class="rv-table">
        <thead><tr><th>#</th><th>Route</th><th>Length</th><?php if (!$isPublic): ?><th>Surveyed by</th><?php endif; ?><th class="c">Safety</th><th class="c">Continuity</th><th class="c">Comfort</th><th>Final</th><th>Rating</th></tr></thead>
        <tbody>
        <?php $cum = 0.0; foreach ($road['rows'] as $sr):
            $start = $cum; $cum += (float)$sr['length'];
            $isScored = isset($sr['final']);
            $col = $isScored ? ratingColour((string)$sr['rating']) : '#95a5a6'; ?>
          <tr>
            <td data-l="Segment"><strong><?= (int)$sr['segment_number'] ?></strong></td>
            <td data-l="Route" class="rv-stretch"><?= number_format($start) ?>m → <?= number_format($cum) ?>m</td>
            <td data-l="Length"><?= number_format((float)$sr['length']) ?> m</td>
            <?php if (!$isPublic): ?><td data-l="Surveyed by"><?= $h($sr['surveyor_name'] ?? '—') ?></td><?php endif; ?>
            <?php if ($isScored): ?>
              <td data-l="Safety" class="c"><?= $h($sr['safety_score']) ?><div class="rv-mini"><i style="width:<?= $pct($sr['safety_score']) ?>%;background:var(--safety-c)"></i></div></td>
              <td data-l="Continuity" class="c"><?= $h($sr['continuity_score']) ?><div class="rv-mini"><i style="width:<?= $pct($sr['continuity_score']) ?>%;background:var(--cont-c)"></i></div></td>
              <td data-l="Comfort" class="c"><?= $h($sr['comfort_score']) ?><div class="rv-mini"><i style="width:<?= $pct($sr['comfort_score']) ?>%;background:var(--comf-c)"></i></div></td>
              <td data-l="Final"><span class="rv-pill" style="--c:<?= $col ?>"><b><?= $h($sr['final']) ?></b></span></td>
              <td data-l="Rating" class="rv-rate" style="color:<?= $col ?>"><?= $h($sr['rating']) ?></td>
            <?php else: ?>
              <td colspan="5"><span class="rv-pill rv-pill-wait">⏳ <?= $h(['submitted' => 'Waiting for review', 'needs_revisit' => 'Sent back', 'assigned' => 'With surveyor'][$sr['assignment_status'] ?? ''] ?? 'Not assigned') ?></span></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($an['scored'] > 0): ?>
      <div class="rv-h"><span class="rv-num"><?= $sec() ?></span> Score breakdown by dimension</div>
      <div class="rv-dimcards">
        <?php foreach ($dimInfo as [$di, $dn, $dk, $dc, $dfac]): $dv = $an['avg'][$dk]; ?>
        <div class="rv-dimcard">
          <div class="head"><span><?= $di ?></span><span><?= $dn ?></span></div>
          <div class="rv-gauge sm">
            <?= $ring($dv, $dc, 96, 9) ?>
            <div class="in"><span class="num" style="color:<?= $dc ?>"><?= $h($dv) ?></span><span class="den">/ 100</span></div>
          </div>
          <?= $zones($dv) ?>
          <ul class="fac"><?php foreach ($dfac as $f): ?><li><?= $h($f) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

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
      <div class="rv-h"><span class="rv-num"><?= $sec() ?></span> Segment detail cards</div>
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
            <div>
              <div class="t">Segment <?= (int)$sr['segment_number'] ?></div>
              <div class="s">Length: <?= number_format((float)$sr['length']) ?> m · <?= number_format($f) ?>m → <?= number_format($t) ?>m</div>
              <?php if (!$isPublic): ?><div class="s by">Surveyed by: <strong><?= $h($sr['surveyor_name'] ?? 'Not assigned') ?></strong></div><?php endif; ?>
            </div>
            <div class="sc"><div class="val" style="color:<?= $col ?>"><?= $h($sr['final']) ?>/100</div><div class="lab" style="color:<?= $col ?>"><?= $h($sr['rating']) ?></div></div>
          </div>
          <div class="rv-card-dims">
            <?php foreach ([['Safety', $sr['safety_score'], 'var(--safety-c)'], ['Continuity', $sr['continuity_score'], 'var(--cont-c)'], ['Comfort', $sr['comfort_score'], 'var(--comf-c)']] as [$dn, $dv, $dc]): ?>
            <div><b style="color:<?= $dc ?>"><?= $h($dv) ?></b><span><?= $dn ?></span><div class="rv-mini"><i style="width:<?= $pct($dv) ?>%;background:<?= $dc ?>"></i></div></div>
            <?php endforeach; ?>
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
</td></tr></tbody></table>

<script nonce="<?= $nonce ?>">
document.getElementById('rdPrint').addEventListener('click', function () { window.print(); });
<?php if (!empty($autoPrint)): ?>
// "Download PDF" opens the browser's print dialog; choose "Save as PDF" as the destination.
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 500); });
<?php endif; ?>
</script>
</body>
</html>
