<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/city_dashboard.php  —  City Leader home
//  Headline numbers, what needs attention, and one card per audit
//  with its progress and the next step.
//  city_admin sees their own city and can create audits.
//  national_admin can open any city with ?city_id=N (read only).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../repositories/CityDashboardRepository.php';
require_once __DIR__ . '/../repositories/AuditReviewRepository.php';

$isNational = $CURRENT_USER_ROLE === 'national_admin';
$cityId     = $isNational ? (int)($_GET['city_id'] ?? 0) : (int)($CURRENT_USER_CITY_ID ?? 0);

if ($isNational && $cityId <= 0) {
    header('Location: platform_dashboard.php');
    exit;
}

$city = null;
if ($cityId > 0) {
    $s = $pdo->prepare('SELECT id, name FROM cities WHERE id = ?');
    $s->execute([$cityId]);
    $city = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

$audits    = $city ? (new CityDashboardRepository($pdo))->auditSummaries((int)$city['id']) : [];
$adminNotes = $city ? (new AuditReviewRepository($pdo))->adminNotesForCity((int)$city['id']) : [];
$totals    = cityDashTotals($audits);
$canCreate = $city && !$isNational;

// Per-audit counts in the shape cityAuditAttention() expects, plus the attention list.
$attention = [];
foreach ($audits as $k => $a) {
    $counts = [
        'total'         => (int)$a['segment_count'],
        'unassigned'    => (int)$a['unassigned_count'],
        'assigned'      => (int)$a['assigned_count'],
        'submitted'     => (int)$a['submitted_count'],
        'needs_revisit' => (int)$a['needs_revisit_count'],
        'approved'      => (int)$a['approved_count'],
    ];
    $items = cityAuditAttention((string)$a['status'], $counts, $adminNotes[(int)$a['id']] ?? null, $isNational);
    $audits[$k]['_next'] = $items;
    foreach ($items as $it) {
        if ($it['level'] === 'action') {
            $attention[] = ['audit' => $a, 'item' => $it];
        }
    }
}

$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$csrf      = $h($_SESSION['csrf_token'] ?? '');
$activeNav = 'home';
$cityName  = $city ? (string)$city['name'] : 'No city assigned';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title>City Dashboard — CycleAudit</title>
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
      <h1><?= $h($cityName) ?></h1>
      <p><?= $isNational ? '<a href="platform_dashboard.php">← All cities</a>' : 'City Leader workspace' ?></p>
    </div>
    <?php if ($canCreate): ?>
      <button class="btn-new" id="caNewBtn" type="button">+ New Audit</button>
    <?php endif; ?>
  </div>
  <div class="content" id="caApp" data-csrf="<?= $csrf ?>">
  <?php if (!$city): ?>
    <div class="card"><p class="rd-empty">No city is assigned to your account yet. Please ask an Admin to assign one.</p></div>
  <?php else: ?>

    <?php if ($canCreate): ?>
    <div class="card" id="caNewCard" style="display:none">
      <div class="card-head"><h3>New audit for <?= $h($cityName) ?></h3></div>
      <form id="caNewForm" class="ca-form" novalidate>
        <div class="ca-field">
          <label for="caName">Audit name *</label>
          <input id="caName" name="name" type="text" maxlength="150" placeholder="e.g. Pune Cycle Infrastructure Audit">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="caYear">Audit year *</label>
          <input id="caYear" name="audit_year" type="number" min="2000" max="2100" value="<?= (int)date('Y') ?>">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="caCity">City</label>
          <input id="caCity" type="text" value="<?= $h($cityName) ?>" disabled>
        </div>
        <div class="ca-field">
          <label for="caState">State *</label>
          <input id="caState" name="state" type="text" maxlength="100" placeholder="e.g. Maharashtra">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field full">
          <label for="caInfo">Programme info</label>
          <textarea id="caInfo" name="programme_info" maxlength="2000" placeholder="Programme, funder or scope notes (optional)"></textarea>
          <div class="ca-err"></div>
        </div>
        <div class="ca-actions full">
          <button class="ca-btn" type="submit">Create audit</button>
          <button class="ca-btn ghost" type="button" id="caNewCancel">Cancel</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="stat-grid">
      <div class="stat-card"><div class="stat-icon" style="background:#edf7d6">📋</div><div><div class="stat-val"><?= (int)$totals['audits'] ?></div><div class="stat-lbl">Audits (<?= (int)$totals['open'] ?> open)</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dcfce7">✅</div><div><div class="stat-val"><?= (int)$totals['approved'] ?> <small style="font-size:.9rem;font-weight:600;color:var(--grl)">/ <?= (int)$totals['segments'] ?></small></div><div class="stat-lbl">Segments approved</div></div></div>
      <div class="stat-card<?= $totals['submitted'] > 0 ? ' cd-hot' : '' ?>"><div class="stat-icon" style="background:#dbeafe">🔍</div><div><div class="stat-val"><?= (int)$totals['submitted'] ?></div><div class="stat-lbl">Waiting for your review</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef3c7">🚴</div><div><div class="stat-val"><?= (int)$totals['with_surveyors'] ?></div><div class="stat-lbl">With surveyors</div></div></div>
    </div>

    <div class="cd-layout">
      <div class="cd-stack">
        <div class="card">
          <div class="card-head"><h3>Your audits</h3></div>
          <?php if (!$audits): ?>
            <div class="cd-empty"><b>No audits yet</b><?= $canCreate ? 'Use “+ New Audit” to start the first one.' : 'This city has no audits yet.' ?></div>
          <?php endif; ?>
          <?php foreach ($audits as $a):
              $total    = (int)$a['segment_count'];
              $approved = (int)$a['approved_count'];
              $pct      = cityDashProgress($approved, $total);
              $first    = $a['_next'][0] ?? null;
              $isDraft  = (string)$a['status'] === 'draft';
          ?>
          <div class="cd-audit">
            <div class="cd-audit-head">
              <div>
                <h4><?= $h($a['name']) ?></h4>
                <div class="cd-audit-meta"><?= (int)$a['audit_year'] ?> · <?= $h($a['state']) ?> · <?= $h(cityPlural((int)$a['road_count'], 'road', 'roads')) ?> · <?= $h(cityPlural($total, 'segment', 'segments')) ?></div>
              </div>
              <span class="ca-badge <?= $h($a['status']) ?>"><?= $h(cityStatusLabel((string)$a['status'])) ?></span>
            </div>

            <?php if ($total > 0): ?>
            <div class="cd-progress">
              <div class="cd-progress-top"><span><b><?= $approved ?></b> of <?= $total ?> segments approved</span><span><?= $pct ?>%</span></div>
              <div class="cd-track"><i style="width:<?= $pct ?>%"></i></div>
            </div>
            <div class="cd-chips">
              <?php if ((int)$a['submitted_count'] > 0): ?><span class="cd-chip review"><?= (int)$a['submitted_count'] ?> to review</span><?php endif; ?>
              <?php if ((int)$a['needs_revisit_count'] > 0): ?><span class="cd-chip back"><?= (int)$a['needs_revisit_count'] ?> sent back</span><?php endif; ?>
              <?php if ((int)$a['assigned_count'] > 0): ?><span class="cd-chip"><?= (int)$a['assigned_count'] ?> with surveyors</span><?php endif; ?>
              <?php if ((int)$a['unassigned_count'] > 0): ?><span class="cd-chip"><?= (int)$a['unassigned_count'] ?> unassigned</span><?php endif; ?>
              <?php if ($approved === $total): ?><span class="cd-chip ok">All approved</span><?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($first): ?>
              <p class="cd-next <?= $h($first['level']) ?>"><?= $h($first['text']) ?></p>
            <?php endif; ?>

            <div class="cd-actions">
              <a class="ca-btn" href="city_audit.php?id=<?= (int)$a['id'] ?>"><?= $isDraft ? 'Set up audit' : 'Open audit' ?></a>
              <?php if (!$isDraft && (int)$a['submitted_count'] > 0): ?>
                <a class="ca-btn ghost" href="city_audit.php?id=<?= (int)$a['id'] ?>#caReview">Review submissions</a>
              <?php endif; ?>
              <?php if (!$isDraft): ?>
                <a class="ca-btn<?= ($isNational && (string)$a['status'] === 'awaiting_approval') ? '' : ' ghost' ?>" href="city_audit_report.php?id=<?= (int)$a['id'] ?>"><?= ($isNational && (string)$a['status'] === 'awaiting_approval') ? 'Review &amp; decide' : 'View report' ?></a>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="cd-stack">
        <div class="card">
          <div class="card-head"><h3>Needs your attention</h3></div>
          <?php if (!$attention): ?>
            <div class="cd-empty"><b>You are all caught up</b>Nothing is waiting for you right now.</div>
          <?php else: ?>
          <ul class="cd-attn">
            <?php foreach (array_slice($attention, 0, 8) as $at):
                $link = in_array($at['audit']['status'], $isNational ? ['awaiting_approval'] : ['finalised'], true)
                    ? 'city_audit_report.php?id=' . (int)$at['audit']['id']
                    : 'city_audit.php?id=' . (int)$at['audit']['id'] . ((int)$at['audit']['submitted_count'] > 0 ? '#caReview' : '');
            ?>
            <li>
              <span class="cd-dot action"></span>
              <div style="flex:1;min-width:0">
                <?= $h($at['item']['text']) ?>
                <small><?= $h($at['audit']['name']) ?></small>
              </div>
              <a href="<?= $h($link) ?>">Open →</a>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
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
<script nonce="<?= $nonce ?>" src="../js/city_audit.js?v=<?= filemtime(__DIR__ . '/../js/city_audit.js') ?>"></script>
</body>
</html>
