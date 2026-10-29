<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/platform_dashboard.php  —  Admin Dashboard (home)
//  national_admin only: what needs attention, every audit, every city.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../helpers/RoleHome.php';
require_once __DIR__ . '/../helpers/AdminDashboard.php';
require_once __DIR__ . '/../helpers/Cities.php';
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
$csrf      = $h($_SESSION['csrf_token'] ?? '');
$today     = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
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
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/city_audit.css?v=<?= filemtime(__DIR__ . '/../css/city_audit.css') ?>">
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
    <button class="btn-new" id="npNewBtn" type="button">+ New Project</button>
  </div>
  <div class="content" id="npApp" data-csrf="<?= $csrf ?>">

    <div class="card" id="npNewCard" style="display:none">
      <div class="card-head"><h3>New project</h3></div>
      <form id="npNewForm" class="ca-form" novalidate>
        <div class="ca-field">
          <label for="npCity">City *</label>
          <select id="npCity" name="city_id">
            <option value="">— Select a city —</option>
            <?php foreach ($cities as $c): ?>
              <option value="<?= (int)$c['id'] ?>" data-state="<?= $h(cityStateFor((string)$c['name']) ?? '') ?>" data-leaders="<?= (int)$c['leaders'] ?>"><?= $h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="ca-err"></div>
          <div class="ca-hint" id="npNoLeader" style="display:none;color:#b45309">This city has no City Leader yet. Add one under Users, or nobody can add its roads.</div>
        </div>
        <div class="ca-field">
          <label for="npState">State *</label>
          <input id="npState" name="state" type="text" maxlength="100" placeholder="Fills in from the city">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="npName">Project name *</label>
          <input id="npName" name="name" type="text" maxlength="150" placeholder="e.g. Pune Cycle Infrastructure Audit">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field">
          <label for="npDate">Audit date *</label>
          <input id="npDate" name="audit_date" type="date" min="2000-01-01" max="2100-12-31" value="<?= $h($today) ?>">
          <div class="ca-err"></div>
        </div>
        <div class="ca-field full">
          <label for="npInfo">Programme info</label>
          <textarea id="npInfo" name="programme_info" maxlength="2000" placeholder="Programme, funder or scope notes (optional)"></textarea>
          <div class="ca-err"></div>
        </div>
        <div class="ca-actions full">
          <button class="ca-btn" type="submit">Create project</button>
          <button class="ca-btn ghost" type="button" id="npNewCancel">Cancel</button>
        </div>
      </form>
    </div>

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
        <p class="pd-clear">✅ All clear. Nothing needs your attention right now.</p>
      <?php else: ?>
      <ul class="pd-attn">
        <?php foreach ($attention as $it): ?>
        <li class="pd-attn-row pd-<?= $h($it['tone']) ?>">
          <span class="pd-attn-dot" aria-hidden="true"></span>
          <div class="pd-attn-text">
            <strong><?= $h($it['title']) ?></strong>
            <span><?= $h($it['detail']) ?></span>
          </div>
          <a class="pd-btn<?= $it['tone'] === 'urgent' ? ' pd-btn-solid' : '' ?>" href="<?= $h($it['href']) ?>"><?= $h($it['cta']) ?> →</a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head">
        <h3>Audits across all cities</h3>
      </div>
      <div class="pd-scroll">
        <table class="pd-table">
          <thead><tr><th>Audit</th><th>City</th><th>Status</th><th>Progress</th><th>Last activity</th><th></th></tr></thead>
          <tbody>
          <?php if (!$audits): ?>
            <tr><td colspan="6" class="pd-empty">No audits yet. Use “+ New Project” to create the first one; the City Leader then adds its roads.</td></tr>
          <?php endif; ?>
          <?php foreach ($audits as $a):
              $p = cityDashProgress($a['done_count'], $a['segment_count']); ?>
            <tr>
              <td><strong><?= $h($a['name']) ?></strong><span class="pd-sub"><?= $h(cityAuditDateLabel($a['audit_date'] ?? null, (int)$a['audit_year'])) ?> · <?= $h(cityPlural($a['road_count'], 'road', 'roads')) ?></span></td>
              <td><?= $h($a['city_name']) ?></td>
              <td><span class="pd-chip pd-chip-<?= $h(adminAuditStatusClass((string)$a['status'])) ?>"><?= $h(adminAuditStatusLabel((string)$a['status'])) ?></span></td>
              <td>
                <?php if ($a['segment_count'] > 0): ?>
                <div class="pd-bar" role="img" aria-label="<?= $p ?>% of segments done"><i style="width:<?= $p ?>%"></i></div>
                <span class="pd-sub"><?= (int)$a['done_count'] ?> of <?= (int)$a['segment_count'] ?> segments</span>
                <?php else: ?><span class="pd-sub">No segments yet</span><?php endif; ?>
              </td>
              <td class="pd-nowrap"><?= $h(cityLocalTime($a['last_activity'])) ?></td>
              <td><a class="pd-link" href="<?= $h(adminAuditLink($a)) ?>"><?= $a['status'] === 'awaiting_approval' ? 'Review' : 'Open' ?> →</a></td>
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
      <div class="pd-scroll">
        <table class="pd-table">
          <thead><tr><th>City</th><th>City Leaders</th><th>Surveyors</th><th>Roads</th><th>Segments done</th><th></th></tr></thead>
          <tbody>
          <?php if (!$cities): ?>
            <tr><td colspan="6" class="pd-empty">No cities yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($cities as $c): ?>
            <tr>
              <td><strong><?= $h($c['name']) ?></strong></td>
              <td><?= $c['leaders'] > 0 ? (int)$c['leaders'] : '<span class="pd-warn-text">None</span>' ?></td>
              <td><?= (int)$c['surveyors'] ?></td>
              <td><?= (int)$c['road_groups'] ?></td>
              <td><?= $h($pct($c['done'], $c['segs'])) ?> <span class="pd-sub"><?= (int)$c['done'] ?> of <?= (int)$c['segs'] ?></span></td>
              <td><a class="pd-link" href="city_dashboard.php?city_id=<?= (int)$c['id'] ?>">Open →</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<div class="sb-overlay" id="sb-overlay"></div>
<div class="ca-toast" id="caToast"></div>
<script nonce="<?= $nonce ?>" src="../js/admin_new_project.js?v=<?= filemtime(__DIR__ . '/../js/admin_new_project.js') ?>"></script>
<script nonce="<?= $nonce ?>">
const tog = document.getElementById('sb-toggle'), ovl = document.getElementById('sb-overlay'), aside = document.querySelector('aside');
tog.addEventListener('click', () => { aside.classList.add('open'); ovl.classList.add('show'); });
ovl.addEventListener('click', () => { aside.classList.remove('open'); ovl.classList.remove('show'); });
</script>
</body>
</html>
