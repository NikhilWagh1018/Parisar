<?php
declare(strict_types=1);

// Ã¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢Â
//  pages/admin_surveyors.php
//  Surveyor list panel Ã¢â‚¬â€ admin-only. Shows every surveyor account
//  with roads-created / segments-audited / last-active stats.
// Ã¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢ÂÃ¢â€¢Â

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
<title>Surveyors Ã¢â‚¬â€ CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/dashboard.css">
<style nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  .surv-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
  .surv-table th {
    text-align: left; font-size: 0.72rem; font-weight: 700; letter-spacing: .04em;
    text-transform: uppercase; opacity: 0.55; padding: 0 12px 10px; white-space: nowrap;
  }
  .surv-table td { padding: 12px; border-top: 1px solid rgba(127,127,127,0.12); vertical-align: middle; }
  .surv-table tbody tr:hover { background: rgba(127,127,127,0.05); }
  .surv-name-cell { display: flex; align-items: center; gap: 10px; }
  .surv-avatar {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: var(--g, #16a34a); color: #fff; display: flex; align-items: center;
    justify-content: center; font-weight: 700; font-size: 0.8rem;
  }
  .surv-name { font-weight: 600; }
  .surv-email { opacity: 0.6; font-size: 0.78rem; }
  .surv-stat { font-weight: 700; }
  .surv-muted { opacity: 0.55; }
  .surv-search {
    width: 100%; max-width: 320px; padding: 9px 12px; border-radius: 8px;
    border: 1px solid rgba(127,127,127,0.25); background: transparent; font-size: 0.85rem;
    margin-bottom: 16px; font-family: inherit;
  }
  .surv-empty { text-align: center; padding: 30px; opacity: 0.6; }
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
      Verify Roads
    </a>
    <a class="nav-item active" href="admin_surveyors.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Surveyors
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
    <h2>Surveyors</h2>
  </div>

  <div style="padding: 0 4px 4px;">
    <div class="card">
      <input type="text" id="survSearch" class="surv-search" placeholder="Search by name or emailÃ¢â‚¬Â¦">
      <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;margin-left:16px;"><input type="checkbox" id="showInactive"> Show inactive</label>

      <div id="loadingMsg" style="text-align:center;padding:30px;opacity:.6;">Loading surveyorsÃ¢â‚¬Â¦</div>
      <div id="errorMsg" style="display:none;text-align:center;padding:30px;color:#dc2626;"></div>
      <div id="tableWrap" style="display:none;overflow-x:auto;">
        <table class="surv-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Organisation</th>
              <th>Roads Created</th>
              <th>Segments Audited</th>
              <th>Last Active</th>
              <th>Joined</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="survTbody"></tbody>
        </table>
        <div id="noResults" class="surv-empty" style="display:none;">No surveyors match your search.</div>
      </div>
    </div>
  </div>
</main>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function () {
  'use strict';

  var allSurveyors = [];

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = String(str == null ? '' : str);
    return div.innerHTML;
  }

  function fmtDate(iso) {
    if (!iso) return '<span class="surv-muted">Never</span>';
    try {
      var d = new Date(iso.replace(' ', 'T'));
      return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) {
      return escapeHtml(iso);
    }
  }

  function initials(name) {
    return (name || '?').trim().charAt(0).toUpperCase();
  }

  function buildRow(s) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><div class="surv-name-cell">' +
        '<div class="surv-avatar">' + escapeHtml(initials(s.name)) + '</div>' +
        '<div><div class="surv-name">' + escapeHtml(s.name) + '</div>' +
        '<div class="surv-email">' + escapeHtml(s.email) + '</div></div>' +
      '</div></td>' +
      '<td>' + (s.organisation ? escapeHtml(s.organisation) : '<span class="surv-muted">Ã¢â‚¬â€</span>') + '</td>' +
      '<td class="surv-stat">' + s.roads_created + '</td>' +
      '<td class="surv-stat">' + s.segments_audited + '</td>' +
      '<td>' + fmtDate(s.last_audit_at || s.last_login) + '</td>' +
      '<td>' + fmtDate(s.created_at) + '</td>' +
      '<td>' + (s.is_active ? '<span style="color:#16a34a;font-weight:600;">Active</span>' : '<span style="color:#9ca3af;font-weight:600;">Inactive</span>') + '</td>' +
      '<td><button class="toggle-status-btn" data-id="' + s.id + '" data-active="' + s.is_active + '">' + (s.is_active ? 'Deactivate' : 'Reactivate') + '</button></td>';
    return tr;
  }

  function render(list) {
    var tbody = document.getElementById('survTbody');
    tbody.innerHTML = '';
    document.getElementById('noResults').style.display = list.length === 0 ? 'block' : 'none';
    list.forEach(function (s) { tbody.appendChild(buildRow(s)); });
  }

  function applyFilter() {
    var q = document.getElementById('survSearch').value.trim().toLowerCase();
    var showInactive = document.getElementById('showInactive').checked;
    render(allSurveyors.filter(function (s) {
      if (!showInactive && !s.is_active) return false;
      return (s.name || '').toLowerCase().indexOf(q) !== -1 ||
             (s.email || '').toLowerCase().indexOf(q) !== -1;
    }));
  }

  document.getElementById('survSearch').addEventListener('input', applyFilter);
  document.getElementById('showInactive').addEventListener('change', applyFilter);

  document.getElementById('survTbody').addEventListener('click', function (e) {
    if (!e.target.classList.contains('toggle-status-btn')) return;
    var id = parseInt(e.target.dataset.id, 10);
    var newActive = e.target.dataset.active !== 'true';
    if (!confirm(newActive ? 'Reactivate this surveyor?' : 'Deactivate this surveyor? They will no longer be able to log in.')) return;
    fetch('../api/admin/surveyors.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ id: id, is_active: newActive })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.success) { alert(data.error || 'Update failed.'); return; }
      var s = allSurveyors.find(function (x) { return x.id === id; });
      if (s) s.is_active = newActive;
      applyFilter();
    })
    .catch(function () { alert('Network error.'); });
  });

  fetch('../api/admin/surveyors.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      document.getElementById('loadingMsg').style.display = 'none';
      if (!data.success) {
        document.getElementById('errorMsg').textContent = data.error || 'Could not load surveyors.';
        document.getElementById('errorMsg').style.display = 'block';
        return;
      }
      allSurveyors = data.surveyors;
      document.getElementById('tableWrap').style.display = 'block';
      render(allSurveyors);
    })
    .catch(function () {
      document.getElementById('loadingMsg').style.display = 'none';
      document.getElementById('errorMsg').textContent = 'Network error Ã¢â‚¬â€ could not load surveyors.';
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
