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
require_once __DIR__ . '/../helpers/Cities.php';
require_once __DIR__ . '/../repositories/CityDashboardRepository.php';
require_once __DIR__ . '/../repositories/AuditReviewRepository.php';
require_once __DIR__ . '/partials/cx_icons.php';

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
$cityState = $city ? cityStateFor($cityName) : null; // known city -> State fills in by itself

// ── Summary numbers for the hero, progress card and pipeline ──
$cityMix = ['total' => 0, 'approved' => 0, 'submitted' => 0, 'needs_revisit' => 0, 'assigned' => 0, 'unassigned' => 0];
foreach ($audits as $a0) {
    $cityMix['total']         += (int)$a0['segment_count'];
    $cityMix['approved']      += (int)$a0['approved_count'];
    $cityMix['submitted']     += (int)$a0['submitted_count'];
    $cityMix['needs_revisit'] += (int)$a0['needs_revisit_count'];
    $cityMix['assigned']      += (int)$a0['assigned_count'];
    $cityMix['unassigned']    += (int)$a0['unassigned_count'];
}
$cityMixParts = citySegmentMix($cityMix);
$cityPct      = cityDashProgress($cityMix['approved'], $cityMix['total']);
$pipeline     = cityPipeline($audits);
$pipeMax      = max(1, max($pipeline));
$firstName    = trim((string)strtok(trim((string)($CURRENT_USER_NAME ?? '')), ' '));
$hour         = (int)(new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('G');
$greeting     = cityGreeting($hour);
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
  <div class="content cx-page" id="caApp" data-csrf="<?= $csrf ?>">
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
          <label for="caDate">Audit date *</label>
          <input id="caDate" name="audit_date" type="date" min="2000-01-01" max="2100-12-31" value="<?= $h((new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d')) ?>">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="caCity">City</label>
          <input id="caCity" type="text" value="<?= $h($cityName) ?>" disabled>
        </div>
        <div class="ca-field">
          <label for="caState">State *</label>
          <input id="caState" name="state" type="text" maxlength="100" placeholder="e.g. Maharashtra"<?= $cityState !== null ? ' value="' . $h($cityState) . '" readonly' : '' ?>>
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

    <section class="cx-hero">
      <div class="cx-hero-row">
        <div>
          <h2 class="cx-hello"><?= $h($greeting) ?><?= $firstName !== '' && !$isNational ? ', ' . $h($firstName) : '' ?></h2>
          <p class="cx-hero-sub">
            <?php if (!$audits): ?>
              <?= $canCreate ? 'Start your first audit for <b>' . $h($cityName) . '</b> with “+ New Audit”.' : 'No audits in <b>' . $h($cityName) . '</b> yet.' ?>
            <?php elseif ($attention): ?>
              <b><?= count($attention) ?></b> <?= count($attention) === 1 ? 'thing needs' : 'things need' ?> your attention in <b><?= $h($cityName) ?></b>.
            <?php else: ?>
              You are all caught up in <b><?= $h($cityName) ?></b>.
            <?php endif; ?>
          </p>
        </div>
        <div class="cx-metas">
          <span class="cx-meta"><?= cxIcon('pin') ?> <b><?= $h($cityName) ?></b></span>
          <span class="cx-meta"><?= cxIcon('list') ?> <b><?= (int)$totals['audits'] ?></b> <?= $totals['audits'] === 1 ? 'audit' : 'audits' ?></span>
        </div>
      </div>
    </section>

    <div class="cx-kpis">
      <div class="cx-kpi"><span class="cx-kpi-ico g"><?= cxIcon('list') ?></span><div><b><?= (int)$totals['audits'] ?></b><span>Audits (<?= (int)$totals['open'] ?> open)</span></div></div>
      <div class="cx-kpi"><span class="cx-kpi-ico p"><?= cxIcon('check') ?></span><div><b><?= (int)$totals['approved'] ?> <small>/ <?= (int)$totals['segments'] ?></small></b><span>Segments approved</span></div></div>
      <div class="cx-kpi<?= $totals['submitted'] > 0 ? ' hot' : '' ?>"><span class="cx-kpi-ico o"><?= cxIcon('eye') ?></span><div><b><?= (int)$totals['submitted'] ?></b><span>Waiting for your review</span></div></div>
      <div class="cx-kpi"><span class="cx-kpi-ico b"><?= cxIcon('users') ?></span><div><b><?= (int)$totals['with_surveyors'] ?></b><span>Segments with surveyors</span></div></div>
    </div>

    <div class="cd-layout">
      <div class="cd-stack">
        <div class="card">
          <div class="card-head"><h3>Your audits</h3></div>
          <?php if (!$audits): ?>
            <div class="cx-empty"><?= cxIcon('list') ?><b>No audits yet</b><?= $canCreate ? 'Use “+ New Audit” to start the first one.' : 'This city has no audits yet.' ?></div>
          <?php endif; ?>
          <?php foreach ($audits as $a):
              $total    = (int)$a['segment_count'];
              $approved = (int)$a['approved_count'];
              $pct      = cityDashProgress($approved, $total);
              $first    = $a['_next'][0] ?? null;
              $isDraft  = (string)$a['status'] === 'draft';
          ?>
          <div class="cd-audit s-<?= $h($a['status']) ?>">
            <div class="cd-audit-head">
              <div>
                <h4><?= $h($a['name']) ?></h4>
                <div class="cd-audit-meta"><?= $h(cityAuditDateLabel($a['audit_date'] ?? null, (int)$a['audit_year'])) ?> · <?= $h($a['state']) ?> · <?= $h(cityPlural((int)$a['road_count'], 'road', 'roads')) ?> · <?= $h(cityPlural($total, 'segment', 'segments')) ?></div>
              </div>
              <span class="ca-badge <?= $h($a['status']) ?>"><?= $h(cityStatusLabel((string)$a['status'])) ?></span>
            </div>

            <?php $stg = cityAuditStage((string)$a['status']); ?>
            <?php if ($stg >= 0): ?>
            <div class="cx-stagebar" aria-label="Stage <?= $stg + 1 ?> of <?= count(CITY_AUDIT_STAGES) ?>">
              <?php foreach (CITY_AUDIT_STAGES as $si => $sl): ?><i class="<?= $si <= $stg ? 'on' : '' ?>"></i><?php endforeach; ?>
              <span>Stage <?= $stg + 1 ?> of <?= count(CITY_AUDIT_STAGES) ?> · <b><?= $h(CITY_AUDIT_STAGES[$stg]) ?></b></span>
            </div>
            <?php endif; ?>

            <?php if ($total > 0):
                $aMix = citySegmentMix([
                    'total' => $total, 'approved' => $approved, 'submitted' => (int)$a['submitted_count'],
                    'needs_revisit' => (int)$a['needs_revisit_count'], 'assigned' => (int)$a['assigned_count'],
                    'unassigned' => (int)$a['unassigned_count'],
                ]);
            ?>
            <div class="cd-progress">
              <div class="cd-progress-top"><span><b><?= $approved ?></b> of <?= $total ?> segments approved</span><span><?= $pct ?>%</span></div>
              <div class="cx-mix" role="img" aria-label="<?= $h($approved . ' of ' . $total . ' segments approved') ?>">
                <?php foreach ($aMix as $m): if ($m['pct'] > 0): ?><i class="<?= $h($m['key']) ?>" style="width:<?= (int)$m['pct'] ?>%" title="<?= $h($m['label'] . ': ' . $m['count']) ?>"></i><?php endif; endforeach; ?>
              </div>
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
            <div class="cx-empty"><?= cxIcon('check') ?><b>You are all caught up</b>Nothing is waiting for you right now.</div>
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

        <?php if ($cityMixParts): ?>
        <div class="card">
          <div class="card-head"><h3>City progress</h3></div>
          <div class="cx-mix-top"><span>All audits</span><span><b><?= $cityPct ?>%</b> approved</span></div>
          <div class="cx-mix" role="img" aria-label="<?= $h($cityMix['approved'] . ' of ' . $cityMix['total'] . ' segments approved') ?>">
            <?php foreach ($cityMixParts as $m): if ($m['pct'] > 0): ?><i class="<?= $h($m['key']) ?>" style="width:<?= (int)$m['pct'] ?>%" title="<?= $h($m['label'] . ': ' . $m['count']) ?>"></i><?php endif; endforeach; ?>
          </div>
          <div class="cx-leg">
            <?php foreach ($cityMixParts as $m): if ($m['count'] > 0): ?><span class="<?= $h($m['key']) ?>"><b><?= (int)$m['count'] ?></b> <?= $h($m['label']) ?></span><?php endif; endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($audits): ?>
        <div class="card">
          <div class="card-head"><h3>Audit pipeline</h3></div>
          <ul class="cx-pipe">
            <?php foreach ($pipeline as $pl => $pc): ?>
            <li class="<?= $pc === 0 ? 'zero' : '' ?>">
              <span><?= $h($pl) ?></span>
              <div class="cd-bar"><i style="width:<?= (int)round($pc / $pipeMax * 100) ?>%"></i></div>
              <b><?= (int)$pc ?></b>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
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
