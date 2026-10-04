<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/city_audit.php?id=N  —  one city audit
//  City Leader: add roads from the city's road list, generate their
//  segments, remove a road while nothing is audited on it.
//  Admin can open any audit read-only.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../repositories/AuditReviewRepository.php';
require_once __DIR__ . '/../services/ScoreService.php';
require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/partials/cx_icons.php';

$isNational = $CURRENT_USER_ROLE === 'national_admin';
$repo       = new CityAuditRepository($pdo);
$audit      = $repo->find((int)($_GET['id'] ?? 0));

if ($audit === null || (!$isNational && (int)$audit['city_id'] !== (int)($CURRENT_USER_CITY_ID ?? 0))) {
    header('Location: ' . ($isNational ? 'platform_dashboard.php' : 'city_dashboard.php'));
    exit;
}

$canEdit   = !$isNational && $repo->isEditable($audit);
$available = $canEdit ? $repo->availableRoadGroups((int)$audit['city_id'], (int)$audit['id']) : [];
$roads     = $repo->roadsWithSegments((int)$audit['id']);
$canAssign = !$isNational && $repo->canAssign($audit);
$surveyors = $canAssign ? $repo->assignableSurveyors((int)$audit['city_id']) : [];
$counts    = $repo->assignmentCounts((int)$audit['id']);
$canActivate = !$isNational && $audit['status'] === 'draft';
$allAssigned = $counts['total'] > 0 && $counts['assigned'] === $counts['total'];
$review     = new AuditReviewRepository($pdo);
$rv         = $review->counts((int)$audit['id']);
$showReview = $audit['status'] !== 'draft' && $audit['status'] !== 'voided';
$canReview  = !$isNational && in_array($audit['status'], AUDIT_REVIEW_OPEN_STATUSES, true);
$adminNote  = $review->adminDecision((int)$audit['id'])['admin_note'];
$wasReturned = !$isNational && $adminNote !== null && in_array($audit['status'], AUDIT_REVIEW_OPEN_STATUSES, true);
$submitted  = $showReview ? $review->submissions((int)$audit['id']) : [];
$sentBack   = $showReview ? $review->sentBack((int)$audit['id']) : [];
$closeBlock = auditReviewCloseBlockReason((string)$audit['status'], $rv);
$isClosed   = in_array($audit['status'], ['finalised', 'awaiting_approval', 'published'], true);
$roadScores = [];
if ($isClosed) {
    foreach ($roads as $r0) {
        try { $roadScores[(int)$r0['id']] = calculateRoadScore((int)$r0['id'], $pdo); }
        catch (Throwable $e) { $roadScores[(int)$r0['id']] = null; }
    }
}
$segScore = static function (?int $auditRowId) use ($pdo): ?array {
    if ($auditRowId === null) { return null; }
    try { return calculateSegmentScore($auditRowId, $pdo); } catch (Throwable $e) { return null; }
};
$backUrl   = 'city_dashboard.php' . ($isNational ? '?city_id=' . (int)$audit['city_id'] : '');

$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$csrf      = $h($_SESSION['csrf_token'] ?? '');
$activeNav = 'home';
$num       = static fn($v): string => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$statusLabel = ucfirst(str_replace('_', ' ', (string)$audit['status']));

