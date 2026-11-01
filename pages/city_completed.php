<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/city_completed.php  —  Completed audits (sidebar page)
//  The finished (published) audits of the City Leader's own projects, newest
//  first, with a link to each report. The dashboard only shows work in progress.
//  city_leader sees their own; admin can open a city with ?city_id=N (read only).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../helpers/AdminDashboard.php';
require_once __DIR__ . '/../helpers/Cities.php';
require_once __DIR__ . '/../repositories/CityDashboardRepository.php';
require_once __DIR__ . '/partials/cx_icons.php';

$isNational = $CURRENT_USER_ROLE === 'admin';
$cityId     = $isNational ? (int)($_GET['city_id'] ?? 0) : (int)($CURRENT_USER_CITY_ID ?? 0);

if ($isNational && $cityId <= 0) {
    header('Location: published_audits.php');
    exit;
}
if (!$isNational && $CURRENT_USER_ROLE !== 'city_leader') {
    header('Location: ' . roleHomePage($CURRENT_USER_ROLE));
    exit;
}

$city = null;
if ($cityId > 0) {
    $s = $pdo->prepare('SELECT id, name FROM cities WHERE id = ?');
    $s->execute([$cityId]);
    $city = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

$all       = $city ? (new CityDashboardRepository($pdo))->auditSummaries((int)$city['id'], $isNational ? null : (int)$CURRENT_USER_ID) : [];
$completed = citySplitAudits($all)['completed'];
$openCount = count(citySplitAudits($all)['open']);

$h         = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce     = $h($_SESSION['csp_nonce'] ?? '');
$activeNav = 'completed';
$cityName  = $city ? (string)$city['name'] : 'No city assigned';
$backUrl   = $isNational && $city ? 'city_dashboard.php?city_id=' . (int)$city['id'] : 'city_dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/theme.css">
<title>Completed audits — CycleAudit</title>
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
      <h1>Completed audits</h1>
      <p><?= $isNational ? $h($cityName) . ' · <a href="' . $h($backUrl) . '">← City dashboard</a>' : $h($cityName) . ' · finished and published' ?></p>
    </div>
  </div>
  <div class="content cx-page">
  <?php if (!$city): ?>
    <div class="card"><p class="rd-empty">No city is assigned to your account yet. Please ask an Admin to assign one.</p></div>
  <?php else: ?>
    <div class="card">
      <div class="card-head">
        <h3>Published audits<?= $completed ? ' (' . count($completed) . ')' : '' ?></h3>
        <a href="<?= $h($backUrl) ?>"><?= $openCount > 0 ? $h($openCount . ' open on the dashboard →') : '← Dashboard' ?></a>
      </div>
      <?php if (!$completed): ?>
        <div class="cx-empty"><?= cxIcon('check') ?><b>Nothing completed yet</b>When the Admin publishes one of your audits it moves here, and leaves your dashboard.</div>
      <?php else: ?>
      <p class="cc-intro">These audits are final. Open the report any time.</p>
      <div class="pd-scroll">
        <table class="pd-table">
          <thead><tr><th>Audit</th><th>Date</th><th>Roads</th><th>Segments</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($completed as $a): ?>
            <tr>
              <td><strong><?= $h($a['name']) ?></strong><span class="pd-sub"><?= $h($a['state']) ?></span></td>
              <td class="pd-nowrap"><?= $h(cityAuditDateLabel($a['audit_date'] ?? null, (int)$a['audit_year'])) ?></td>
              <td><?= (int)$a['road_count'] ?></td>
              <td><?= (int)$a['approved_count'] ?> of <?= (int)$a['segment_count'] ?> <span class="pd-sub">approved</span></td>
              <td><span class="pd-chip pd-chip-<?= $h(adminAuditStatusClass('published')) ?>"><?= $h(adminAuditStatusLabel('published')) ?></span></td>
              <td class="pd-nowrap"><a class="pd-link" href="city_audit_report.php?id=<?= (int)$a['id'] ?>">View report →</a> · <a class="pd-link" href="city_audit.php?id=<?= (int)$a['id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
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
