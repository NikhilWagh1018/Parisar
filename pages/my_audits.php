<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════════════
//  pages/my_audits.php
//  Personal audit history page.
//  Section 1 (this delivery): header/summary strip only, backed by
//  api/user/audit_history.php. Filters, "continue where you left
//  off," and the main audit list are added in later sections.
// ════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/permissions.php';
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
<style nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  /* Scoped to my_audits.php only — filter bar + pagination controls */
  .ma-select {
    padding: 8px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-family: inherit;
    font-size: 14px;
    background: #fff;
    color: #1f2937;
    cursor: pointer;
  }
  .ma-page-btn {
    padding: 6px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #fff;
    color: #3d7a1f;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
  }
  .ma-page-btn:disabled {
    color: #cbd5e1;
    cursor: not-allowed;
  }
  /* Section 3 — "Continue where you left off" callout */
  .ma-continue-card {
    background: #fef9ec;
    border: 1px solid #f5e3b3;
    border-radius: 12px;
    padding: 16px 20px;
    margin-top: 20px;
  }
  .ma-continue-title {
    font-size: 13px;
    font-weight: 700;
    color: #92702c;
    text-transform: uppercase;
    letter-spacing: .03em;
    margin-bottom: 12px;
  }
  .ma-continue-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 10px 0;
    border-top: 1px solid #f2e6c2;
  }
  .ma-continue-row:first-of-type {
    border-top: none;
    padding-top: 0;
  }
  .ma-continue-progress-track {
    background: #f2e6c2;
    border-radius: 6px;
    height: 6px;
    width: 140px;
    overflow: hidden;
  }
  .ma-continue-progress-fill {
    background: #d97706;
    height: 100%;
    border-radius: 6px;
  }
  .ma-resume-btn {
    background: #3d7a1f;
    color: #fff;
    font-weight: 600;
    font-size: 13px;
    padding: 6px 16px;
    border-radius: 8px;
    text-decoration: none;
    white-space: nowrap;
  }
  /* Section 5 — .empty-state a's default color (var(--g), green) is a
     descendant selector and out-specifies .btn-new's own color:#fff,
     so without this override the CTA text would render green on the
     button's green background. */
  #ma-empty-state a.btn-new {
    color: #fff;
  }
  /* Reporting roadmap item 2 — before/after comparison panel */
  .ma-compare-link {
    color: #3d7a1f;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    font-family: inherit;
  }
  .ma-compare-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, .55);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  .ma-compare-overlay.show {
    display: flex;
  }
  .ma-compare-modal {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 640px;
    max-height: 85vh;
    overflow-y: auto;
    padding: 24px 28px;
    position: relative;
  }
  .ma-compare-close {
    position: absolute;
    top: 16px;
    right: 18px;
    background: none;
    border: none;
    font-size: 22px;
    line-height: 1;
    color: #9ca3af;
    cursor: pointer;
  }
  .ma-compare-title {
    font-size: 18px;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 4px;
    padding-right: 24px;
  }
  .ma-compare-sub {
    font-size: 13px;
    color: #6b7280;
    margin: 0 0 18px;
  }
  .ma-compare-scoreband {
    display: flex;
    align-items: center;
    gap: 16px;
    background: #f9fafb;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 18px;
  }
  .ma-compare-score-block {
    flex: 1;
    text-align: center;
  }
  .ma-compare-score-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .03em;
    color: #9ca3af;
    font-weight: 700;
    margin-bottom: 4px;
  }
  .ma-compare-score-val {
    font-size: 20px;
    font-weight: 700;
    color: #1f2937;
  }
  .ma-compare-arrow {
    color: #9ca3af;
    font-size: 18px;
  }
  .ma-compare-delta {
    font-size: 13px;
    font-weight: 700;
    margin-top: 2px;
  }
  .ma-compare-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-top: 1px solid #f0f0f0;
    font-size: 13px;
    gap: 12px;
  }
  .ma-compare-row:first-of-type {
    border-top: none;
  }
  .ma-compare-row-label {
    color: #6b7280;
    font-weight: 600;
    flex: 0 0 130px;
  }
  .ma-compare-row-vals {
    flex: 1;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    text-align: right;
  }
  .ma-compare-unchanged {
    color: #9ca3af;
  }
  .ma-compare-changed {
    color: #1f2937;
    font-weight: 600;
  }
