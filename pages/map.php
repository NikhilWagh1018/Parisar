<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/map.php
//  Map View — Visibility & Motivation roadmap item #2.
//  Renders the logged-in user's GPS-tagged audited segments as
//  color-coded pins (green = completed, amber = pending) on a
//  Leaflet + OpenStreetMap map. Data via api/segments/map-data.php.
//  Leaflet is vendored locally (css/leaflet, js/leaflet — note: NOT under a "vendor" name, since .gitignore blanket-ignores any vendor/ dir (meant for Composer))
//  rather than loaded from a CDN, since the app's CSP script-src is
//  'self' only. Map tile images come from OSM's tile servers, so
//  img-src in config/auth_guard.php was widened for those domains.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/constants.php';

$initials = strtoupper(substr($CURRENT_USER_NAME, 0, 1));
$nonce    = htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
  <link rel="stylesheet" href="../css/theme.css">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Map View — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link nonce="<?= $nonce ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/leaflet/leaflet.css">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/map.css?v=<?= filemtime(__DIR__ . '/../css/map.css') ?>">
</head>
<body>

<!-- SIDEBAR (matches dashboard.php / my_audits.php nav) -->
<aside>
  <div class="sb-brand">
    <img src="../assets/parisar-logo.png" alt="Parisar" style="height:22px;width:auto;filter:brightness(0) invert(1);opacity:.85;flex-shrink:0;">
    <span style="width:1px;height:18px;background:rgba(255,255,255,.15);display:inline-block;flex-shrink:0;"></span>
    CycleAudit
  </div>

  <nav>
    <div class="nav-section">Main</div>
    <a class="nav-item" href="dashboard.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <a class="nav-item" href="my_audits.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      My Audits
    </a>
    <a class="nav-item active" href="map.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 6v16l7-4 8 4 7-4V2l-7 4-8-4-7 4z"/><path d="M8 2v16"/><path d="M16 6v16"/></svg>
      Map View
    </a>
    <?php if ($CURRENT_USER_ROLE === 'admin'): ?>
    <div class="nav-section">Admin</div>
    <a class="nav-item" href="admin.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 1-1.5 3-9 3s-9-2-9-3 1.5-3 9-3 9 2 9 3z"/></svg>
      Roads
    </a>
    <a class="nav-item" href="admin_surveyors.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Users
    </a>
    <a class="nav-item" href="admin_activity.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Activity Log
    </a>
    <?php endif; ?>
  </nav>

  <div class="sb-user">
    <div class="sb-popup" id="sbPopup">
      <div class="popup-header">
        <div class="popup-avatar" id="popupAv">
          <?php if ($CURRENT_USER_PIC): ?>
            <img src="<?= htmlspecialchars($CURRENT_USER_PIC) ?>" alt="">
          <?php else: ?>
            <?= $initials ?>
          <?php endif; ?>
        </div>
        <div style="min-width:0">
          <div class="popup-uname"><?= htmlspecialchars($CURRENT_USER_NAME) ?></div>
          <div class="popup-urole"><?= htmlspecialchars($CURRENT_USER_ROLE) ?></div>
        </div>
      </div>
      <div class="popup-menu">
        <a class="popup-item" href="profile.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          My Profile
        </a>
        <a class="popup-item" href="profile.php#tab-account">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Change Password
        </a>
        <div class="popup-divider"></div>
        <a class="popup-item danger" href="../auth/logout.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Sign Out
        </a>
      </div>
    </div>

    <button class="sb-user-btn" id="sbUserBtn" onclick="toggleUserMenu()">
      <div class="sb-avatar" id="sb-av">
        <?php if ($CURRENT_USER_PIC): ?>
          <img src="<?= htmlspecialchars($CURRENT_USER_PIC) ?>" alt="">
        <?php else: ?>
          <?= $initials ?>
        <?php endif; ?>
      </div>
      <div class="sb-uinfo">
        <div class="sb-uname"><?= htmlspecialchars($CURRENT_USER_NAME) ?></div>
        <div class="sb-urole"><?= htmlspecialchars($CURRENT_USER_ROLE) ?></div>
      </div>
      <svg class="sb-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <polyline points="18 15 12 9 6 15"/>
      </svg>
    </button>
  </div>
</aside>

<!-- MAIN -->
<main>
  <div class="topbar">
    <button class="sb-hamburger" id="sb-toggle" aria-label="Menu">&#9776;</button>
    <div class="topbar-left">
      <h1>Map View</h1>
      <p>Every road you've audited, plotted where you captured it.</p>
    </div>
  </div>

  <div class="content">
    <div class="card" id="map-card">
      <div class="map-toolbar">
        <div class="map-scope-toggle" role="group" aria-label="Map scope">
          <button type="button" class="map-scope-btn active" data-scope="mine">My Audits</button>
          <button type="button" class="map-scope-btn" data-scope="all">All Audits</button>
        </div>
      </div>
      <div id="map-canvas"></div>
      <div id="map-empty-state">
        No GPS-tagged segments yet — audit a road with GPS capture on the
        form to see it appear here.
      </div>
      <div class="map-legend">
        <div class="map-legend-item"><span class="map-legend-dot completed"></span>Completed</div>
        <div class="map-legend-item"><span class="map-legend-dot pending"></span>Pending</div>
      </div>
    </div>
  </div>
</main>

<div class="sb-overlay" id="sb-overlay"></div>

<script nonce="<?= $nonce ?>" src="../js/leaflet/leaflet.js"></script>
<script nonce="<?= $nonce ?>" src="../js/map.js?v=<?= filemtime(__DIR__ . '/../js/map.js') ?>"></script>
<script nonce="<?= $nonce ?>">
// Shared sidebar user-menu + mobile-drawer toggle (matches dashboard.php / my_audits.php)
function toggleUserMenu() {
  const popup = document.getElementById('sbPopup');
  const btn   = document.getElementById('sbUserBtn');
  const open  = popup.classList.toggle('show');
  btn.classList.toggle('open', open);
}
document.addEventListener('click', e => {
  const popup = document.getElementById('sbPopup');
  if (!popup.classList.contains('show')) return;
  if (!document.getElementById('sbUserBtn').contains(e.target) &&
      !popup.contains(e.target)) {
    popup.classList.remove('show');
    document.getElementById('sbUserBtn').classList.remove('open');
  }
});

const tog = document.getElementById("sb-toggle");
const ovl = document.getElementById("sb-overlay");
const aside = document.querySelector("aside");
function openSb(){ aside.classList.add("open"); ovl.classList.add("show"); }
function closeSb(){ aside.classList.remove("open"); ovl.classList.remove("show"); }
tog.addEventListener("click", openSb);
ovl.addEventListener("click", closeSb);
</script>
</body>
</html>
