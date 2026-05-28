<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/road_result.php
//  Shows per-segment scores + final weighted road score.
//  All scoring via ScoreService — zero inline scoring here.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ScoreService.php';

// ── Fetch roads belonging to this user ─────────────────────────
$stmtRoads = $pdo->prepare(
    'SELECT id, name, total_length FROM roads
     WHERE  creator_id = ?
     ORDER  BY created_at DESC'
);
$stmtRoads->execute([$CURRENT_USER_ID]);
$roads = $stmtRoads->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Road Results — CycleAudit</title>
<link rel="stylesheet" href="../css/view.css">
<link rel="stylesheet" href="../css/road-result.css">
</head>
<body>
<div class="container">

  <h1>Road Audit Results</h1>

  <?php if (empty($roads)): ?>
  <div class="empty-state">
    No roads audited yet. <a href="segment.php" style="color:#3d7a1f;font-weight:700">Start a new audit →</a>
  </div>

  <?php else: foreach ($roads as $road):
    $roadId  = (int)$road['id'];

    // Fetch all segments for this road
    $stmtSegs = $pdo->prepare(
        'SELECT s.id, s.segment_number, s.start_label, s.end_label,
                s.length, s.status,
                sa.id AS audit_id
         FROM   segments s
         LEFT JOIN segment_audits sa
               ON  sa.id = (
                     SELECT id FROM segment_audits
                     WHERE  segment_id = s.id
                     ORDER  BY id DESC LIMIT 1
                   )
         WHERE  s.road_id = ?
         ORDER  BY s.segment_number ASC'
    );
    $stmtSegs->execute([$roadId]);
    $segs = $stmtSegs->fetchAll(PDO::FETCH_ASSOC);

    $total     = count($segs);
    $completed = array_filter($segs, fn($s) => $s['status'] === 'completed');
    $allDone   = count($completed) === $total && $total > 0;
    $roadScore = $allDone ? calculateRoadScore($roadId, $pdo) : null;
  ?>

  <div class="road-section">
    <div class="road-head">
      <div>
        <div class="road-name"><?= htmlspecialchars($road['name']) ?></div>
        <div class="road-meta">
          <?= count($completed) ?>/<?= $total ?> segments audited
          <?= $road['total_length'] ? '&nbsp;|&nbsp; ' . $road['total_length'] . ' m' : '' ?>
        </div>
      </div>
      <?php if ($allDone && $roadScore): ?>
        <span class="score-pill"
              style="background:<?= ratingColour($roadScore['rating']) ?>22;
                     color:<?= ratingColour($roadScore['rating']) ?>">
          <?= $roadScore['rating'] ?>
        </span>
      <?php endif; ?>
    </div>

    <div class="card">
      <?php foreach ($segs as $seg):
        $auditId = $seg['audit_id'] ? (int)$seg['audit_id'] : null;
        $score   = $auditId ? calculateSegmentScore($auditId, $pdo) : null;
        $col     = $score   ? ratingColour($score['rating']) : '#ccc';
      ?>
      <div class="seg-row">
        <div class="seg-num-badge">SEG <?= (int)$seg['segment_number'] ?></div>
        <div>
          <div class="seg-route">
            <?= htmlspecialchars($seg['start_label'] ?? '') ?>
            →
            <?= htmlspecialchars($seg['end_label']   ?? '') ?>
          </div>
          <div class="seg-len"><?= (float)$seg['length'] ?> m</div>
        </div>

        <?php if ($score): ?>
        <div>
          <div style="font-size:.78rem;color:#9aaa88;margin-bottom:3px">
            Safety / Cont. / Comfort
          </div>
          <div style="font-size:.78rem;font-weight:600">
            <?= $score['safety_score'] ?> /
            <?= $score['continuity_score'] ?> /
            <?= $score['comfort_score'] ?>
          </div>
        </div>
        <div>
          <span class="score-pill"
                style="background:<?= $col ?>22;color:<?= $col ?>">
            <?= $score['final'] ?> / 100
          </span>
          <div class="prog-mini">
            <div class="prog-mini-fill"
                 style="width:<?= (100 - $score['final']) ?>%;background:<?= $col ?>"></div>
          </div>
        </div>
        <?php else: ?>
        <div></div>
        <div><span class="pending-badge">⏳ Pending</span></div>
        <?php endif; ?>

        <div>
          <?php if ($score): ?>
          <a href="view.php?segment_id=<?= (int)$seg['id'] ?>" class="view-link">
            View →
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (!$allDone): ?>
    <div class="warn-box">
      ⏳ <?= $total - count($completed) ?> segment(s) not yet audited.
      Complete all segments to unlock the final road score.
    </div>

    <?php elseif ($roadScore): ?>
    <div class="final-road-box">
      <div style="font-size:.7rem;font-weight:700;letter-spacing:.1em;
                  text-transform:uppercase;color:#9aaa88;margin-bottom:8px">
        Final Road Score
      </div>
      <div class="final-road-score"
           style="color:<?= ratingColour($roadScore['rating']) ?>">
        <?= $roadScore['score'] ?>
      </div>
      <div style="font-size:.85rem;color:#9aaa88">out of 100</div>
      <div class="final-road-rating"
           style="color:<?= ratingColour($roadScore['rating']) ?>">
        <?= $roadScore['rating'] ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /.road-section -->

  <?php endforeach; endif; ?>

  <a href="dashboard.php" class="back-btn">← Back to Dashboard</a>

</div>
</body>
</html>