</style>
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
    <a class="nav-item" href="map.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 6v16l7-4 8 4 7-4V2l-7 4-8-4-7 4z"/><path d="M8 2v16"/><path d="M16 6v16"/></svg>
      Map View
    </a>
    <a class="nav-item" href="leaderboard.php">
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

    <!-- ═══════════ SECTION 3: CONTINUE WHERE YOU LEFT OFF ═══════════ -->
    <!-- Hidden by default; JS reveals it only if the user has roads to resume. -->
    <div class="ma-continue-card" id="ma-continue-card" style="display:none;">
      <div class="ma-continue-title">Continue where you left off</div>
      <div id="ma-continue-body"></div>
    </div>

    <!-- ═══════════ SECTION 2: FILTER & SORT BAR ═══════════ -->
    <div class="card" id="ma-filterbar" style="margin-top:20px;padding:16px 20px;">
      <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;">
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;">
          <div style="display:flex;flex-direction:column;gap:4px;">
            <label for="ma-filter-status" style="font-size:12px;font-weight:600;color:#6b7280;">Status</label>
            <select id="ma-filter-status" class="ma-select">
              <option value="all">All</option>
              <option value="active">Active</option>
              <option value="completed">Completed</option>
            </select>
          </div>
          <div style="display:flex;flex-direction:column;gap:4px;">
            <label for="ma-filter-range" style="font-size:12px;font-weight:600;color:#6b7280;">Date range</label>
            <select id="ma-filter-range" class="ma-select">
              <option value="all">All time</option>
              <option value="week">This week</option>
              <option value="month">This month</option>
            </select>
          </div>
          <div style="display:flex;flex-direction:column;gap:4px;">
            <label for="ma-sort" style="font-size:12px;font-weight:600;color:#6b7280;">Sort by</label>
            <select id="ma-sort" class="ma-select">
              <option value="recent">Most recent</option>
              <option value="name">Road name (A–Z)</option>
              <option value="score">Condition (worst first)</option>
            </select>
          </div>
        </div>
        <button id="ma-export-btn" class="ma-page-btn" type="button" style="display:flex;align-items:center;gap:6px;white-space:nowrap;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Export to Excel
        </button>
      </div>
    </div>

    <!-- ═══════════ SECTION 4: AUDIT LIST ═══════════ -->
    <div class="card" style="margin-top:16px;" id="ma-list-card">
      <div id="ma-list-body">
        <p style="padding:24px;color:#6b7280;">Loading your audits…</p>
      </div>
      <div id="ma-pagination" style="display:flex;justify-content:center;gap:12px;padding:16px;align-items:center;"></div>
    </div>

    <!-- ═══════════ SECTION 5: EMPTY STATE (zero audits ever) ═══════════ -->
    <!-- Hidden by default. Shown only when the user has no completed
         audits AND nothing to resume — distinct from the "no audits
         match these filters yet" message inside Section 4, which
         handles the filtered-empty case for users who do have audits. -->
    <div class="card empty-state" id="ma-empty-state" style="display:none;margin-top:16px;">
      <div class="empty-icon">🚴</div>
      <p><strong>No audits yet</strong></p>
      <p>Once you audit your first road segment, it'll show up here — along with your progress and history.</p>
      <a href="segment.php" class="btn-new" style="margin-top:14px;">+ Start your first audit</a>
    </div>

  </div>
</main>

<!-- ═══════════ Reporting roadmap item 2: before/after comparison modal ═══════════ -->
<div class="ma-compare-overlay" id="ma-compare-overlay">
  <div class="ma-compare-modal">
    <button class="ma-compare-close" id="ma-compare-close" aria-label="Close">&times;</button>
    <div id="ma-compare-body">
      <p style="padding:24px 0;color:#6b7280;">Loading comparison…</p>
    </div>
  </div>
</div>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
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

