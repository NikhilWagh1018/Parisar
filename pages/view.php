<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/view.php
//  Single-segment audit detail view.
//  All scoring via ScoreService — zero inline scoring here.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ScoreService.php';

$segmentId = isset($_GET['segment_id']) ? (int)$_GET['segment_id'] : 0;
if ($segmentId <= 0) { header('Location: dashboard.php'); exit; }
$roadId = isset($_GET['road_id']) ? (int)$_GET['road_id'] : 0;
$backUrl = $roadId > 0 ? "segment.php?road_id={$roadId}" : 'dashboard.php';

$stmt = $pdo->prepare(
    'SELECT sa.*, s.length AS seg_length, s.segment_number,
            s.start_label, s.end_label, r.name AS road_name
     FROM   segment_audits sa
     JOIN   segments s ON s.id = sa.segment_id
     JOIN   roads    r ON r.id = s.road_id
     WHERE  sa.segment_id = ?
     ORDER  BY sa.id DESC LIMIT 1'
);
$stmt->execute([$segmentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row === false) { header('Location: dashboard.php'); exit; }

$auditId = (int)$row['id'];
$score   = calculateSegmentScore($auditId, $pdo);
$colour  = ratingColour($score['rating']);

$stmtObs = $pdo->prepare(
    'SELECT obstruction_category, obstruction_type,
            cyclist_slowed, partial_obstructions, total_obstructions
     FROM   obstructions WHERE audit_id = ?
     ORDER  BY obstruction_category, obstruction_type'
);
$stmtObs->execute([$auditId]);
$obstructions = $stmtObs->fetchAll(PDO::FETCH_ASSOC);

$stmtInt = $pdo->prepare(
    'SELECT * FROM intersections WHERE audit_id = ? ORDER BY intersection_num'
);
$stmtInt->execute([$auditId]);
$intersections = $stmtInt->fetchAll(PDO::FETCH_ASSOC);

$surfaceIssues  = json_decode((string)($row['surface_issues']  ?? '[]'), true) ?: [];
$overheadIssues = json_decode((string)($row['overhead_issues'] ?? '[]'), true) ?: [];
$footpathRating = json_decode((string)($row['footpath_rating'] ?? '[]'), true) ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Segment <?= (int)$row['segment_number'] ?> — <?= htmlspecialchars($row['road_name']) ?></title>
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/view.css">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/view-inline.css">
</head>
<body>
<div class="container">

  <a href="<?= htmlspecialchars($backUrl) ?>" class="back-btn">← Back</a>

  <div class="card" style="margin-top:16px">
    <div class="title">
      <?= htmlspecialchars($row['road_name']) ?> — Segment <?= (int)$row['segment_number'] ?>
    </div>
    <p style="font-size:.84rem;color:#6b7566;margin-top:4px">
      <?= htmlspecialchars($row['start_label'] ?? '') ?> →
      <?= htmlspecialchars($row['end_label']   ?? '') ?>
      &nbsp;|&nbsp; <?= (float)$row['seg_length'] ?> m
    </p>
    <div class="status completed">✓ Audited</div>
  </div>

  <div class="final-box">
    <div style="font-size:.7rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#9aaa88;margin-bottom:8px">Final Score</div>
    <div class="final-num" style="color:<?= $colour ?>"><?= $score['final'] ?></div>
    <div style="font-size:.85rem;color:#9aaa88">out of 100</div>
    <div style="font-size:.9rem;font-weight:700;margin-top:6px;color:<?= $colour ?>"><?= $score['rating'] ?></div>
  </div>

  <div class="score-grid">
    <?php foreach ([
      ['Safety',     $score['safety_score'],     '#3498db'],
      ['Continuity', $score['continuity_score'], '#9b59b6'],
      ['Comfort',    $score['comfort_score'],    '#e67e22'],
    ] as [$lbl, $val, $col]): ?>
    <div class="score-box">
      <div class="val" style="color:<?= $col ?>"><?= $val ?></div>
      <div class="lbl"><?= $lbl ?></div>
      <div class="prog-bar-wrap">
        <div class="prog-bar-fill" style="width:<?= $val ?>%;background:<?= $col ?>"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="section-title">Audit Details</div>
    <?php
    $details = [
      'Start Landmark'      => $row['start_landmark'],
      'End Landmark'        => $row['end_landmark'],
      'GPS Start'           => $row['gps_start'],
      'GPS End'             => $row['gps_end'],
      'Cycle Track Missing' => $row['cycle_track_missing'],
      'Missing Length'      => $row['missing_length'] ? $row['missing_length'].' m' : null,
      'Cyclist Can Use'     => $row['cyclist_use'],
      'Better Surface'      => $row['better_surface'],
      'Surface Material'    => $row['surface_material'],
      'People Walking'      => $row['people_walking'],
      'Signage Count'       => $row['signage_count'],
      'Shade'               => $row['shade'],
      'Light After Sunset'  => $row['light_after_sunset'],
      'Track Geometry'      => $row['track_geometry'],
      'Buffer Zone'         => $row['buffer_zone'],
      'Segment Width'       => $row['segment_width']  ? $row['segment_width'].' m'  : null,
      'Segment Length'      => $row['segment_length'] ? $row['segment_length'].' m' : null,
    ];
    foreach ($details as $k => $v):
      if ($v === null || $v === '') continue; ?>
    <div class="detail-row">
      <span class="detail-key"><?= $k ?></span>
      <span style="font-weight:500"><?= htmlspecialchars((string)$v) ?></span>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($surfaceIssues)): ?>
    <div style="margin-top:10px">
      <div class="section-title">Surface Issues</div>
      <?php foreach ($surfaceIssues as $s): ?>
        <span class="tag"><?= htmlspecialchars($s) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($overheadIssues)): ?>
    <div style="margin-top:10px">
      <div class="section-title">Overhead Issues</div>
      <?php foreach ($overheadIssues as $o): ?>
        <span class="tag"><?= htmlspecialchars($o) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($footpathRating)): ?>
    <div style="margin-top:10px">
      <div class="section-title">Footpath Rating (<?= (int)$row['footpath_score'] ?>%)</div>
      <?php foreach ($footpathRating as $f): ?>
        <span class="tag">✓ <?= htmlspecialchars($f) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($row['comments'])): ?>
    <div style="margin-top:14px">
      <div class="section-title">Surveyor Comments</div>
      <p style="font-size:.84rem;color:#444;line-height:1.65">
        <?= nl2br(htmlspecialchars($row['comments'])) ?>
      </p>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($obstructions)): ?>
  <div class="card">
    <div class="section-title">Obstructions</div>
    <table class="obs-table">
      <thead>
        <tr><th>Category</th><th>Type</th><th>Slowed</th><th>Partial</th><th>Total</th></tr>
      </thead>
      <tbody>
        <?php foreach ($obstructions as $obs): ?>
        <tr>
          <td><?= ucfirst(htmlspecialchars($obs['obstruction_category'])) ?></td>
          <td><?= htmlspecialchars($obs['obstruction_type']) ?></td>
          <td><?= (int)$obs['cyclist_slowed'] ?></td>
          <td><?= (int)$obs['partial_obstructions'] ?></td>
          <td><?= (int)$obs['total_obstructions'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!empty($intersections)): ?>
  <div class="card">
    <div class="section-title">Intersections</div>
    <?php foreach ($intersections as $i): ?>
    <div class="int-card">
      <h4>Intersection <?= (int)$i['intersection_num'] ?>
        <?= $i['landmark_name'] ? '— '.htmlspecialchars($i['landmark_name']) : '' ?>
      </h4>
      <div class="int-grid">
        <div class="int-row"><span class="int-key">GPS</span><span><?= htmlspecialchars($i['gps_coords'] ?? '—') ?></span></div>
        <div class="int-row"><span class="int-key">Off-Ramp</span><span><?= htmlspecialchars($i['off_ramp']   ?? '—') ?></span></div>
        <div class="int-row"><span class="int-key">On-Ramp</span><span><?= htmlspecialchars($i['on_ramp']    ?? '—') ?></span></div>
        <div class="int-row"><span class="int-key">Markings</span><span><?= htmlspecialchars($i['markings']   ?? '—') ?></span></div>
        <div class="int-row"><span class="int-key">Signage</span><span><?= htmlspecialchars($i['signage']    ?? '—') ?></span></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <a href="<?= htmlspecialchars($backUrl) ?>" class="back-btn">← Back</a>
</div>
</body>
</html>