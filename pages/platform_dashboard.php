<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/platform_dashboard.php  —  Admin Dashboard (home)
//  national_admin only: what needs attention, every audit, every city.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../helpers/AdminDashboard.php';
require_once __DIR__ . '/../repositories/AuditReviewRepository.php';
require_once __DIR__ . '/../repositories/AdminDashboardRepository.php';

if ($CURRENT_USER_ROLE !== 'national_admin') {
    header('Location: ' . roleHomePage($CURRENT_USER_ROLE));
    exit;
}

$dash     = new AdminDashboardRepository($pdo);
$cities   = $dash->cities();
$audits   = adminSortAudits($dash->audits());
$awaiting = (new AuditReviewRepository($pdo))->awaitingApproval();
$toVerify = $dash->roadsToVerifyCount();

$segs = $done = 0;
foreach ($cities as $c) {
    $segs += $c['segs'];
    $done += $c['done'];
}
$inProgress = count(array_filter($audits, static fn(array $a): bool => in_array($a['status'], ADMIN_IN_PROGRESS_STATUSES, true)));
$attention  = adminAttentionItems(
    $awaiting,
    adminStalledAudits($audits, new DateTimeImmutable('now', new DateTimeZone('UTC'))),
    $toVerify,
    adminCitiesWithoutLeader($cities)
);
$pct = static fn(int $d, int $t): string => $t > 0 ? (string)round($d * 100 / $t) . '%' : '—';

$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$activeNav = 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title>Dashboard — CycleAudit</title>
<link nonce="<?= $nonce ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/role_sidebar.php'; ?>
<main>
  <div class="topbar">
    <button class="sb-hamburger" id="sb-toggle" aria-label="Menu">&#9776;</button>
    <div class="topbar-left">
      <h1>Dashboard</h1>
      <p>What needs you, and how every city is doing.</p>
    </div>
  </div>
  <div class="content">

    <div class="stat-grid">
      <div class="stat-card"><div class="stat-icon" style="background:#fee2e2">📝</div><div><div class="stat-val"><?= count($awaiting) ?></div><div class="stat-lbl">Awaiting your approval</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dbeafe">🛠️</div><div><div class="stat-val"><?= $inProgress ?></div><div class="stat-lbl">Audits in progress</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef3c7">🛣️</div><div><div class="stat-val"><?= $toVerify ?></div><div class="stat-lbl">Roads to verify</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dcfce7">✅</div><div><div class="stat-val"><?= $pct($done, $segs) ?></div><div class="stat-lbl">Segments done</div></div></div>
    </div>

    <div class="card" id="needsAttention">
      <div class="card-head">
        <h3>Needs your attention<?= $attention ? ' (' . count($attention) . ')' : '' ?></h3>
      </div>
      <?php if (!$attention): ?>
        <p class="ad-clear">✅ All clear. Nothing needs your attention right now.</p>
      <?php else: ?>
      <ul class="ad-attn">
        <?php foreach ($attention as $it): ?>
        <li class="ad-attn-row ad-<?= $h($it['tone']) ?>">
          <span class="ad-attn-dot" aria-hidden="true"></span>
          <div class="ad-attn-text">
            <strong><?= $h($it['title']) ?></strong>
            <span><?= $h($it['detail']) ?></span>
          </div>
          <a class="ad-btn<?= $it['tone'] === 'urgent' ? ' ad-btn-solid' : '' ?>" href="<?= $h($it['href']) ?>"><?= $h($it['cta']) ?> →</a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head">
        <h3>Audits across all cities</h3>
      </div>
      <div class="ad-scroll">
        <table class="ad-table">
          <thead><tr><th>Audit</th><th>City</th><th>Status</th><th>Progress</th><th>Last activity</th><th></th></tr></thead>
          <tbody>
          <?php if (!$audits): ?>
            <tr><td colspan="6" class="ad-empty">No audits yet. City Leaders create audits from their own dashboard.</td></tr>
          <?php endif; ?>
          <?php foreach ($audits as $a):
              $p = cityDashProgress($a['done_count'], $a['segment_count']); ?>
            <tr>
              <td><strong><?= $h($a['name']) ?></strong><span class="ad-sub"><?= $h(cityAuditDateLabel($a['audit_date'] ?? null, (int)$a['audit_year'])) ?> · <?= $h(cityPlural($a['road_count'], 'road', 'roads')) ?></span></td>
              <td><?= $h($a['city_name']) ?></td>
              <td><span class="ad-chip ad-chip-<?= $h(adminAuditStatusClass((string)$a['status'])) ?>"><?= $h(adminAuditStatusLabel((string)$a['status'])) ?></span></td>
              <td>
                <?php if ($a['segment_count'] > 0): ?>
                <div class="ad-bar" role="img" aria-label="<?= $p ?>% of segments done"><i style="width:<?= $p ?>%"></i></div>
                <span class="ad-sub"><?= (int)$a['done_count'] ?> of <?= (int)$a['segment_count'] ?> segments</span>
                <?php else: ?><span class="ad-sub">No segments yet</span><?php endif; ?>
              </td>
              <td class="ad-nowrap"><?= $h(cityLocalTime($a['last_activity'])) ?></td>
              <td><a class="ad-link" href="<?= $h(adminAuditLink($a)) ?>"><?= $a['status'] === 'awaiting_approval' ? 'Review' : 'Open' ?> →</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <h3>Cities</h3>
        <a href="admin_surveyors.php">Manage users →</a>
      </div>
      <div class="ad-scroll">
        <table class="ad-table">
          <thead><tr><th>City</th><th>City Leaders</th><th>Surveyors</th><th>Roads</th><th>Segments done</th><th></th></tr></thead>
          <tbody>
          <?php if (!$cities): ?>
            <tr><td colspan="6" class="ad-empty">No cities yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($cities as $c): ?>
            <tr>
              <td><strong><?= $h($c['name']) ?></strong></td>
              <td><?= $c['leaders'] > 0 ? (int)$c['leaders'] : '<span class="ad-warn-text">None</span>' ?></td>
              <td><?= (int)$c['surveyors'] ?></td>
              <td><?= (int)$c['road_groups'] ?></td>
              <td><?= $h($pct($c['done'], $c['segs'])) ?> <span class="ad-sub"><?= (int)$c['done'] ?> of <?= (int)$c['segs'] ?></span></td>
              <td><a class="ad-link" href="city_dashboard.php?city_id=<?= (int)$c['id'] ?>">Open →</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<div class="sb-overlay" id="sb-overlay"></div>
<script nonce="<?= $nonce ?>">
const tog = document.getElementById('sb-toggle'), ovl = document.getElementById('sb-overlay'), aside = document.querySelector('aside');
tog.addEventListener('click', () => { aside.classList.add('open'); ovl.classList.add('show'); });
ovl.addEventListener('click', () => { aside.classList.remove('open'); ovl.classList.remove('show'); });
</script>
</body>
</html>
