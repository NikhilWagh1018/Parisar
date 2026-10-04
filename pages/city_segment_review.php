<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/city_segment_review.php?audit_id=N&segment_id=M
//  One segment, in full: its score, the Safety / Continuity / Comfort
//  breakdown, everything the surveyor recorded, obstructions and
//  intersections. City Leader approves it or sends it back from here.
//  national_admin can open it read only.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../repositories/AuditReviewRepository.php';
require_once __DIR__ . '/../repositories/AuditReportRepository.php';
require_once __DIR__ . '/../services/ScoreService.php';

$isNational = $CURRENT_USER_ROLE === 'national_admin';
$auditRepo  = new CityAuditRepository($pdo);
$audit      = $auditRepo->find((int)($_GET['audit_id'] ?? 0));

if ($audit === null || (!$isNational && (int)$audit['city_id'] !== (int)($CURRENT_USER_CITY_ID ?? 0))) {
    header('Location: ' . ($isNational ? 'platform_dashboard.php' : 'city_dashboard.php'));
    exit;
}

$auditUrl = 'city_audit.php?id=' . (int)$audit['id'];
$seg      = (new AuditReportRepository($pdo))->segmentDetail((int)$audit['id'], (int)($_GET['segment_id'] ?? 0));
if ($seg === null) {
    header('Location: ' . $auditUrl);
    exit;
}

$status    = (string)$seg['assignment_status'];
$data      = is_array($seg['data']) ? $seg['data'] : [];
$answers   = cityAnswersRecorded($data);
$canReview = !$isNational
    && in_array((string)$audit['status'], AUDIT_REVIEW_OPEN_STATUSES, true)
    && $status === 'submitted';
$canApprove = $canReview && $answers;

$score = null;
if ($seg['latest_audit_id'] !== null) {
    try { $score = calculateSegmentScoreDetailed((int)$seg['latest_audit_id'], $pdo); }
    catch (Throwable $e) { $score = null; }
}

// A score worked out from nothing says nothing, so it is not shown.
if (!$answers) { $score = null; }

$nextId  = $canReview ? (new AuditReportRepository($pdo))->nextToReview((int)$audit['id'], (int)$seg['segment_id']) : null;
$nextUrl = $nextId !== null
    ? 'city_segment_review.php?audit_id=' . (int)$audit['id'] . '&segment_id=' . $nextId
    : $auditUrl . '#caReview';
$surveyors = $canReview ? (new AuditReviewRepository($pdo))->citySurveyors((int)$audit['city_id']) : [];

$h   = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = static fn($v): string => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$csrf      = $h($_SESSION['csrf_token'] ?? '');
$activeNav = 'home';

/** Show a stored answer: empty -> dash, JSON list/object -> readable text. */
$show = static function (mixed $v) use ($h): string {
    if ($v === null || $v === '' || $v === '[]' || $v === '{}') {
        return '<dd class="empty">Not recorded</dd>';
    }
    $s = (string)$v;
    if ($s !== '' && ($s[0] === '[' || $s[0] === '{')) {
        $j = json_decode($s, true);
        if (is_array($j)) {
            $parts = [];
            foreach ($j as $k => $val) {
                if (is_array($val)) { $val = implode(', ', array_map('strval', $val)); }
                $parts[] = is_int($k) ? (string)$val : $k . ': ' . $val;
            }
            $parts = array_filter($parts, static fn($p) => trim($p) !== '');
            return $parts ? '<dd>' . $h(implode(' · ', $parts)) . '</dd>' : '<dd class="empty">Not recorded</dd>';
        }
    }
    return '<dd>' . $h($s) . '</dd>';
};
/** Bar colour for a 0-100 penalty score (lower is better). */
$barClass = static fn(float $v): string => $v <= 20 ? '' : ($v <= 60 ? 'mid' : 'bad');

