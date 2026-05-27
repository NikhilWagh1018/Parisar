<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/db.php';

// ── INPUTS ──────────────────────────────────────────────────────
// Accept road_id OR segment IDs
$road_id_raw = trim($_GET['road_id'] ?? '');
$seg_ids_raw = trim($_GET['seg_ids'] ?? '');
$road_name_param = trim($_GET['road'] ?? ''); // legacy fallback

// ── FETCH SEGMENTS + ROAD META ───────────────────────────────────
$segs      = [];
$road_meta = null;

if ($seg_ids_raw !== '') {
    // Strategy 1: explicit segment IDs
    $seg_ids = array_filter(array_map('intval', explode(',', $seg_ids_raw)));
    if (!empty($seg_ids)) {
        $ph   = implode(',', array_fill(0, count($seg_ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT s.*, r.name AS road_name, r.start_point AS road_start,
                    r.end_point AS road_end, r.total_length AS road_length,
                    r.id AS road_id
               FROM segments s
               JOIN roads r ON r.id = s.road_id
              WHERE s.id IN ($ph)
              ORDER BY s.segment_number ASC"
        );
        $stmt->execute(array_values($seg_ids));
        $segs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (empty($segs) && $road_id_raw !== '') {
    // Strategy 2: road_id parameter
    $road_id = (int)$road_id_raw;
    $stmt = $pdo->prepare(
        "SELECT s.*, r.name AS road_name, r.start_point AS road_start,
                r.end_point AS road_end, r.total_length AS road_length,
                r.id AS road_id
           FROM segments s
           JOIN roads r ON r.id = s.road_id
          WHERE s.road_id = ?
          ORDER BY s.segment_number ASC"
    );
    $stmt->execute([$road_id]);
    $segs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($segs) && $road_name_param !== '') {
    // Strategy 3: legacy road name lookup via roads table
    $stmt = $pdo->prepare(
        "SELECT s.*, r.name AS road_name, r.start_point AS road_start,
                r.end_point AS road_end, r.total_length AS road_length,
                r.id AS road_id
           FROM segments s
           JOIN roads r ON r.id = s.road_id
          WHERE r.name = ?
          ORDER BY s.segment_number ASC"
    );
    $stmt->execute([$road_name_param]);
    $segs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── ROAD META ────────────────────────────────────────────────────
if (!empty($segs)) {
    $road_name   = $segs[0]['road_name']   ?? 'Unknown Road';
    $road_start  = $segs[0]['road_start']  ?? 'N/A';
    $road_end    = end($segs)['road_end']  ?? 'N/A';
    $road_length = $segs[0]['road_length'] ?? 0;
    reset($segs);
} else {
    $road_name   = $road_name_param ?: 'No Data';
    $road_start  = $road_end = 'N/A';
    $road_length = 0;
}

$audit_date = date('d F Y');
$surveyor   = $_SESSION['user_name']  ?? 'N/A';
$s_email    = $_SESSION['user_email'] ?? '';

// ── HELPER FUNCTIONS ─────────────────────────────────────────────
function getAudit($pdo, $seg_id) {
    $s = $pdo->prepare("SELECT * FROM segment_audits WHERE segment_id=? ORDER BY id DESC LIMIT 1");
    $s->execute([$seg_id]);
    return $s->fetch(PDO::FETCH_ASSOC);
}
function getObs($pdo, $aid) {
    $s = $pdo->prepare("SELECT SUM(partial_obstructions) p, SUM(total_obstructions) t, SUM(cyclist_slowed) sl FROM obstructions WHERE audit_id=?");
    $s->execute([$aid]);
    return $s->fetch(PDO::FETCH_ASSOC);
}
function getInts($pdo, $aid) {
    $s = $pdo->prepare("SELECT * FROM intersections WHERE audit_id=?");
    $s->execute([$aid]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function calcScore($row, $obs, $ints) {
    if (!$row) return null;
    $partial = (float)($obs['p']  ?? 0);
    $totalO  = (float)($obs['t']  ?? 0);
    $slowed  = (float)($obs['sl'] ?? 0);

    // Safety
    $bufP  = ($row['buffer_zone']       === 'None')    ? 100 : 0;
    $lgt   = $row['light_after_sunset'] ?? 'No';
    $ltP   = $lgt==='Yes' ? 0 : ($lgt==='Partial' ? 40 : 100);
    $absDev= count(array_filter($ints, fn($i)=>($i['traffic_device']??'')==='Absent'));
    $trP   = $absDev===0?0:($absDev===1?50:($absDev<=3?75:100));
    $partP = $partial<5?0:($partial<=10?50:100);
    $safety= ($bufP+$ltP+$trP+$partP)/4;

    // Continuity
    $noR  = count(array_filter($ints, fn($i)=>($i['off_ramp']??'')==='No Ramp'||($i['on_ramp']??'')==='No Ramp'));
    $rP   = $noR===0?0:($noR>=5?100:($noR>=3?50:25));
    $noS  = count(array_filter($ints, fn($i)=>($i['markings']??'')==='Absent'||($i['signage']??'')==='Absent'));
    $sP   = $noS===0?0:($noS===1?50:($noS<=3?75:100));
    $obsP = $totalO<5?0:($totalO<=10?50:100);
    $cont = ($rP+$sP+$obsP)/3;

    // Comfort
    $surfP = ($row['surface_material']==='Interlock Blocks')?100:0;
    $slwP  = $slowed<5?0:($slowed<=10?50:($slowed<=20?75:100));
    $sh    = $row['shade']??'No';
    $shP   = $sh==='Yes'?0:($sh==='Partial'?50:100);
    $comf  = ($surfP+$slwP+$shP)/3;

    $pen   = ($safety*1 + $comf*1.25 + $cont*1.5) / 3.75;
    $final = round(max(0.0, min(100.0, $pen)), 1);
    return [
        'final'      => $final,
        'safety'     => round($safety, 1),
        'continuity' => round($cont, 1),
        'comfort'    => round($comf, 1),
    ];
}
function ratingInfo($s) {
    // Score: 0=best, 100=worst (matches PDF & Excel)
    if ($s<=20) return ['Good',     '#15803d','#dcfce7','🟢'];
    if ($s<=40) return ['OK',       '#f1c40f','#fef9c3','🟡'];
    if ($s<=60) return ['Poor',     '#e67e22','#fff7ed','🟠'];
    if ($s<=80) return ['Bad',      '#dc2626','#fee2e2','🔴'];
    return              ['Very Bad','#8e1010','#fde8e8','🔴'];
}
function bar($pct,$color,$w=180,$h=10) {
    $fw=max(0,min($w,round($w*$pct/100)));
    return "<svg width='{$w}' height='{$h}' style='vertical-align:middle'>"
          ."<rect width='{$w}' height='{$h}' rx='5' fill='#e8f5d0'/>"
          ."<rect width='{$fw}' height='{$h}' rx='5' fill='{$color}'/>"
          ."</svg>";
}

// ── BUILD SEGMENT DATA ────────────────────────────────────────────
$seg_data = []; $tw=0; $ws=0;
$observations=[]; $critical=[]; $recs_flags=[];

foreach ($segs as $seg) {
    $audit = getAudit($pdo, $seg['id']);
    $obs   = $audit ? getObs($pdo,$audit['id'])  : ['p'=>0,'t'=>0,'sl'=>0];
    $ints  = $audit ? getInts($pdo,$audit['id']) : [];
    $sc    = calcScore($audit, $obs, $ints);
    $len   = (float)($seg['length']??500);

    if ($sc!==null) { $ws+=$sc['final']*$len; $tw+=$len; }

    $seg_data[] = ['seg'=>$seg,'audit'=>$audit,'obs'=>$obs,'ints'=>$ints,'sc'=>$sc,'len'=>$len];

    if ($audit) {
        if ((float)($obs['t']??0)>5)              $observations[]="Segment {$seg['id']}: High obstruction count (".((int)$obs['t'])." total)";
        if (($audit['light_after_sunset']??'')=='No') $observations[]="Segment {$seg['id']}: No after-sunset lighting";
        if (($audit['cycle_track_missing']??'')=='Yes') { $observations[]="Segment {$seg['id']}: Cycle track section missing"; $critical[]="Missing cycle track in Segment {$seg['id']}"; $recs_flags['missing']=true; }
        if (($audit['buffer_zone']??'')=='None')  $observations[]="Segment {$seg['id']}: No buffer zone";
        $noRamp=count(array_filter($ints,fn($i)=>($i['off_ramp']??'')==='No Ramp'||($i['on_ramp']??'')==='No Ramp'));
        if ($noRamp>0) { $observations[]="Segment {$seg['id']}: {$noRamp} intersection(s) missing ramps"; if($noRamp>=2)$critical[]="Unsafe intersections in Segment {$seg['id']} — {$noRamp} missing ramps"; $recs_flags['ramps']=true; }
        if (($audit['people_walking']??'')=='Yes') { $observations[]="Segment {$seg['id']}: Pedestrians walking on cycle track"; }
        if ($sc && $sc['final']>60)               $critical[]="Segment {$seg['id']} scored {$sc['final']}/100 — critically poor";
        if (($audit['light_after_sunset']??'')=='No') $recs_flags['lighting']=true;
        if (($audit['buffer_zone']??'')=='None')  $recs_flags['buffer']=true;
    }
}

$road_score = $tw>0 ? round($ws/$tw,1) : 0;
[$rl,$rc,$rbg,$ri] = ratingInfo($road_score);

$recs=['Conduct regular maintenance and remove all obstructions from the cycle track.','Install signage at intersections indicating the presence of the cycle track.','Repair damaged or uneven surface sections to improve cycling comfort.'];
if ($recs_flags['missing']??false) $recs[]='Construct missing cycle track sections to restore full network continuity.';
if ($recs_flags['ramps']??false)   $recs[]='Build on/off ramps at all intersection points for smooth transitions.';
if ($recs_flags['lighting']??false) $recs[]='Install functional street lighting along all unlit segments for night safety.';
if ($recs_flags['buffer']??false)  $recs[]='Construct buffer zones (bollards/raised curbs) to separate cycle track from vehicular traffic.';
$recs[]='Enforce no-encroachment rules and remove parked vehicles from the cycle track.';

$cnt=0; $as=$ac=$acf=0;
foreach($seg_data as $d){if($d['sc']){$as+=$d['sc']['safety'];$ac+=$d['sc']['continuity'];$acf+=$d['sc']['comfort'];$cnt++;}}
if($cnt){$as=round($as/$cnt,1);$ac=round($ac/$cnt,1);$acf=round($acf/$cnt,1);}

$pending = count(array_filter($seg_data, fn($d)=>!$d['audit']));
$audited = count($seg_data)-$pending;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cycle Track Audit Report — <?= htmlspecialchars($road_name) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/report-print.css">
</head>
<body>

<div class="pb-bar">
  <p>CycleAudit Report &nbsp;|&nbsp; <?= htmlspecialchars($road_name) ?> &nbsp;|&nbsp; Score: <?= $road_score ?>/100 (<?= $rl ?>) — lower is better</p>
  <button class="pb-btn" onclick="window.print()">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Print / Save as PDF
  </button>
</div>

<div class="page">

<!-- 1. HEADER -->
<div class="hdr">
  <div>
    <div class="hdr-lbl">Parisar Cycle Track Audit Programme</div>
    <div class="hdr-title">Cycle Track Audit Report</div>
    <div class="hdr-road"><?= htmlspecialchars($road_name) ?></div>
    <div class="hdr-meta">
      <span><strong>Route:</strong> <?= htmlspecialchars($road_start) ?> → <?= htmlspecialchars($road_end) ?></span>
      <span><strong>Total Length:</strong> <?= number_format((float)$road_length) ?> m &nbsp;|&nbsp; <strong>Segments:</strong> <?= count($segs) ?> (<?= $audited ?> audited<?= $pending>0?", {$pending} pending":'' ?>)</span>
      <span><strong>Date of Audit:</strong> <?= $audit_date ?></span>
      <span><strong>Surveyor:</strong> <?= htmlspecialchars($surveyor) ?><?= $s_email?" &lt;".htmlspecialchars($s_email)."&gt;":'' ?></span>
    </div>
  </div>
  <div>
    <div class="logo-box" style="display:flex;align-items:center;justify-content:center;padding:10px 14px;">
      <img src="../assets/parisar-logo.png" alt="Parisar" style="height:26px;width:auto;filter:brightness(0) invert(1);opacity:.95;">
    </div>
    <div class="logo-meta">parisar.org<br>Pune, Maharashtra</div>
  </div>
</div>

<!-- 2. FINAL ROAD SCORE -->
<div class="sec">
  <div class="sh"><div class="sh-num">2</div><h3>Final Road Score</h3></div>
  <div class="sc-hero">
    <div class="sc-left">
      <div class="sc-icon"><?= $ri ?></div>
      <div class="sc-num"><?= $road_score ?></div>
      <div class="sc-max">out of 100</div>
      <div class="sc-badge"><?= $rl ?></div>
    </div>
    <div class="sc-right">
      <div>
        <div class="sc-rt">Score Breakdown by Dimension</div>
        <div class="dim-r"><span class="dim-l">🛡️ Safety</span><?= bar($as,'#3b82f6') ?><span class="dim-v"><?= $as ?></span></div>
        <div class="dim-r"><span class="dim-l">🔗 Continuity</span><?= bar($ac,'#86B93B') ?><span class="dim-v"><?= $ac ?></span></div>
        <div class="dim-r"><span class="dim-l">🌿 Comfort</span><?= bar($acf,'#f59e0b') ?><span class="dim-v"><?= $acf ?></span></div>
      </div>
      <div class="sc-sum">
        <?php
        if($road_score<=20) echo "This road's cycle track is in <strong>Good</strong> condition overall. Minor targeted improvements can maintain quality.";
        elseif($road_score<=40) echo "This road is in <strong>OK</strong> condition — usable for experienced cyclists but with some issues that need attention.";
        else echo "This road is in <strong>Poor</strong> condition and requires urgent intervention to make it safe and accessible for everyday cyclists.";
        ?>
      </div>
    </div>
  </div>
</div>

<!-- 3. QUICK SUMMARY -->
<div class="sec">
  <div class="sh"><div class="sh-num">3</div><h3>Quick Summary</h3></div>
  <div class="sum-box">
    <?php
    $worst=null;$ws2=-1; $best=null;$bs2=999;
    foreach($seg_data as $d){if($d['sc']){if($d['sc']['final']>$ws2){$ws2=$d['sc']['final'];$worst=$d;}if($d['sc']['final']<$bs2){$bs2=$d['sc']['final'];$best=$d;}}}
    ?>
    <strong><?= htmlspecialchars($road_name) ?></strong> was audited across <strong><?= count($segs) ?> segments</strong>
    covering approximately <strong><?= number_format((float)$road_length) ?> metres</strong> of cycle track.
    The overall road score is <strong><?= $road_score ?>/100</strong>, rated <strong><?= $rl ?></strong>.
    <?php if($worst): ?>Weakest segment is <strong>Segment <?= $worst['seg']['id'] ?></strong> (<?= $worst['sc']['final'] ?>/100);<?php endif; ?>
    <?php if($best): ?> strongest is <strong>Segment <?= $best['seg']['id'] ?></strong> (<?= $best['sc']['final'] ?>/100).<?php endif; ?>
    <?php
    if($as>$ac&&$as>$acf) echo " <strong>Safety</strong> is the primary concern — inadequate lighting and missing buffer zones are key penalty drivers.";
    elseif($ac<$as&&$ac<$acf) echo " <strong>Continuity</strong> is the primary concern — obstructions and missing ramps break cycling flow.";
    else echo " <strong>Comfort</strong> is the primary concern — surface quality, pedestrian conflict, and lack of shade reduce usability.";
    ?>
  </div>
</div>

<!-- 4. SEGMENT-WISE SCORES -->
<div class="sec">
  <div class="sh"><div class="sh-num">4</div><h3>Segment-Wise Scores</h3></div>
  <?php if(empty($seg_data)): ?>
  <div class="no-data">No segment data found in database for this road.</div>
  <?php else: ?>
  <table class="st">
    <thead><tr><th>#</th><th>Route</th><th>Length</th><th>Safety</th><th>Continuity</th><th>Comfort</th><th>Final</th><th>Rating</th></tr></thead>
    <tbody>
    <?php foreach($seg_data as $d):
      $s=$d['seg'];$sc=$d['sc'];
      if($sc){[$rl2,$rc2,$rb2]=[ratingInfo($sc['final'])[0],ratingInfo($sc['final'])[1],ratingInfo($sc['final'])[2]];}
    ?>
    <tr>
      <td style="font-weight:700"><?= $s['id'] ?></td>
      <td style="font-size:.7rem;max-width:160px"><?= htmlspecialchars($s['start_label']??'—') ?> → <?= htmlspecialchars($s['end_label']??'—') ?></td>
      <td><?= number_format($d['len']) ?>m</td>
      <?php if($sc): ?>
      <td><?= bar($sc['safety'],'#3b82f6',50,8) ?> <span style="font-size:.7rem"><?= $sc['safety'] ?></span></td>
      <td><?= bar($sc['continuity'],'#86B93B',50,8) ?> <span style="font-size:.7rem"><?= $sc['continuity'] ?></span></td>
      <td><?= bar($sc['comfort'],'#f59e0b',50,8) ?> <span style="font-size:.7rem"><?= $sc['comfort'] ?></span></td>
      <td><span class="sc-cell" style="color:<?= $rc2 ?>"><?= $sc['final'] ?></span></td>
      <td><span class="chip" style="background:<?= $rb2 ?>;color:<?= $rc2 ?>"><?= $rl2 ?></span></td>
      <?php else: ?>
      <td colspan="5" style="color:#aaa;font-size:.7rem;font-style:italic">Pending — not yet audited</td>
      <td><span class="chip" style="background:#f3f4f6;color:#9ca3af">Pending</span></td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- 4b. DIMENSION BREAKDOWN -->
<div class="sec">
  <div class="sh"><div class="sh-num">4b</div><h3>Score Breakdown by Dimension</h3></div>
  <div class="dg">
    <div class="dc s"><div class="dc-i">🛡️</div><div class="dc-t">Safety</div><div><span class="dc-n"><?= $as ?></span><span class="dc-x"> / 100</span></div><div class="dc-its"><div class="dc-it">Buffer zone presence</div><div class="dc-it">After-sunset lighting</div><div class="dc-it">Intersection traffic devices</div><div class="dc-it">Partial obstruction density</div></div></div>
    <div class="dc c"><div class="dc-i">🔗</div><div class="dc-t">Continuity</div><div><span class="dc-n"><?= $ac ?></span><span class="dc-x"> / 100</span></div><div class="dc-its"><div class="dc-it">Missing ramps at intersections</div><div class="dc-it">Absent markings and signage</div><div class="dc-it">Total obstruction count</div><div class="dc-it">Missing track sections</div></div></div>
    <div class="dc f"><div class="dc-i">🌿</div><div class="dc-t">Comfort</div><div><span class="dc-n"><?= $acf ?></span><span class="dc-x"> / 100</span></div><div class="dc-its"><div class="dc-it">Surface material type</div><div class="dc-it">Cyclist slowed by obstructions</div><div class="dc-it">Shade availability</div><div class="dc-it">Footpath quality rating</div></div></div>
  </div>
</div>

<!-- 5. KEY OBSERVATIONS -->
<div class="sec">
  <div class="sh"><div class="sh-num">5</div><h3>Key Observations</h3></div>
  <?php if(!empty($observations)): ?>
  <div class="obs-l">
    <?php foreach(array_slice($observations,0,8) as $o): ?>
    <div class="obs-i"><span>📌</span><span><?= htmlspecialchars($o) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="obs-i" style="border-left-color:#16a34a;background:#f0fdf4"><span>✅</span><span>No major issues recorded. All segments appear to be in acceptable condition.</span></div>
  <?php endif; ?>
</div>

<!-- 6. CRITICAL ISSUES -->
<div class="sec">
  <div class="sh"><div class="sh-num">6</div><h3>Critical Issues</h3></div>
  <?php if(!empty($critical)): ?>
  <div class="obs-l">
    <?php foreach($critical as $c): ?>
    <div class="crit-i"><span>⛔</span><span><?= htmlspecialchars($c) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="obs-i" style="border-left-color:#16a34a;background:#f0fdf4"><span>✅</span><span>No critical issues identified across audited segments.</span></div>
  <?php endif; ?>
</div>

<!-- 7. RECOMMENDATIONS -->
<div class="sec">
  <div class="sh"><div class="sh-num">7</div><h3>Recommendations</h3></div>
  <div class="rec-l">
    <?php foreach($recs as $i=>$r): ?>
    <div class="rec-i"><div class="rec-n"><?= $i+1 ?></div><span><?= htmlspecialchars($r) ?></span></div>
    <?php endforeach; ?>
  </div>
</div>

<!-- SEGMENT DETAIL CARDS -->
<div class="sec pb">
  <div class="sh"><div class="sh-num">+</div><h3>Segment Detail Cards</h3></div>
  <?php foreach($seg_data as $d):
    $seg=$d['seg'];$audit=$d['audit'];$sc=$d['sc'];
    if(!$audit) continue;
    [$rl3,$rc3,$rb3]=ratingInfo($sc['final']??0);
  ?>
  <div class="sd">
    <div class="sd-h">
      <div class="sd-hl">
        <h4>Segment <?= $seg['id'] ?> &nbsp;·&nbsp; <?= htmlspecialchars($audit['start_landmark']??$seg['start_label']??'—') ?> → <?= htmlspecialchars($audit['end_landmark']??$seg['end_label']??'—') ?></h4>
        <p>GPS: <?= htmlspecialchars($audit['gps_start']??'N/A') ?> → <?= htmlspecialchars($audit['gps_end']??'N/A') ?> &nbsp;|&nbsp; Length: <?= number_format($d['len']) ?>m</p>
      </div>
      <?php if($sc): ?>
      <div class="sd-sc" style="text-align:right">
        <div class="sn" style="color:<?= $rc3 ?>"><?= $sc['final'] ?><span style="font-size:.58rem;color:var(--gray);font-weight:400">/100</span></div>
        <span class="chip" style="background:<?= $rb3 ?>;color:<?= $rc3 ?>"><?= $rl3 ?></span>
      </div>
      <?php endif; ?>
    </div>
    <?php if($sc): ?>
    <div class="sd-b">
      <div>
        <div class="sd-dims">
          <div class="sd-d"><div class="sd-dn">Safety</div><div class="sd-dv" style="color:#3b82f6"><?= $sc['safety'] ?></div></div>
          <div class="sd-d"><div class="sd-dn">Continuity</div><div class="sd-dv" style="color:#86B93B"><?= $sc['continuity'] ?></div></div>
          <div class="sd-d"><div class="sd-dn">Comfort</div><div class="sd-dv" style="color:#f59e0b"><?= $sc['comfort'] ?></div></div>
        </div>
        <div class="sd-f"><span>Surface Material</span><strong><?= htmlspecialchars($audit['surface_material']??'N/A') ?></strong></div>
        <div class="sd-f"><span>Track Missing</span><strong><?= htmlspecialchars($audit['cycle_track_missing']??'N/A') ?><?= ($audit['missing_length']??0)>0?' ('.$audit['missing_length'].'m)':'' ?></strong></div>
        <div class="sd-f"><span>Buffer Zone</span><strong><?= htmlspecialchars($audit['buffer_zone']??'N/A') ?></strong></div>
        <div class="sd-f"><span>Lighting</span><strong><?= htmlspecialchars($audit['light_after_sunset']??'N/A') ?></strong></div>
        <div class="sd-f"><span>Shade</span><strong><?= htmlspecialchars($audit['shade']??'N/A') ?></strong></div>
      </div>
      <div>
        <div class="sd-f"><span>Cyclist Can Use</span><strong><?= htmlspecialchars($audit['cyclist_use']??'N/A') ?></strong></div>
        <div class="sd-f"><span>Better Surface</span><strong><?= htmlspecialchars($audit['better_surface']??'N/A') ?></strong></div>
        <div class="sd-f"><span>Signage Count</span><strong><?= (int)($audit['signage_count']??0) ?></strong></div>
        <div class="sd-f"><span>People Walking</span><strong><?= htmlspecialchars($audit['people_walking']??'N/A') ?></strong></div>
        <div class="sd-f"><span>Intersections</span><strong><?= count($d['ints']) ?></strong></div>
        <div class="sd-f"><span>Obstructions (total)</span><strong><?= (int)($d['obs']['t']??0) ?></strong></div>
        <?php if($audit['comments']): ?>
        <div style="margin-top:5px;font-size:.68rem;color:var(--gray);font-style:italic">"<?= htmlspecialchars($audit['comments']) ?>"</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- 8. FOOTER -->
<div class="rft">
  <div>
    <div class="rf-p">Parisar — Cycle Track Audit Programme</div>
    <div class="rf-s">parisar.org &nbsp;|&nbsp; Pune, Maharashtra</div>
  </div>
  <div class="rf-r">
    Road: <?= htmlspecialchars($road_name) ?><br>
    Final Score: <?= $road_score ?>/100 (<?= $rl ?>) — lower is better<br>
    Generated: <?= date('d F Y, h:i A') ?><br>
    Surveyor: <?= htmlspecialchars($surveyor) ?>
  </div>
</div>

</div><!-- /page -->
</body>
</html>