// ── Dashboard-style summary: stage tracker, progress mix, next step, scores ──
$stage      = cityAuditStage((string)$audit['status']);
$published  = $audit['status'] === 'published';
$segCounts  = [
    'total'         => (int)$rv['total'],
    'unassigned'    => (int)$rv['unassigned'],
    'assigned'      => (int)$rv['assigned'],
    'submitted'     => (int)$rv['submitted'],
    'needs_revisit' => (int)$rv['needs_revisit'],
    'approved'      => (int)$rv['approved'],
];
$mix        = citySegmentMix($segCounts);
$approvedPct = cityDashProgress($segCounts['approved'], $segCounts['total']);
$attnItems  = cityAuditAttention((string)$audit['status'], $segCounts, $adminNote, $isNational);
$nextItem   = $attnItems[0] ?? null;
foreach ($attnItems as $it0) {
    if ($it0['level'] === 'action') { $nextItem = $it0; break; }
}
$nextCta    = cityAuditNextAction((string)$audit['status'], $segCounts, $isNational, (int)$audit['id']);
$overall    = null;
$roadConds  = [];
if ($isClosed) {
    $wSum = 0.0; $fSum = 0.0; $sSum = 0.0; $cSum = 0.0; $mSum = 0.0;
    foreach ($roads as $r0) {
        $rs0 = $roadScores[(int)$r0['id']] ?? null;
        if (!$rs0) { continue; }
        $w0 = max(0.0, (float)$r0['total_length']);
        $wSum += $w0;
        $fSum += (float)$rs0['score'] * $w0;
        $sSum += (float)$rs0['safety_score'] * $w0;
        $cSum += (float)$rs0['continuity_score'] * $w0;
        $mSum += (float)$rs0['comfort_score'] * $w0;
        $roadConds[] = ['condition' => $rs0['condition'] ?? ''];
    }
    if ($wSum > 0.0) {
        $sc0 = round($fSum / $wSum, 2);
        $overall = [
            'score' => $sc0, 'condition' => ScoreHelpers::scoreToCondition($sc0),
            'bars'  => ['Safety' => round($sSum / $wSum, 2), 'Continuity' => round($cSum / $wSum, 2), 'Comfort' => round($mSum / $wSum, 2)],
        ];
    }
}
$barClass = static fn(float $v): string => $v < 40 ? 'bad' : ($v < 70 ? 'mid' : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title><?= $h($audit['name']) ?> — CycleAudit</title>
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
      <h1><?= $h($audit['name']) ?></h1>
      <p><a href="<?= $h($backUrl) ?>">← All audits</a></p>
    </div>
  </div>
  <div class="content cx-page" id="caApp" data-csrf="<?= $csrf ?>" data-audit-id="<?= (int)$audit['id'] ?>">

    <section class="cx-hero">
      <div class="cx-metas">
        <span class="ca-badge <?= $h($audit['status']) ?>"><?= $h($statusLabel) ?></span>
        <span class="cx-meta"><?= cxIcon('pin') ?> <b><?= $h($audit['city_name']) ?></b></span>
        <span class="cx-meta"><?= cxIcon('flag') ?> <b><?= $h($audit['state']) ?></b></span>
        <span class="cx-meta"><?= cxIcon('calendar') ?> <b><?= (int)$audit['audit_year'] ?></b></span>
        <?php if ($audit['created_by_name']): ?><span class="cx-meta"><?= cxIcon('user') ?> Created by <b><?= $h($audit['created_by_name']) ?></b></span><?php endif; ?>
      </div>
      <?php if ($audit['programme_info']): ?>
        <p class="cx-prog-info"><?= $h($audit['programme_info']) ?></p>
      <?php endif; ?>
      <?php if ($stage >= 0): ?>
      <div class="cx-track" role="list" aria-label="Audit progress">
        <?php foreach (CITY_AUDIT_STAGES as $i => $label):
            $cls = ($published || $i < $stage) ? 'done' : ($i === $stage ? 'now' : '');
        ?>
          <div class="cx-step <?= $cls ?>" role="listitem"<?= $cls === 'now' ? ' aria-current="step"' : '' ?>>
            <i><?= $cls === 'done' ? cxIcon('tick') : ($i + 1) ?></i>
            <span><?= $h($label) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <?php if ($nextItem): ?>
    <div class="cx-next <?= $h($nextItem['level']) ?>">
      <span class="cx-next-ico"><?= cxIcon($nextItem['level'] === 'done' ? 'check' : ($nextItem['level'] === 'info' ? 'clock' : 'alert')) ?></span>
      <div class="cx-next-txt">
        <small><?= $nextItem['level'] === 'action' ? 'Your next step' : ($nextItem['level'] === 'done' ? 'All done' : 'Status') ?></small>
        <p><?= $h($nextItem['text']) ?></p>
      </div>
      <?php if ($nextCta): ?>
        <a class="ca-btn" href="<?= $h($nextCta['href']) ?>"><?= $h($nextCta['label']) ?> <?= cxIcon('arrow') ?></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="cx-kpis">
      <div class="cx-kpi"><span class="cx-kpi-ico g"><?= cxIcon('road') ?></span><div><b><?= count($roads) ?></b><span><?= count($roads) === 1 ? 'Road' : 'Roads' ?> in this audit</span></div></div>
      <div class="cx-kpi"><span class="cx-kpi-ico b"><?= cxIcon('route') ?></span><div><b><?= (int)$segCounts['total'] ?></b><span>Segments</span></div></div>
      <div class="cx-kpi"><span class="cx-kpi-ico o"><?= cxIcon('users') ?></span><div><b><?= (int)$counts['assigned'] ?> <small>/ <?= (int)$counts['total'] ?></small></b><span>Have a surveyor</span></div></div>
      <div class="cx-kpi"><span class="cx-kpi-ico p"><?= cxIcon('check') ?></span><div><b><?= (int)$segCounts['approved'] ?> <small>/ <?= (int)$segCounts['total'] ?></small></b><span>Approved (<?= $approvedPct ?>%)</span></div></div>
    </div>

    <?php if ($mix): ?>
    <div class="card">
      <div class="cx-mix-top"><span>Segment progress</span><span><b><?= $approvedPct ?>%</b> approved</span></div>
      <div class="cx-mix" role="img" aria-label="<?= $h($segCounts['approved'] . ' of ' . $segCounts['total'] . ' segments approved') ?>">
        <?php foreach ($mix as $m): if ($m['pct'] > 0): ?><i class="<?= $h($m['key']) ?>" style="width:<?= (int)$m['pct'] ?>%" title="<?= $h($m['label'] . ': ' . $m['count']) ?>"></i><?php endif; endforeach; ?>
      </div>
      <div class="cx-leg">
        <?php foreach ($mix as $m): if ($m['count'] > 0): ?><span class="<?= $h($m['key']) ?>"><b><?= (int)$m['count'] ?></b> <?= $h($m['label']) ?></span><?php endif; endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card" id="caAssign">
      <div class="card-head">
        <h3>Surveyor assignment</h3>
        <?php if ($canActivate): ?>
          <button class="ca-btn" type="button" id="caActivate" <?= $allAssigned ? '' : 'disabled' ?>>Activate Audit</button>
        <?php endif; ?>
      </div>
      <p class="ca-assign-sum"><b><?= (int)$counts['assigned'] ?></b> of <b><?= (int)$counts['total'] ?></b> segments have a surveyor.</p>
      <?php if ($counts['total'] > 0): ?>
        <div class="cd-track" style="margin-top:10px"><i style="width:<?= cityDashProgress((int)$counts['assigned'], (int)$counts['total']) ?>%"></i></div>
      <?php endif; ?>
      <?php if ($canAssign && !$surveyors): ?>
        <p class="ca-hint-line">No active surveyors are registered in <?= $h($audit['city_name']) ?> yet, so segments cannot be assigned.</p>
      <?php elseif ($canActivate && !$allAssigned): ?>
        <p class="ca-hint-line"><?= $counts['total'] === 0 ? 'Add a road, then assign its segments.' : 'Assign every segment to activate the audit. Roads cannot be added or removed after that.' ?></p>
      <?php elseif ($audit['status'] === 'active'): ?>
        <p class="ca-hint-line">This audit is active. Segments can still be reassigned until auditing starts on them.</p>
      <?php endif; ?>
    </div>

    <?php if ($canEdit): ?>
    <div class="card" id="caAddRoad">
      <div class="card-head"><h3>Add a road</h3></div>
      <?php if (!$available): ?>
        <p class="rd-empty">Every road in this city is already part of this audit.</p>
      <?php else: ?>
      <form id="caRoadForm" class="ca-form" novalidate>
        <div class="ca-field full">
          <label for="caRoad">Road *</label>
          <select id="caRoad" name="road_group_id">
            <option value="">— Select a road —</option>
            <?php foreach ($available as $rg): ?>
              <option value="<?= (int)$rg['id'] ?>"><?= $h($rg['canonical_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="caLen">Total road length (metres) *</label>
          <input id="caLen" name="total_length" type="number" min="50" step="any" inputmode="decimal" placeholder="e.g. 1500">
          <div class="ca-hint">Minimum 50 m. Used to work out the segments.</div>
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="caSeg">Standard segment length *</label>
          <select id="caSeg" name="segment_choice">
            <option value="100">100 meters</option>
            <option value="200">200 meters</option>
            <option value="300">300 meters</option>
            <option value="500" selected>500 meters</option>
            <option value="custom">Custom length…</option>
          </select>
          <div id="caCustomWrap" style="display:none;margin-top:8px">
            <input name="segment_custom" type="number" min="10" step="any" inputmode="decimal" placeholder="e.g. 150">
          </div>
          <div class="ca-err"></div>
        </div>
        <div class="full ca-preview" id="caPreview">
          <div><span>Estimated segments</span><b id="caPvCount">–</b></div>
          <div><span>Each segment</span><b id="caPvEach">–</b></div>
          <div><span>Last segment</span><b id="caPvLast">–</b></div>
        </div>
        <div class="ca-actions full">
          <button class="ca-btn" type="submit">+ Generate &amp; Save Segments</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($showReview): ?>
    <div class="card" id="caReview">
      <div class="card-head">
        <h3>Review &amp; close <a class="cd-back" style="margin:0 0 0 10px" href="city_audit_report.php?id=<?= (int)$audit['id'] ?>">View full report →</a></h3>
        <?php if ($canReview): ?>
          <button class="ca-btn" type="button" id="caClose" <?= $closeBlock === null ? '' : 'disabled' ?>>Close Audit</button>
        <?php elseif ($audit['status'] === 'finalised' && !$isNational): ?>
          <button class="ca-btn" type="button" id="caSend">Send to Admin</button>
        <?php endif; ?>
      </div>
      <div class="cd-chips" style="margin:0 0 4px">
        <span class="cd-chip ok"><?= (int)$rv['approved'] ?> approved</span>
        <span class="cd-chip review"><?= (int)$rv['submitted'] ?> waiting for review</span>
        <span class="cd-chip back"><?= (int)$rv['needs_revisit'] ?> sent back</span>
        <span class="cd-chip"><?= (int)$rv['assigned'] ?> still with surveyors</span>
        <span class="cd-chip">of <?= (int)$rv['total'] ?> segments</span>
      </div>
      <?php if ($canReview && $closeBlock !== null): ?>
        <p class="ca-hint-line">Close Audit unlocks when every segment is approved. <?= $h($closeBlock) ?></p>
      <?php elseif ($audit['status'] === 'finalised'): ?>
        <p class="ca-hint-line">This audit is closed and its report is ready below. Send it to the Admin for approval.</p>
      <?php elseif ($audit['status'] === 'awaiting_approval'): ?>
        <p class="ca-hint-line"><?= $isNational ? 'Waiting for your approval. Open the report to approve or return it.' : 'Sent to the Admin. Waiting for approval.' ?></p>
      <?php elseif ($audit['status'] === 'published'): ?>
        <p class="ca-hint-line">Approved by the Admin. The report is final.</p>
      <?php endif; ?>
      <?php if ($wasReturned): ?>
        <div class="rp-returned">
          <b>The Admin sent this audit back for changes.</b>
          <p class="rp-returned-note"><?= $h($adminNote) ?></p>
        </div>
      <?php endif; ?>

      <?php if ($submitted): ?>
        <h4 class="ca-sub">Waiting for your review</h4>
        <?php foreach ($submitted as $sb): ?>
          <?php $sc = $segScore(isset($sb['latest_audit_id']) ? (int)$sb['latest_audit_id'] : null); $d = $sb['data']; ?>
          <div class="ca-sub-card" data-segment-id="<?= (int)$sb['segment_id'] ?>">
            <div class="ca-sub-head">
              <div>
                <b><a href="city_segment_review.php?audit_id=<?= (int)$audit['id'] ?>&amp;segment_id=<?= (int)$sb['segment_id'] ?>"><?= $h($sb['road_name']) ?> · Segment <?= (int)$sb['segment_number'] ?></a></b>
                <small><?= $h($num($sb['length'])) ?> m · by <?= $h($sb['surveyor_name'] ?? 'Unknown') ?><?= $sb['submitted_at'] ? ' · ' . $h($sb['submitted_at']) : '' ?></small>
              </div>
              <?php if ($sc !== null && isset($sc['final'])): ?>
                <span class="ca-score">Score <?= $h($num($sc['final'])) ?></span>
              <?php endif; ?>
            </div>
            <div class="ca-sub-data">
              <?php foreach ([
                'Cycle track missing' => $d['cycle_track_missing'] ?? null,
                'Cyclist use'         => $d['cyclist_use'] ?? null,
                'Surface'             => $d['surface_material'] ?? null,
                'Width (m)'           => $d['segment_width'] ?? null,
                'Shade'               => $d['shade'] ?? null,
                'Buffer zone'         => $d['buffer_zone'] ?? null,
                'Signage count'       => $d['signage_count'] ?? null,
              ] as $label => $val): ?>
                <span><?= $h($label) ?>: <b><?= ($val === null || $val === '') ? '—' : $h($val) ?></b></span>
              <?php endforeach; ?>
            </div>
            <?php if (!empty($d['comments'])): ?><p class="ca-sub-note">Surveyor's comments: <?= $h($d['comments']) ?></p><?php endif; ?>
            <?php if ($canReview): ?>
            <div class="ca-sub-actions">
              <?php if (cityAnswersRecorded($d)): ?>
              <button class="ca-btn ca-approve" type="button" data-segment-id="<?= (int)$sb['segment_id'] ?>">Approve</button>
              <?php else: ?>
              <button class="ca-btn ca-approve" type="button" disabled title="No answers were recorded. Send it back for a re-audit.">Approve</button>
              <?php endif; ?>
              <a class="ca-btn ghost" href="city_segment_review.php?audit_id=<?= (int)$audit['id'] ?>&amp;segment_id=<?= (int)$sb['segment_id'] ?>">Full review</a>
              <button class="ca-btn ghost ca-sendback-open" type="button">Send back…</button>
            </div>
            <div class="ca-sendback" style="display:none">
              <textarea class="ca-note" rows="2" maxlength="500" placeholder="What does the surveyor need to fix? (required)"></textarea>
              <select class="ca-seg-select ca-new-surveyor" aria-label="Surveyor for the re-audit">
                <option value="">Same surveyor (<?= $h($sb['surveyor_name'] ?? 'Unknown') ?>)</option>
                <?php foreach ($review->citySurveyors((int)$audit['city_id']) as $sv): ?>
                  <?php if ((int)$sv['id'] !== (int)$sb['surveyor_id']): ?>
                    <option value="<?= (int)$sv['id'] ?>">Assign to <?= $h($sv['name']) ?></option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
              <button class="ca-btn danger ca-sendback" type="button" data-segment-id="<?= (int)$sb['segment_id'] ?>">Send back for re-audit</button>
            </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php elseif ($canReview): ?>
        <p class="rd-empty">Nothing is waiting for review right now.</p>
      <?php endif; ?>

      <?php if ($sentBack): ?>
        <h4 class="ca-sub">Sent back for re-audit</h4>
        <?php foreach ($sentBack as $sbk): ?>
          <p class="ca-sub-line"><b><?= $h($sbk['road_name']) ?> · Segment <?= (int)$sbk['segment_number'] ?></b>
            with <?= $h($sbk['surveyor_name'] ?? 'Unknown') ?> — <?= $h($sbk['review_note'] ?? '') ?></p>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($isClosed): ?>
        <h4 class="ca-sub">Audit report</h4>
        <?php if ($overall): ?>
        <div class="cx-score">
          <div class="rv-score cond-<?= $h(cityConditionClass($overall['condition'])) ?>"><b><?= $h($num($overall['score'])) ?></b><span><?= $h($overall['condition']) ?></span></div>
          <div class="rv-bars">
            <?php foreach ($overall['bars'] as $bl => $bv): ?>
            <div class="rv-bar-row"><span><?= $h($bl) ?></span><div class="cd-bar"><i class="<?= $h($barClass((float)$bv)) ?>" style="width:<?= (int)max(0, min(100, round((float)$bv))) ?>%"></i></div><b><?= $h($num($bv)) ?></b></div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php $cc = cityConditionCounts($roadConds); if (count($roadConds) > 1): ?>
        <div class="rp-legend" style="margin-bottom:8px">
          <?php foreach ($cc as $cn => $cv): if ($cv > 0): ?><span class="<?= $h(cityConditionClass($cn)) ?>"><?= $h($cn) ?>: <?= $h(cityPlural((int)$cv, 'road', 'roads')) ?></span><?php endif; endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <div class="rd-scroll"><table class="rd-table">
          <thead><tr><th>Road</th><th>Segments</th><th>Score</th><th>Safety</th><th>Continuity</th><th>Comfort</th><th>Condition</th></tr></thead>
          <tbody>
          <?php foreach ($roads as $r1): $rs = $roadScores[(int)$r1['id']] ?? null; ?>
            <tr>
              <td><?= $h($r1['name']) ?></td>
              <td><?= count($r1['segments']) ?></td>
              <?php if ($rs): ?>
                <td><b><?= $h($num($rs['score'])) ?></b></td><td><?= $h($num($rs['safety_score'])) ?></td>
                <td><?= $h($num($rs['continuity_score'])) ?></td><td><?= $h($num($rs['comfort_score'])) ?></td>
                <td><span class="rp-cond <?= $h(cityConditionClass($rs['condition'])) ?>"><?= $h($rs['condition']) ?></span></td>
              <?php else: ?><td colspan="5">Not available</td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card" id="caRoads">
      <div class="card-head"><h3>Roads in this audit (<?= count($roads) ?>)</h3></div>
      <?php if (!$roads): ?>
        <div class="cx-empty"><?= cxIcon('road') ?><b>No roads yet</b><?= $canEdit ? 'Add the first road using the form above.' : 'No roads have been added to this audit.' ?></div>
      <?php endif; ?>
      <?php foreach ($roads as $r): ?>
        <?php
          $rAssigned = 0; $rPending = 0;
          foreach ($r['segments'] as $s0) {
              if ($s0['assigned_to'] !== null) { $rAssigned++; }
              if ($s0['status'] === 'pending') { $rPending++; }
          }
        ?>
        <div class="ca-road" data-road-id="<?= (int)$r['id'] ?>">
          <div class="ca-road-head">
            <div>
              <h4><?= $h($r['name']) ?></h4>
              <small><?= $h($num($r['total_length'])) ?> m · <?= count($r['segments']) ?> segments of <?= $h($num($r['segment_length'])) ?> m · <?= $rAssigned ?> of <?= count($r['segments']) ?> assigned</small>
              <?php if ($r['segments']): ?>
              <div class="cx-dots" aria-hidden="true">
                <?php foreach ($r['segments'] as $sd):
                    $dCls = $sd['status'] === 'in_progress' ? 'in_progress' : ($sd['status'] === 'completed' ? 'completed' : ($sd['assigned_to'] === null ? 'un' : ''));
                    $dTip = 'Segment ' . (int)$sd['segment_number'] . ' · ' . $num($sd['length']) . ' m · ' . ($sd['assigned_name'] ?? 'Unassigned') . ' · ' . ucfirst(str_replace('_', ' ', (string)$sd['status']));
                ?><span class="cx-dot <?= $h($dCls) ?>" title="<?= $h($dTip) ?>"><?= (int)$sd['segment_number'] ?></span><?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <?php if ($canAssign && $surveyors && $rPending > 0): ?>
                <select class="ca-seg-select ca-road-surveyor" aria-label="Surveyor for the whole road <?= $h($r['name']) ?>">
                  <option value="">— Choose surveyor —</option>
                  <?php foreach ($surveyors as $sv): ?>
                    <option value="<?= (int)$sv['id'] ?>"><?= $h($sv['name']) ?></option>
                  <?php endforeach; ?>
                  <option value="0">Unassign whole road</option>
                </select>
                <button class="ca-btn ghost ca-assign-road" type="button" data-road-id="<?= (int)$r['id'] ?>">Assign whole road</button>
              <?php endif; ?>
              <button class="ca-toggle" type="button">Show segments</button>
              <?php if ($canEdit): ?>
                <button class="ca-btn danger ca-remove" type="button"
                        data-road-id="<?= (int)$r['id'] ?>" data-name="<?= $h($r['name']) ?>">Remove</button>
              <?php endif; ?>
            </div>
          </div>
          <div class="ca-road-body">
            <div class="rd-scroll">
              <table class="rd-table">
                <thead><tr><th>#</th><th>From</th><th>To</th><th>Length</th><th>Status</th><th>Surveyor</th></tr></thead>
                <tbody>
                <?php foreach ($r['segments'] as $s): ?>
                  <tr>
                    <td><?= (int)$s['segment_number'] ?></td>
                    <td><?= $h($num($s['start_distance'])) ?> m</td>
                    <td><?= $h($num($s['end_distance'])) ?> m</td>
                    <td><?= $h($num($s['length'])) ?> m</td>
                    <td><span class="cx-pill st-<?= $h($s['status']) ?>"><?= $h(ucfirst(str_replace('_', ' ', (string)$s['status']))) ?></span></td>
                    <td>
                    <?php if ($canAssign && $s['status'] === 'pending'): ?>
                      <?php $cur = $s['assigned_to'] === null ? '' : (string)(int)$s['assigned_to']; $known = false; ?>
                      <select class="ca-seg-select ca-seg-assign" data-segment-id="<?= (int)$s['segment_id'] ?>" data-current="<?= $h($cur) ?>"
                              aria-label="Surveyor for segment <?= (int)$s['segment_number'] ?>">
                        <option value="">— Unassigned —</option>
                        <?php foreach ($surveyors as $sv): ?>
                          <?php if ((string)$sv['id'] === $cur) { $known = true; } ?>
                          <option value="<?= (int)$sv['id'] ?>" <?= (string)$sv['id'] === $cur ? 'selected' : '' ?>><?= $h($sv['name']) ?></option>
                        <?php endforeach; ?>
                        <?php if ($cur !== '' && !$known): ?>
                          <option value="<?= $h($cur) ?>" selected><?= $h($s['assigned_name'] ?? 'Unknown') ?> (not active)</option>
                        <?php endif; ?>
                      </select>
                    <?php else: ?>
                      <?= $s['assigned_name'] !== null ? $h($s['assigned_name']) : '—' ?>
                    <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
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
<script nonce="<?= $nonce ?>" src="../js/city_audit.js?v=<?= filemtime(__DIR__ . '/../js/city_audit.js') ?>"></script>
</body>
</html>
