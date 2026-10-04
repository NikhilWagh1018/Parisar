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
  <div class="content" id="caApp" data-csrf="<?= $csrf ?>" data-audit-id="<?= (int)$audit['id'] ?>">

    <div class="card">
      <div class="card-head">
        <h3>Audit details</h3>
        <span class="ca-badge <?= $h($audit['status']) ?>"><?= $h($statusLabel) ?></span>
      </div>
      <div class="ca-meta">
        <span>City: <b><?= $h($audit['city_name']) ?></b></span>
        <span>State: <b><?= $h($audit['state']) ?></b></span>
        <span>Year: <b><?= (int)$audit['audit_year'] ?></b></span>
        <?php if ($audit['created_by_name']): ?><span>Created by: <b><?= $h($audit['created_by_name']) ?></b></span><?php endif; ?>
      </div>
      <?php if ($audit['programme_info']): ?>
        <p style="margin:12px 0 0;font-size:.85rem;white-space:pre-line"><?= $h($audit['programme_info']) ?></p>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head">
        <h3>Surveyor assignment</h3>
        <?php if ($canActivate): ?>
          <button class="ca-btn" type="button" id="caActivate" <?= $allAssigned ? '' : 'disabled' ?>>Activate Audit</button>
        <?php endif; ?>
      </div>
      <p class="ca-assign-sum"><b><?= (int)$counts['assigned'] ?></b> of <b><?= (int)$counts['total'] ?></b> segments have a surveyor.</p>
      <?php if ($canAssign && !$surveyors): ?>
        <p class="ca-hint-line">No active surveyors are registered in <?= $h($audit['city_name']) ?> yet, so segments cannot be assigned.</p>
      <?php elseif ($canActivate && !$allAssigned): ?>
        <p class="ca-hint-line"><?= $counts['total'] === 0 ? 'Add a road, then assign its segments.' : 'Assign every segment to activate the audit. Roads cannot be added or removed after that.' ?></p>
      <?php elseif ($audit['status'] === 'active'): ?>
        <p class="ca-hint-line">This audit is active. Segments can still be reassigned until auditing starts on them.</p>
      <?php endif; ?>
    </div>

    <?php if ($canEdit): ?>
    <div class="card">
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
        <h3>Review &amp; close</h3>
        <?php if ($canReview): ?>
          <button class="ca-btn" type="button" id="caClose" <?= $closeBlock === null ? '' : 'disabled' ?>>Close Audit</button>
        <?php elseif ($audit['status'] === 'finalised' && !$isNational): ?>
          <button class="ca-btn" type="button" id="caSend">Send to Admin</button>
        <?php endif; ?>
      </div>
      <p class="ca-assign-sum">
        <b><?= (int)$rv['approved'] ?></b> approved ·
        <b><?= (int)$rv['submitted'] ?></b> waiting for review ·
        <b><?= (int)$rv['needs_revisit'] ?></b> sent back ·
        <b><?= (int)$rv['assigned'] ?></b> still with surveyors
        (of <b><?= (int)$rv['total'] ?></b> segments)
      </p>
      <?php if ($canReview && $closeBlock !== null): ?>
        <p class="ca-hint-line">Close Audit unlocks when every segment is approved. <?= $h($closeBlock) ?></p>
      <?php elseif ($audit['status'] === 'finalised'): ?>
        <p class="ca-hint-line">This audit is closed and its report is ready below. Send it to the Admin for approval.</p>
      <?php elseif ($audit['status'] === 'awaiting_approval'): ?>
        <p class="ca-hint-line">Sent to the Admin. Waiting for approval.</p>
      <?php endif; ?>

      <?php if ($submitted): ?>
        <h4 class="ca-sub">Waiting for your review</h4>
        <?php foreach ($submitted as $sb): ?>
          <?php $sc = $segScore(isset($sb['latest_audit_id']) ? (int)$sb['latest_audit_id'] : null); $d = $sb['data']; ?>
          <div class="ca-sub-card" data-segment-id="<?= (int)$sb['segment_id'] ?>">
            <div class="ca-sub-head">
              <div>
                <b><?= $h($sb['road_name']) ?> · Segment <?= (int)$sb['segment_number'] ?></b>
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
              <button class="ca-btn ca-approve" type="button" data-segment-id="<?= (int)$sb['segment_id'] ?>">Approve</button>
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
                <td><?= $h($rs['condition']) ?></td>
              <?php else: ?><td colspan="5">Not available</td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head"><h3>Roads in this audit (<?= count($roads) ?>)</h3></div>
      <?php if (!$roads): ?>
        <p class="rd-empty"><?= $canEdit ? 'No roads yet. Add the first road above.' : 'No roads in this audit yet.' ?></p>
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
                    <td><?= $h(ucfirst(str_replace('_', ' ', (string)$s['status']))) ?></td>
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