async function loadMyAuditStats() {
  try {
    const res  = await fetch('../api/user/audit_history.php', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();

    if (!data.success) {
      console.error('Failed to load audit history:', data.error);
      return true; // unknown — fail open, don't claim "zero audits"
    }

    const s = data.stats;
    document.getElementById('ma-segments').textContent = s.segments_audited;
    document.getElementById('ma-distance').textContent = s.total_length_km + ' km';
    document.getElementById('ma-roads').textContent    = s.roads_touched;
    document.getElementById('ma-since').textContent    = s.member_since
      ? new Date(s.member_since).toLocaleDateString('en-IN', { month: 'short', year: 'numeric' })
      : '—';

    return s.segments_audited > 0;
  } catch (err) {
    console.error('Error loading audit history stats:', err);
    return true; // unknown — fail open, don't claim "zero audits"
  }
}

// ── Section 3: "Continue where you left off" callout ─────────────────
// Returns true if it rendered at least one resumable road. A user can
// have an active session with pending segments but zero *completed*
// audits yet (segments_audited === 0), so this is checked separately
// from the stats call before Section 5's empty state is decided.
async function loadMyAuditContinue() {
  const card = document.getElementById('ma-continue-card');
  const body = document.getElementById('ma-continue-body');

  try {
    const res  = await fetch('../api/user/audit_continue.php', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();

    if (!data.success || !data.items || data.items.length === 0) {
      card.style.display = 'none';
      return false;
    }

    body.innerHTML = data.items.map(function (item) {
      const pct = item.total_segments > 0
        ? Math.round((item.completed_segments / item.total_segments) * 100)
        : 0;

      return (
        '<div class="ma-continue-row">' +
          '<div>' +
            '<div style="font-weight:600;">' + item.road_name + '</div>' +
            '<div style="font-size:13px;color:#92702c;margin-top:4px;display:flex;align-items:center;gap:8px;">' +
              '<span>' + item.completed_segments + ' of ' + item.total_segments + ' segments done</span>' +
              '<span class="ma-continue-progress-track"><span class="ma-continue-progress-fill" style="width:' + pct + '%;"></span></span>' +
            '</div>' +
          '</div>' +
          '<a class="ma-resume-btn" href="form.php?segment_id=' + item.next_segment_id + '">Resume →</a>' +
        '</div>'
      );
    }).join('');

    card.style.display = 'block';
    return true;
  } catch (err) {
    console.error('Error loading continue-audits data:', err);
    card.style.display = 'none';
    return true; // unknown — fail open, don't claim "zero audits"
  }
}

// ── Section 2+4: filter/sort bar + audit list ─────────────────────────
let maCurrentPage = 1;

function maConditionColor(condition) {
  switch (condition) {
    case 'Good':     return '#27ae60';
    case 'OK':       return '#f1c40f';
    case 'Poor':     return '#e67e22';
    case 'Bad':      return '#e74c3c';
    case 'Very Bad': return '#8e1010';
    default:         return '#95a5a6';
  }
}

function maFormatDate(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}

async function loadMyAuditList(page) {
  maCurrentPage = page || 1;

  const status = document.getElementById('ma-filter-status').value;
  const range  = document.getElementById('ma-filter-range').value;
  const sort   = document.getElementById('ma-sort').value;

  const listBody = document.getElementById('ma-list-body');
  listBody.innerHTML = '<p style="padding:24px;color:#6b7280;">Loading your audits…</p>';

  try {
    const params = new URLSearchParams({ status, range, sort, page: String(maCurrentPage) });
    const res  = await fetch('../api/user/audit_history_list.php?' + params.toString(), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();

    if (!data.success) {
      listBody.innerHTML = '<p style="padding:24px;color:#e74c3c;">Could not load your audits. Please try again.</p>';
      document.getElementById('ma-pagination').innerHTML = '';
      return;
    }

    if (data.items.length === 0) {
      listBody.innerHTML = '<p style="padding:24px;color:#6b7280;">No audits match these filters yet.</p>';
      document.getElementById('ma-pagination').innerHTML = '';
      return;
    }

    listBody.innerHTML = data.items.map(function (item) {
      const chipColor = maConditionColor(item.condition);
      const conditionChip = item.condition
        ? '<span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;color:#fff;background:' + chipColor + ';">' + item.condition + '</span>'
        : '<span style="color:#9ca3af;font-size:12px;">—</span>';

      const statusChip = item.session_status === 'active'
        ? '<span style="color:#b45309;font-size:12px;font-weight:600;">Active</span>'
        : '<span style="color:#15803d;font-size:12px;font-weight:600;">Completed</span>';

      // Reporting roadmap item 2: only segments this user has audited
      // 2+ times are eligible for the before/after comparison view.
      const compareLink = (item.audit_count >= 2)
        ? '<button type="button" class="ma-compare-link" onclick="maOpenCompare(' + item.segment_id + ')">Compare →</button>'
        : '';

      return (
        '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #f0f0f0;">' +
          '<div>' +
            '<div style="font-weight:600;">' + item.road_name + ' — Segment ' + item.segment_number + '</div>' +
            '<div style="font-size:13px;color:#6b7280;margin-top:2px;">' +
              maFormatDate(item.created_at) + ' · ' + statusChip +
            '</div>' +
          '</div>' +
          '<div style="display:flex;align-items:center;gap:16px;">' +
            conditionChip +
            compareLink +
            '<a href="view.php?segment_id=' + item.segment_id + '" style="color:#3d7a1f;font-weight:600;font-size:14px;text-decoration:none;">View →</a>' +
          '</div>' +
        '</div>'
      );
    }).join('');

    // Pagination controls
    const pag = document.getElementById('ma-pagination');
    if (data.total_pages <= 1) {
      pag.innerHTML = '';
    } else {
      pag.innerHTML =
        '<button class="ma-page-btn" id="ma-prev" ' + (data.page <= 1 ? 'disabled' : '') + '>← Prev</button>' +
        '<span style="font-size:13px;color:#6b7280;">Page ' + data.page + ' of ' + data.total_pages + '</span>' +
        '<button class="ma-page-btn" id="ma-next" ' + (data.page >= data.total_pages ? 'disabled' : '') + '>Next →</button>';

      const prevBtn = document.getElementById('ma-prev');
      const nextBtn = document.getElementById('ma-next');
      if (prevBtn) prevBtn.onclick = function () { loadMyAuditList(data.page - 1); };
      if (nextBtn) nextBtn.onclick = function () { loadMyAuditList(data.page + 1); };
    }

  } catch (err) {
    console.error('Error loading audit list:', err);
    listBody.innerHTML = '<p style="padding:24px;color:#e74c3c;">Could not load your audits. Please try again.</p>';
  }
}

document.getElementById('ma-filter-status').addEventListener('change', function () { loadMyAuditList(1); });
document.getElementById('ma-filter-range').addEventListener('change', function () { loadMyAuditList(1); });
document.getElementById('ma-sort').addEventListener('change', function () { loadMyAuditList(1); });

// Export respects whatever filters are currently selected — same
// status/range/sort params the on-screen list uses, no page param
// since the export always includes every matching row.
document.getElementById('ma-export-btn').addEventListener('click', function () {
  const status = document.getElementById('ma-filter-status').value;
  const range  = document.getElementById('ma-filter-range').value;
  const sort   = document.getElementById('ma-sort').value;
  const params = new URLSearchParams({ status, range, sort });
  window.location.href = '../api/user/audit_export.php?' + params.toString();
});

// ── Reporting roadmap item 2: before/after comparison modal ──────────
function maFormatScore(v) {
  return (v === null || v === undefined) ? '—' : Math.round(v * 10) / 10;
}

function maCompareRow(label, beforeVal, afterVal) {
  const changed = String(beforeVal) !== String(afterVal);
  const beforeDisplay = '<span class="ma-compare-unchanged">' + (beforeVal ?? '—') + '</span>';
  const afterDisplay = changed
    ? ' → <span class="ma-compare-changed">' + (afterVal ?? '—') + '</span>'
    : '';
  return (
    '<div class="ma-compare-row">' +
      '<div class="ma-compare-row-label">' + label + '</div>' +
      '<div class="ma-compare-row-vals">' + beforeDisplay + afterDisplay + '</div>' +
    '</div>'
  );
}

async function maOpenCompare(segmentId) {
  const overlay = document.getElementById('ma-compare-overlay');
  const body    = document.getElementById('ma-compare-body');
  body.innerHTML = '<p style="padding:24px 0;color:#6b7280;">Loading comparison…</p>';
  overlay.classList.add('show');

  try {
    const res  = await fetch('../api/user/audit_compare.php?segment_id=' + segmentId, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();

    if (!data.success) {
      body.innerHTML = '<p style="padding:24px 0;color:#e74c3c;">' + (data.error || 'Could not load comparison.') + '</p>';
      return;
    }

    const b = data.before;
    const a = data.after;
    const deltaVal = data.score_delta;
    const deltaColor = (deltaVal === null) ? '#6b7280' : (deltaVal > 0 ? '#15803d' : (deltaVal < 0 ? '#e74c3c' : '#6b7280'));
    const deltaLabel = (deltaVal === null) ? '' : (deltaVal > 0 ? '+' + deltaVal + ' pts' : deltaVal + ' pts');

    const beforeObs = b.obstructions.total;
    const afterObs   = a.obstructions.total;

    body.innerHTML =
      '<h2 class="ma-compare-title">' + data.segment.road_name + ' — Segment ' + data.segment.segment_number + '</h2>' +
      '<p class="ma-compare-sub">Comparing your first audit (' + maFormatDate(b.created_at) + ') to your most recent audit (' + maFormatDate(a.created_at) + ')</p>' +
      '<div class="ma-compare-scoreband">' +
        '<div class="ma-compare-score-block">' +
          '<div class="ma-compare-score-label">Before</div>' +
          '<div class="ma-compare-score-val">' + maFormatScore(b.score) + '</div>' +
          '<div style="font-size:12px;color:#6b7280;">' + (b.condition || '—') + '</div>' +
        '</div>' +
        '<div class="ma-compare-arrow">→</div>' +
        '<div class="ma-compare-score-block">' +
          '<div class="ma-compare-score-label">After</div>' +
          '<div class="ma-compare-score-val">' + maFormatScore(a.score) + '</div>' +
          '<div style="font-size:12px;color:#6b7280;">' + (a.condition || '—') + '</div>' +
        '</div>' +
        '<div class="ma-compare-score-block">' +
          '<div class="ma-compare-score-label">Change</div>' +
          '<div class="ma-compare-delta" style="color:' + deltaColor + ';">' + deltaLabel + '</div>' +
        '</div>' +
      '</div>' +
      maCompareRow('Buffer zone', b.buffer_zone, a.buffer_zone) +
      maCompareRow('Surface', b.surface_material, a.surface_material) +
      maCompareRow('Shade', b.shade, a.shade) +
      maCompareRow('Lit after dark', b.light_after_sunset, a.light_after_sunset) +
      maCompareRow('Segment width (m)', b.segment_width, a.segment_width) +
      maCompareRow('Total obstructions', beforeObs, afterObs);

  } catch (err) {
    console.error('Error loading comparison:', err);
    body.innerHTML = '<p style="padding:24px 0;color:#e74c3c;">Could not load comparison. Please try again.</p>';
  }
}

document.getElementById('ma-compare-close').addEventListener('click', function () {
  document.getElementById('ma-compare-overlay').classList.remove('show');
});
document.getElementById('ma-compare-overlay').addEventListener('click', function (e) {
  if (e.target === this) this.classList.remove('show');
});

// ── Init: decide between the normal filter bar + list vs. Section 5's
//    empty state, based on whether the user has any completed audits
//    or anything resumable. Both calls run first so the decision is
//    never made on partial information. ───────────────────────────────
(async function initMyAudits() {
  const hasCompletedAudits = await loadMyAuditStats();
  const hasResumable       = await loadMyAuditContinue();

  if (!hasCompletedAudits && !hasResumable) {
    document.getElementById('ma-filterbar').style.display  = 'none';
    document.getElementById('ma-list-card').style.display  = 'none';
    document.getElementById('ma-empty-state').style.display = 'block';
    return;
  }

  loadMyAuditList(1);
})();
</script>

</body>
</html>
