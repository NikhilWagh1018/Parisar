<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/admin_activity.php
//  Activity Log — admin-only. Read-only viewer for the audit_log
//  table (road create/delete actions written by api/admin/roads.php).
//  Distinct from the surveyor-facing activity_log table — see the
//  note in api/admin/activity_log.php.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../config/constants.php';

$initials = strtoupper(substr($CURRENT_USER_NAME, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
  <link rel="stylesheet" href="../css/theme.css">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activity Log — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/dashboard.css">
<style nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  .act-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
  .act-table th {
    text-align: left; font-size: 0.72rem; font-weight: 700; letter-spacing: .04em;
    text-transform: uppercase; opacity: 0.55; padding: 0 12px 10px; white-space: nowrap;
  }
  .act-table td { padding: 12px; border-top: 1px solid rgba(127,127,127,0.12); vertical-align: middle; }
  .act-table tbody tr:hover { background: rgba(127,127,127,0.05); }
  .act-actor { font-weight: 600; color: var(--ink); }
  .act-road { color: var(--ink); }
  .act-muted { opacity: 0.55; }
  .act-when { white-space: nowrap; color: var(--gray); font-size: 0.82rem; }

  .action-pill {
    display: inline-flex; align-items: center; font-size: 0.72rem; font-weight: 700;
    padding: 4px 11px; border-radius: 999px; white-space: nowrap; text-transform: capitalize;
  }
  .action-pill.create { background: var(--tsuccess-bg); color: var(--tsuccess-txt); }
  .action-pill.delete { background: var(--tdanger-bg); color: var(--tdanger-txt); }
  .action-pill.other  { background: var(--tseg-bg); color: var(--gray); }

  .act-filterbar { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
  .act-search {
    width: 100%; max-width: 320px; padding: 9px 12px; border-radius: 8px;
    border: 1px solid rgba(127,127,127,0.25); background: transparent; font-size: 0.85rem;
    font-family: inherit;
  }
  .act-action-filter {
    padding: 9px 12px; border-radius: 8px; border: 1px solid rgba(127,127,127,0.25);
    background: transparent; font-size: 0.85rem; font-family: inherit;
  }
  .act-filtercount { margin-left: auto; font-size: 0.8rem; opacity: 0.6; white-space: nowrap; }
  .act-empty { text-align: center; padding: 30px; opacity: 0.6; }
</style>
</head>
<body>

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
    <div class="nav-section">Admin</div>
    <a class="nav-item" href="admin.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 1-1.5 3-9 3s-9-2-9-3 1.5-3 9-3 9 2 9 3z"/></svg>
      Roads
    </a>
    <a class="nav-item" href="admin_surveyors.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Users
    </a>
    <a class="nav-item active" href="admin_activity.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Activity Log
    </a>
  </nav>

  <div class="sb-user">
  <button class="sb-user-btn" id="sbUserBtn" onclick="toggleUserMenu()">
    <div class="sb-avatar" id="sbAvatar">
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
  </div>
</aside>

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
</script>

<div class="sb-overlay" id="sb-overlay"></div>

<main>
  <div class="topbar">
    <button id="sb-toggle" aria-label="Menu">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
    </button>
    <div class="topbar-left">
      <h2>Activity Log</h2>
      <p style="margin-top:2px;">Road create/delete actions, newest first.</p>
    </div>
  </div>

  <div style="padding: 0 4px 4px;">
    <div class="card">
      <div class="act-filterbar">
        <input type="text" id="actSearch" class="act-search" placeholder="Search by actor or road name…">
        <select id="actionFilter" class="act-action-filter">
          <option value="all">All actions</option>
          <option value="create">Created only</option>
          <option value="delete">Deleted only</option>
        </select>
        <span id="filterCount" class="act-filtercount"></span>
      </div>

      <div id="loadingMsg" style="text-align:center;padding:30px;opacity:.6;">Loading activity…</div>
      <div id="errorMsg" style="display:none;text-align:center;padding:30px;color:var(--tdanger-txt);"></div>
      <div id="tableWrap" style="display:none;overflow-x:auto;">
        <table class="act-table">
          <thead>
            <tr>
              <th>Actor</th>
              <th>Action</th>
              <th>Road</th>
              <th>When</th>
            </tr>
          </thead>
          <tbody id="actTbody"></tbody>
        </table>
        <div id="noResults" class="act-empty" style="display:none;">No activity matches your search.</div>
      </div>
    </div>
  </div>
</main>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function () {
  'use strict';

  var allEntries = [];

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = String(str == null ? '' : str);
    return div.innerHTML;
  }

  function fmtDate(iso) {
    if (!iso) return '<span class="act-muted">—</span>';
    try {
      var d = new Date(iso.replace(' ', 'T'));
      return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) +
        ' at ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    } catch (e) {
      return escapeHtml(iso);
    }
  }

  function actionPillClass(action) {
    if (action === 'create') return 'create';
    if (action === 'delete') return 'delete';
    return 'other';
  }

  function actionLabel(action) {
    if (action === 'create') return 'Created';
    if (action === 'delete') return 'Deleted';
    return action;
  }

  function buildRow(e) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td class="act-actor">' + (e.actor_name ? escapeHtml(e.actor_name) : '<span class="act-muted">Unknown</span>') + '</td>' +
      '<td><span class="action-pill ' + actionPillClass(e.action) + '">' + escapeHtml(actionLabel(e.action)) + '</span></td>' +
      '<td class="act-road">' + escapeHtml(e.road_group_name) + '</td>' +
      '<td class="act-when">' + fmtDate(e.created_at) + '</td>';
    return tr;
  }

  function render(list) {
    var tbody = document.getElementById('actTbody');
    tbody.innerHTML = '';
    document.getElementById('noResults').style.display = list.length === 0 ? 'block' : 'none';
    list.forEach(function (e) { tbody.appendChild(buildRow(e)); });
  }

  function applyFilter() {
    var q = document.getElementById('actSearch').value.trim().toLowerCase();
    var actionFilter = document.getElementById('actionFilter').value;
    var filtered = allEntries.filter(function (e) {
      if (actionFilter !== 'all' && e.action !== actionFilter) return false;
      return (e.actor_name || '').toLowerCase().indexOf(q) !== -1 ||
             (e.road_group_name || '').toLowerCase().indexOf(q) !== -1;
    });
    render(filtered);
    document.getElementById('filterCount').textContent =
      'Showing ' + filtered.length + ' of ' + allEntries.length + ' entries';
  }

  document.getElementById('actSearch').addEventListener('input', applyFilter);
  document.getElementById('actionFilter').addEventListener('change', applyFilter);

  fetch('../api/admin/activity_log.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      document.getElementById('loadingMsg').style.display = 'none';
      if (!data.success) {
        document.getElementById('errorMsg').textContent = data.error || 'Could not load activity log.';
        document.getElementById('errorMsg').style.display = 'block';
        return;
      }
      allEntries = data.entries;
      document.getElementById('tableWrap').style.display = 'block';
      applyFilter();
    })
    .catch(function () {
      document.getElementById('loadingMsg').style.display = 'none';
      document.getElementById('errorMsg').textContent = 'Network error — could not load activity log.';
      document.getElementById('errorMsg').style.display = 'block';
    });
})();
</script>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
var tog2 = document.getElementById("sb-toggle");
var ovl2 = document.getElementById("sb-overlay");
var aside2 = document.querySelector("aside");
function openSb2(){ aside2.classList.add("open"); ovl2.classList.add("show"); }
function closeSb2(){ aside2.classList.remove("open"); ovl2.classList.remove("show"); }
tog2.addEventListener("click", openSb2);
ovl2.addEventListener("click", closeSb2);
</script>
</body>
</html>
