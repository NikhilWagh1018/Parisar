<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/my_audits.php
//  Personal audit history page.
//  Section 1 (this delivery): header/summary strip only, backed by
//  api/user/audit_history.php. Filters, "continue where you left
//  off," and the main audit list are added in later sections.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/constants.php';

$initials = strtoupper(substr($CURRENT_USER_NAME, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
  <link rel="stylesheet" href="../css/theme.css">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Audits — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>">
</head>
<body>

<!-- SIDEBAR (matches dashboard.php post-Session-27 nav; no dead Reports links) -->
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
    <a class="nav-item active" href="my_audits.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      My Audits
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
      <h1>My Audits</h1>
      <p>Your personal contribution history.</p>
    </div>
  </div>

  <div class="content">

    <!-- ═══════════ SECTION 1: SUMMARY STRIP ═══════════ -->
    <div class="stat-grid" id="myAuditsStatGrid">
      <div class="stat-card"><div class="stat-icon" style="background:#dbeafe">📍</div><div><div class="stat-val" id="ma-segments">—</div><div class="stat-lbl">Segments Audited</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#edf7d6">📏</div><div><div class="stat-val" id="ma-distance">—</div><div class="stat-lbl">Distance Covered</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef3c7">🛣️</div><div><div class="stat-val" id="ma-roads">—</div><div class="stat-lbl">Roads Touched</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dcfce7">🗓️</div><div><div class="stat-val" id="ma-since">—</div><div class="stat-lbl">Member Since</div></div></div>
    </div>

    <!-- Sections 2–4 (filters, continue-where-left-off, main list) land in later deliveries -->

  </div>
</main>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
function toggleUserMenu() {
  document.getElementById('sbPopup').classList.toggle('open');
}

async function loadMyAuditStats() {
  try {
    const res  = await fetch('../api/user/audit_history.php', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();

    if (!data.success) {
      console.error('Failed to load audit history:', data.error);
      return;
    }

    const s = data.stats;
    document.getElementById('ma-segments').textContent = s.segments_audited;
    document.getElementById('ma-distance').textContent = s.total_length_km + ' km';
    document.getElementById('ma-roads').textContent    = s.roads_touched;
    document.getElementById('ma-since').textContent    = s.member_since
      ? new Date(s.member_since).toLocaleDateString('en-IN', { month: 'short', year: 'numeric' })
      : '—';
  } catch (err) {
    console.error('Error loading audit history stats:', err);
  }
}

loadMyAuditStats();
</script>

</body>
</html>
