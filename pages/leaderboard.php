<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/leaderboard.php
//  Leaderboard — Visibility & Motivation roadmap item #3.
//  Ranks surveyors by segments audited + distance covered, either
//  this ISO week or all-time. Data via api/leaderboard/data.php.
//  Current-streak is shown on the Dashboard instead (personal
//  motivation stat, not a competitive ranking column here).
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/permissions.php';
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
<title>Leaderboard — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link nonce="<?= $nonce ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>">
<link nonce="<?= $nonce ?>" rel="stylesheet" href="../css/leaderboard.css?v=<?= filemtime(__DIR__ . '/../css/leaderboard.css') ?>">
</head>
<body>

<!-- SIDEBAR (matches dashboard.php / my_audits.php / map.php nav) -->
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
    <a class="nav-item" href="map.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 6v16l7-4 8 4 7-4V2l-7 4-8-4-7 4z"/><path d="M8 2v16"/><path d="M16 6v16"/></svg>
      Map View
    </a>
    <a class="nav-item active" href="leaderboard.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
      Leaderboard
    </a>
    <?php if (isAnyAdmin($CURRENT_USER_ROLE)): ?>
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
      <h1>Leaderboard</h1>
      <p>See how your audit work stacks up against the rest of the team.</p>
    </div>
  </div>

  <div class="content">
    <div class="card" id="lb-card">
      <div class="lb-toolbar">
        <div class="lb-window-toggle" role="group" aria-label="Leaderboard window">
          <button type="button" class="lb-window-btn active" data-window="week">This Week</button>
          <button type="button" class="lb-window-btn" data-window="all">All Time</button>
        </div>
      </div>

      <div id="lb-your-rank"></div>

      <div id="lb-table-wrap">
        <table class="lb-table">
          <thead>
            <tr>
              <th class="lb-col-rank">Rank</th>
              <th>Surveyor</th>
              <th class="lb-col-num">Segments</th>
              <th class="lb-col-num">Distance</th>
            </tr>
          </thead>
          <tbody id="lb-tbody">
            <tr><td colspan="4"><div class="skeleton" style="height:18px;width:100%"></div></td></tr>
          </tbody>
        </table>
      </div>

      <div id="lb-empty-state" style="display:none">
        No audits yet this week — be the first on the board!
      </div>
    </div>
  </div>
</main>

<div class="sb-overlay" id="sb-overlay"></div>

<script nonce="<?= $nonce ?>" src="../js/leaderboard.js?v=<?= filemtime(__DIR__ . '/../js/leaderboard.js') ?>"></script>
<script nonce="<?= $nonce ?>">
// Shared sidebar user-menu + mobile-drawer toggle (matches dashboard.php / my_audits.php / map.php)
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