$statusText = [
    'submitted'     => 'Waiting for your review',
    'approved'      => 'Approved',
    'needs_revisit' => 'Sent back to the surveyor',
    'assigned'      => 'With the surveyor',
];
$pageTitle = $seg['road_name'] . ' · Segment ' . (int)$seg['segment_number'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title><?= $h($pageTitle) ?> — Review — CycleAudit</title>
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
      <h1><?= $h($pageTitle) ?></h1>
      <p><a href="<?= $h($auditUrl) ?>">← <?= $h($audit['name']) ?></a></p>
    </div>
    <span class="ca-badge <?= $h($status) ?>"><?= $h($statusText[$status] ?? cityStatusLabel($status)) ?></span>
  </div>

  <div class="content" id="caApp" data-csrf="<?= $csrf ?>" data-audit-id="<?= (int)$audit['id'] ?>"
       data-segment-id="<?= (int)$seg['segment_id'] ?>" data-next-url="<?= $h($nextUrl) ?>">

    <?php if ($status === 'needs_revisit'): ?>
      <p class="rv-sentback"><b>Sent back.</b> <?= $h($seg['review_note'] ?? '') ?></p>
    <?php elseif ($status === 'assigned'): ?>
      <p class="rv-info">The surveyor has not submitted this segment yet. What you see below is only what has been saved so far.</p>
    <?php endif; ?>
    <?php if ($canReview && !$answers): ?>
      <p class="rv-warn"><b>No answers recorded.</b> This segment was submitted without any answers, so its score does not mean anything. Send it back for a re-audit. Approve stays locked until answers are recorded.</p>
    <?php endif; ?>

    <div class="card">
      <div class="card-head"><h3>Score</h3></div>
      <?php if ($score !== null): $cond = (string)$score['condition']; ?>
      <div class="rv-hero">
        <div class="rv-score cond-<?= $h(cityConditionClass($cond)) ?>">
          <b><?= $h($num($score['final'])) ?></b>
          <span><?= $h($cond) ?></span>
        </div>
        <div class="rv-bars">
          <?php foreach (['Safety' => 'safety_score', 'Continuity' => 'continuity_score', 'Comfort' => 'comfort_score'] as $label => $key):
              $v = (float)$score[$key]; ?>
            <div class="rv-bar-row">
              <span><?= $h($label) ?></span>
              <div class="cd-bar"><i class="<?= $h($barClass($v)) ?>" style="width:<?= (int)round(max(0, min(100, $v))) ?>%"></i></div>
              <b><?= $h($num($v)) ?></b>
            </div>
          <?php endforeach; ?>
          <p class="cd-sub">Scores run from 0 to 100. Lower is better.</p>
        </div>
      </div>
      <?php else: ?>
        <p class="cd-empty"><b>No score yet</b>Nothing has been recorded for this segment.</p>
      <?php endif; ?>
    </div>

    <?php if ($score !== null && !empty($score['parameters'])):
        $groups = [
            'Safety' => ['safety', ['buffer_zone' => 'Buffer zone', 'light_after_dark' => 'Light after dark',
                                    'traffic_calming' => 'Traffic calming', 'partial_obs' => 'Partial obstructions']],
            'Continuity' => ['continuity', ['missing_ramps' => 'Missing ramps', 'missing_signage' => 'Missing signage',
                                            'total_obs' => 'Total obstructions']],
            'Comfort' => ['comfort', ['surface' => 'Surface', 'cyclist_slowed' => 'Cyclists slowed', 'shade' => 'Shade']],
        ]; ?>
    <div class="card">
      <div class="card-head"><h3>What drives the score</h3></div>
      <div class="rv-params">
        <?php foreach ($groups as $title => [$gk, $labels]): ?>
          <div>
            <h4><?= $h($title) ?></h4>
            <?php foreach ($labels as $pk => $pl): $v = (float)($score['parameters'][$gk][$pk] ?? 0); ?>
              <div class="rv-bar-row">
                <span><?= $h($pl) ?></span>
                <div class="cd-bar"><i class="<?= $h($barClass($v)) ?>" style="width:<?= (int)round(max(0, min(100, $v))) ?>%"></i></div>
                <b><?= $h($num($v)) ?></b>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head"><h3>What the surveyor recorded</h3></div>
      <dl class="rv-dl">
        <div><dt>Surveyor</dt><dd><?= $h($seg['surveyor_name'] ?? 'Unknown') ?></dd></div>
        <div><dt>Submitted</dt><dd><?= $h(cityLocalTime($seg['submitted_at'] ?? null)) ?></dd></div>
        <div><dt>Segment length</dt><dd><?= $h($num($seg['length'])) ?> m (<?= $h($num($seg['start_distance'])) ?> – <?= $h($num($seg['end_distance'])) ?> m)</dd></div>
        <?php foreach ([
            'Cycle track missing' => 'cycle_track_missing', 'Missing length (m)' => 'missing_length',
            'Cyclist use' => 'cyclist_use', 'Surface type' => 'surface_type', 'Surface material' => 'surface_material',
            'Track width (m)' => 'segment_width', 'Track geometry' => 'track_geometry', 'Buffer zone' => 'buffer_zone',
            'Shade' => 'shade', 'Light after sunset' => 'light_after_sunset', 'People walking' => 'people_walking',
            'Signage count' => 'signage_count', 'Surface issues' => 'surface_issues',
            'Overhead issues' => 'overhead_issues', 'Footpath rating' => 'footpath_rating',
        ] as $label => $key): ?>
          <div><dt><?= $h($label) ?></dt><?= $show($data[$key] ?? null) ?></div>
        <?php endforeach; ?>
      </dl>
      <?php if (!empty($data['comments'])): ?>
        <p class="rv-note"><b>Surveyor's comments:</b> <?= $h($data['comments']) ?></p>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head"><h3>Obstructions (<?= count($seg['obstructions']) ?>)</h3></div>
      <?php if (!$seg['obstructions']): ?>
        <p class="cd-empty">No obstructions were recorded.</p>
      <?php else: ?>
      <div class="rd-scroll"><table class="rd-table">
        <thead><tr><th>Category</th><th>Type</th><th>Partial</th><th>Total</th><th>Cyclists slowed</th></tr></thead>
        <tbody>
        <?php foreach ($seg['obstructions'] as $o): ?>
          <tr><td><?= $h($o['obstruction_category'] ?? '—') ?></td><td><?= $h($o['obstruction_type'] ?? '—') ?></td>
              <td><?= $h($o['partial_obstructions'] ?? 0) ?></td><td><?= $h($o['total_obstructions'] ?? 0) ?></td>
              <td><?= $h($o['cyclist_slowed'] ?? 0) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head"><h3>Intersections (<?= count($seg['intersections']) ?>)</h3></div>
      <?php if (!$seg['intersections']): ?>
        <p class="cd-empty">No intersections were recorded.</p>
      <?php else: ?>
      <div class="rd-scroll"><table class="rd-table">
        <thead><tr><th>#</th><th>Landmark</th><th>Off ramp</th><th>On ramp</th><th>Markings</th><th>Signage</th><th>Traffic calming</th><th>Discontinuity</th><th>Tapering</th></tr></thead>
        <tbody>
        <?php foreach ($seg['intersections'] as $i): ?>
          <tr><td><?= $h($i['intersection_num'] ?? '') ?></td><td><?= $h($i['landmark_name'] ?? '—') ?></td>
            <?php foreach (['off_ramp', 'on_ramp', 'markings', 'signage', 'traffic_calming', 'discontinuity', 'tapering'] as $k): ?>
              <td><?= ($i[$k] ?? '') === '' || ($i[$k] ?? null) === null ? '—' : $h($i[$k]) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <?php if ($canReview): ?>
    <div class="card rv-actions" id="rvActions">
      <div class="card-head"><h3>Your decision</h3></div>
      <div class="ca-sub-actions">
        <button class="ca-btn" type="button" id="rvApprove" <?= $canApprove ? '' : 'disabled title="Approve is locked until answers are recorded."' ?>>Approve segment</button>
        <button class="ca-btn ghost" type="button" id="rvSendBackOpen">Send back for re-audit…</button>
        <a class="ca-btn ghost" href="<?= $h($auditUrl) ?>#caReview">Back to audit</a>
      </div>
      <div class="rv-sendback" id="rvSendBackBox">
        <textarea id="rvNote" rows="3" maxlength="500" placeholder="What does the surveyor need to fix? (required)"></textarea>
        <select class="ca-seg-select" id="rvSurveyor" aria-label="Surveyor for the re-audit">
          <option value="">Same surveyor (<?= $h($seg['surveyor_name'] ?? 'Unknown') ?>)</option>
          <?php foreach ($surveyors as $sv): if ((int)$sv['id'] === (int)$seg['surveyor_id']) { continue; } ?>
            <option value="<?= (int)$sv['id'] ?>">Assign to <?= $h($sv['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="ca-btn" type="button" id="rvSendBack">Send back for re-audit</button>
      </div>
    </div>
    <?php else: ?>
    <div class="card"><div class="cd-actions"><a class="ca-btn ghost" href="<?= $h($auditUrl) ?>">Back to audit</a>
      <a class="ca-btn ghost" href="city_audit_report.php?id=<?= (int)$audit['id'] ?>">View report</a></div></div>
    <?php endif; ?>

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
