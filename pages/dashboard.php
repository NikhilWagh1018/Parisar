<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/dashboard.php
//  All data comes from api/dashboard/stats.php via a single fetch.
//  No inline scoring — ScoreService handles that via the API.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/constants.php';

$hour     = (int)date('H');
$greet    = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$initials = strtoupper(substr($CURRENT_USER_NAME, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/dashboard.css">
</head>
<body>

<!-- SIDEBAR -->
<aside>
  <div class="sb-brand">
    <img src="../assets/parisar-logo.png" alt="Parisar" style="height:22px;width:auto;filter:brightness(0) invert(1);opacity:.85;flex-shrink:0;">
    <span style="width:1px;height:18px;background:rgba(255,255,255,.15);display:inline-block;flex-shrink:0;"></span>
    CycleAudit
  </div>

  <nav>
    <div class="nav-section">Main</div>
    <a class="nav-item active" href="dashboard.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <a class="nav-item" href="segment.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
      Road Setup
    </a>
    <div class="nav-section">Reports</div>
    <a class="nav-item" href="road_result.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Road Results
    </a>
    <a class="nav-item" href="view.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
      Segment View
    </a>
  </nav>

  <div class="sb-user">
    <!-- Popup menu -->
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

    <!-- Trigger button -->
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
    <div class="topbar-left">
      <h1><?= $greet ?>, <?= htmlspecialchars(explode(' ', $CURRENT_USER_NAME)[0]) ?>!</h1>
      <p>Here's your audit overview for today.</p>
    </div>
    <a href="segment.php" class="btn-new">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New Road Audit
    </a>
  </div>

  <div class="content">

    <!-- Stat cards — populated by JS -->
    <div class="stat-grid" id="statGrid">
      <div class="stat-card"><div class="stat-icon" style="background:#edf7d6">🛣️</div><div><div class="stat-val" id="st-roads">—</div><div class="stat-lbl">Roads</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dbeafe">📍</div><div><div class="stat-val" id="st-segs">—</div><div class="stat-lbl">Total Segments</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dcfce7">✅</div><div><div class="stat-val" id="st-done">—</div><div class="stat-lbl">Completed</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef9c3">⚡</div><div><div class="stat-val" id="st-active">—</div><div class="stat-lbl">Active Sessions</div></div></div>
    </div>

    <!-- Roads table -->
    <div class="card">
      <div class="card-head">
        <h3>🛣️ Your Roads</h3>
        <a href="segment.php">+ Define new road</a>
      </div>
      <div id="roadsContainer">
        <!-- Skeleton loading -->
        <div style="display:flex;flex-direction:column;gap:14px;padding:8px 0">
          <div class="skeleton" style="height:18px;width:60%"></div>
          <div class="skeleton" style="height:18px;width:80%"></div>
          <div class="skeleton" style="height:18px;width:50%"></div>
        </div>
      </div>
    </div>

  </div>
</main>

<!-- Delete confirmation modal -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <div style="font-size:2rem;margin-bottom:10px">🗑️</div>
    <h4>Delete Road?</h4>
    <p id="deleteModalMsg">This will permanently delete the road and all its segments, sessions and audit data.</p>
    <div class="modal-btns">
      <button class="mbtn-cancel" onclick="closeDeleteModal()">Cancel</button>
      <button class="mbtn-delete" id="confirmDeleteBtn">Delete</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast-wrap" id="toastWrap"></div>

<script>const CSRF = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';</script>
<script src="../js/dashboard.js"></script>
</body>
</html>