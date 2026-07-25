<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/admin.php  (v4 — UI polish pass)
//  Roads admin page — admin-only. Add/Delete only (no verify/flag,
//  removed in v3). This pass restyles the page: a search filter, a
//  roads/segments summary line, badge-style counts, and a redesigned
//  delete confirmation panel — no behavior or API contract changes.
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
<title>Roads — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/dashboard.css">
<style nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  .topbar-left p { margin-top: 2px; }

  .info-banner {
    display: flex; align-items: flex-start; gap: 10px;
    font-size: 0.85rem; color: var(--gray); margin-bottom: 18px;
    padding: 14px 16px; border-radius: var(--r);
    background: var(--gp); border: 1px solid var(--bd); line-height: 1.5;
  }
  .info-banner svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; color: var(--g); }

  /* ── Toolbar row: search + add-road trigger live above the list ── */
  .roads-toolbar {
    display: flex; align-items: center; gap: 10px; margin-bottom: 16px; flex-wrap: wrap;
  }
  .roads-search {
    position: relative; flex: 1 1 260px; min-width: 180px;
  }
  .roads-search svg {
    position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
    width: 15px; height: 15px; color: var(--grl); pointer-events: none;
  }
  .roads-search input {
    width: 100%; padding: 9px 14px 9px 36px; border-radius: 999px;
    border: 1px solid var(--bd); background: #fff; font-family: 'DM Sans', sans-serif;
    font-size: 0.85rem; color: var(--ink); transition: var(--T);
  }
  .roads-search input:focus { outline: none; border-color: var(--g); box-shadow: 0 0 0 3px var(--gp); }
  .roads-search input::placeholder { color: var(--grl); }
  .roads-count {
    font-size: 0.76rem; color: var(--grl); white-space: nowrap; padding: 0 2px;
  }

  .action-btn {
    font-size: 0.78rem; font-weight: 600; padding: 6px 14px; border-radius: 999px;
    border: 1px solid var(--bd); background: #fff; color: var(--ink); cursor: pointer; transition: var(--T);
  }
  .action-btn:hover { border-color: var(--g); }
  .action-btn:disabled { opacity: 0.5; cursor: wait; }
  .text-link-btn {
    font-size: 0.78rem; color: var(--gray); cursor: pointer;
    background: none; border: none; text-decoration: underline; padding: 0;
  }

  /* ── Road group cards ── */
  .grp-card { padding: 16px 18px; margin-bottom: 10px; transition: var(--T); }
  .grp-card:hover { border-color: var(--gl); box-shadow: 0 2px 10px rgba(61,122,31,.08); }
  .grp-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
  .grp-head-left { display: flex; align-items: center; gap: 12px; min-width: 0; cursor: pointer; flex: 1; }
  .grp-name { font-family: 'Playfair Display', serif; font-weight: 700; font-size: 1.02rem; color: var(--ink); }
  .grp-counts { display: flex; align-items: center; gap: 6px; flex-shrink: 0; flex-wrap: wrap; }
  .count-pill {
    display: inline-flex; align-items: center; font-size: 0.7rem; font-weight: 700;
    padding: 3px 10px; border-radius: 999px; white-space: nowrap;
    background: var(--gp); color: var(--gd);
  }
  .count-pill.alt { background: var(--tseg-bg); color: var(--gray); }
  .grp-head-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }

  /* ── Delete affordance: icon + label pill, quiet until hovered ── */
  .del-btn {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.76rem; font-weight: 600; color: var(--tdanger-txt); cursor: pointer;
    background: transparent; border: 1px solid transparent; border-radius: 999px;
    padding: 6px 12px; flex-shrink: 0; transition: var(--T);
  }
  .del-btn:hover { background: var(--tdanger-bg); border-color: rgba(139,26,26,.2); }
  .del-btn svg { width: 13px; height: 13px; flex-shrink: 0; }
  .del-btn:disabled { opacity: 0.5; cursor: wait; }

  /* ── Delete confirm panel: a self-contained danger card ── */
  .del-panel {
    display: none; width: 100%; margin-top: 12px; padding: 14px 16px;
    border-radius: 10px; background: var(--tdanger-bg); border: 1px solid rgba(139,26,26,.18);
  }
  .del-panel.open { display: block; animation: delPanelIn .16s ease-out; }
  @keyframes delPanelIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .del-panel-msg {
    display: flex; align-items: flex-start; gap: 8px; font-size: 0.8rem;
    color: var(--tdanger-txt); line-height: 1.5; margin-bottom: 10px;
  }
  .del-panel-msg svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }
  .del-panel-msg b { font-weight: 700; }
  .del-panel-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .del-panel input {
    flex: 1 1 200px; min-width: 160px; padding: 8px 12px; border-radius: 8px;
    border: 1px solid rgba(139,26,26,.35);
    font-family: 'DM Sans', sans-serif; font-size: 0.83rem; color: var(--ink);
    background: #fff;
  }
  .del-panel input:focus { outline: none; border-color: var(--tdanger-txt); box-shadow: 0 0 0 3px rgba(139,26,26,.12); }
  .del-confirm-btn {
    font-size: 0.78rem; font-weight: 700; padding: 8px 16px; border-radius: 999px;
    border: 1px solid var(--tdanger-txt); background: var(--tdanger-txt); color: #fff;
    cursor: pointer; transition: var(--T); flex-shrink: 0; white-space: nowrap;
  }
  .del-confirm-btn:disabled { opacity: 0.4; cursor: not-allowed; }
  .del-confirm-btn:not(:disabled):hover { background: #6e1414; border-color: #6e1414; }
  .del-cancel-btn {
    font-size: 0.78rem; color: var(--gray); cursor: pointer;
    background: none; border: none; text-decoration: underline; padding: 6px 4px;
  }
  .del-panel-hint { font-size: 0.74rem; color: var(--tdanger-txt); width: 100%; margin-top: 2px; }
  .del-panel-hint.match { color: var(--tsuccess-txt); }

  .chev { display: inline-flex; flex-shrink: 0; color: var(--grl); transition: var(--T); }
  .chev svg { width: 14px; height: 14px; }
  .chev.open { transform: rotate(90deg); color: var(--g); }

  .grp-rows {
    margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--bd);
    display: none; flex-direction: column; gap: 7px;
  }
  .grp-rows.open { display: flex; }
  .road-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 10px 12px; border-radius: 8px;
    background: var(--tseg-bg); font-size: 0.82rem; color: var(--gray);
  }
  .road-row-meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .road-row-meta b { color: var(--ink); font-weight: 600; }
  .road-row-dot { opacity: .4; }

  /* ── Add road form ── */
  .add-road-form {
    display: none; align-items: center; gap: 10px; flex-wrap: wrap;
    padding: 12px 16px; border-radius: var(--r); margin-bottom: 16px;
    background: var(--gp); border: 1px solid var(--bd); font-size: 0.85rem;
  }
  .add-road-form.show { display: flex; animation: delPanelIn .16s ease-out; }
  .add-road-form input {
    flex: 1 1 260px; min-width: 200px; padding: 8px 12px; border-radius: 8px;
    border: 1px solid var(--bd); font-family: 'DM Sans', sans-serif; font-size: 0.85rem;
    color: var(--ink); background: #fff;
  }
  .add-road-form input:focus { outline: none; border-color: var(--g); box-shadow: 0 0 0 3px #fff; }
  .add-road-msg { font-size: 0.78rem; font-weight: 600; }
  .add-road-msg.err { color: var(--tdanger-txt); }
  .add-road-msg.ok { color: var(--tsuccess-txt); }

  /* ── Empty / loading states ── */
  .empty-state {
    text-align: center; padding: 48px 20px; color: var(--grl); font-size: 0.88rem;
  }
  .empty-state svg { width: 34px; height: 34px; color: var(--grl); opacity: .6; margin-bottom: 10px; }
  .empty-state p { margin-top: 4px; }
  .empty-state .empty-sub { font-size: 0.78rem; margin-top: 2px; color: var(--grl); }
  .loading-state {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    padding: 40px 20px; color: var(--grl); font-size: 0.86rem;
  }
  .spinner {
    width: 16px; height: 16px; border-radius: 50%;
    border: 2px solid var(--bd); border-top-color: var(--g);
    animation: spin .7s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

  @media (max-width: 600px) {
    .grp-head { flex-wrap: wrap; }
    .grp-head-left { flex: 1 1 100%; }
    .grp-head-right { flex: 1 1 100%; justify-content: flex-start; margin-top: 8px; }
    .grp-counts { flex-wrap: wrap; }
    .roads-toolbar { flex-direction: column; align-items: stretch; }
    .del-panel-row { flex-direction: column; align-items: stretch; }
    .del-confirm-btn, .del-cancel-btn { width: 100%; text-align: center; }
  }
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
    <a class="nav-item active" href="admin.php">
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
      <h2>Roads</h2>
      <p id="roadsSubtitle">&nbsp;</p>
    </div>
    <button id="addRoadBtn" class="btn-new">+ Add Road</button>
  </div>

  <div style="padding: 0 4px 4px;">
    <div class="info-banner">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
      <span>Every road here is public. Click a road name to see who created each underlying entry. Deleting a road removes it and any audit entries under it — you'll be asked to type its name to confirm.</span>
    </div>

    <div class="add-road-form" id="addRoadForm">
      <input type="text" id="addRoadInput" placeholder="Road name (e.g. KOTHRUD ROAD)" maxlength="255">
      <button class="action-btn" id="addRoadSubmit" style="background:var(--g);color:#fff;border-color:var(--g);">Add</button>
      <button class="text-link-btn" id="addRoadCancel">Cancel</button>
      <span id="addRoadMsg" class="add-road-msg"></span>
    </div>

    <div class="roads-toolbar">
      <div class="roads-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="roadsSearchInput" placeholder="Search roads…" autocomplete="off">
      </div>
      <button class="action-btn" id="exportExcelBtn" type="button">Export Excel</button>
      <span class="roads-count" id="roadsCountLbl"></span>
    </div>
    <div id="exportMsg" style="font-size:0.78rem;color:var(--gray);margin:-8px 0 12px;display:none;"></div>

    <div id="loadingMsg" class="card loading-state">
      <span class="spinner"></span> Loading roads…
    </div>
    <div id="errorMsg" class="card" style="display:none;text-align:center;padding:36px;color:var(--tdanger-txt);"></div>
    <div id="groupsContainer"></div>
    <div id="emptyMsg" class="card empty-state" style="display:none;">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19h16"/><path d="M6 19V9l6-5 6 5v10"/><path d="M10 19v-6h4v6"/></svg>
      <p id="emptyMsgText">No roads yet.</p>
      <div class="empty-sub" id="emptyMsgSub">Add the first road to get started.</div>
    </div>
  </div>
</main>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">const CSRF = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';</script>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function () {
  'use strict';

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = String(str == null ? '' : str);
    return div.innerHTML;
  }

  function fmtDate(iso) {
    try {
      var d = new Date(iso);
      return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) {
      return iso;
    }
  }

  function buildMemberRow(member) {
    var row = document.createElement('div');
    row.className = 'road-row';
    row.innerHTML =
      '<div class="road-row-meta">' +
      '<span>#' + escapeHtml(member.id) + '</span>' +
      '<span class="road-row-dot">&middot;</span>' +
      '<b>' + escapeHtml(member.creator_name || 'Unknown') + '</b>' +
      '<span class="road-row-dot">&middot;</span>' +
      '<span>' + escapeHtml(member.segment_count) + ' segment' + (member.segment_count === 1 ? '' : 's') + '</span>' +
      '<span class="road-row-dot">&middot;</span>' +
      '<span>' + escapeHtml(fmtDate(member.created_at)) + '</span>' +
      '</div>';
    return row;
  }

  function submitDelete(id, name, confirmInput, confirmBtn, hint) {
    var typed = confirmInput.value.trim();
    if (typed.toUpperCase() !== name.toUpperCase()) {
      hint.textContent = 'Doesn\u2019t match \u2014 type the road name exactly.';
      hint.classList.remove('match');
      return;
    }
    confirmBtn.disabled = true;
    confirmInput.disabled = true;
    fetch('../api/admin/roads.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ id: id, action: 'delete', confirm_name: typed })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          confirmBtn.disabled = false;
          confirmInput.disabled = false;
          hint.textContent = data.error || 'Could not delete road. Please try again.';
          hint.classList.remove('match');
          return;
        }
        loadRoads();
      })
      .catch(function () {
        confirmBtn.disabled = false;
        confirmInput.disabled = false;
        hint.textContent = 'Network error \u2014 could not delete road.';
        hint.classList.remove('match');
      });
  }

  var TRASH_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';
  var WARN_ICON  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>';

  function buildGroup(group) {
    var card = document.createElement('div');
    card.className = 'card grp-card';

    var head = document.createElement('div');
    head.className = 'grp-head';

    var left = document.createElement('div');
    left.className = 'grp-head-left';

    var chev = document.createElement('span');
    chev.className = 'chev';
    chev.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>';

    var nameEl = document.createElement('span');
    nameEl.className = 'grp-name';
    nameEl.textContent = group.name;

    var countsEl = document.createElement('span');
    countsEl.className = 'grp-counts';
    var entryPill = document.createElement('span');
    entryPill.className = 'count-pill';
    entryPill.textContent = group.entry_count + (group.entry_count === 1 ? ' entry' : ' entries');
    var segPill = document.createElement('span');
    segPill.className = 'count-pill alt';
    segPill.textContent = group.total_segments + ' segments';
    countsEl.appendChild(entryPill);
    countsEl.appendChild(segPill);

    left.appendChild(chev);
    left.appendChild(nameEl);
    left.appendChild(countsEl);

    var right = document.createElement('div');
    right.className = 'grp-head-right';

    // Default state: just a Delete affordance. Clicking it opens a danger
    // panel below the row with a type-to-confirm control instead of a
    // browser confirm() — deletion here removes any real audit entries
    // under the road too, so it needs a deliberate, hard-to-fat-finger step.
    var deleteBtn = document.createElement('button');
    deleteBtn.className = 'del-btn';
    deleteBtn.innerHTML = TRASH_ICON + '<span>Delete</span>';
    deleteBtn.setAttribute('aria-label', 'Delete ' + group.name);

    right.appendChild(deleteBtn);
    head.appendChild(left);
    head.appendChild(right);

    var panel = document.createElement('div');
    panel.className = 'del-panel';

    var panelMsg = document.createElement('div');
    panelMsg.className = 'del-panel-msg';
    panelMsg.innerHTML = WARN_ICON + '<span>' +
      (group.entry_count > 0
        ? 'This also removes <b>' + escapeHtml(group.entry_count) + ' audit ' + (group.entry_count === 1 ? 'entry' : 'entries') + '</b> under this road. '
        : '') +
      'This cannot be undone. Type <b>' + escapeHtml(group.name) + '</b> to confirm.</span>';

    var panelRow = document.createElement('div');
    panelRow.className = 'del-panel-row';

    var confirmInput = document.createElement('input');
    confirmInput.type = 'text';
    confirmInput.placeholder = group.name;
    confirmInput.autocomplete = 'off';

    var confirmBtn = document.createElement('button');
    confirmBtn.className = 'del-confirm-btn';
    confirmBtn.textContent = group.entry_count > 0 ? 'Delete road + entries' : 'Delete road';
    confirmBtn.disabled = true;

    var confirmCancel = document.createElement('button');
    confirmCancel.className = 'del-cancel-btn';
    confirmCancel.textContent = 'Cancel';

    var confirmHint = document.createElement('span');
    confirmHint.className = 'del-panel-hint';
    confirmHint.style.display = 'none';

    panelRow.appendChild(confirmInput);
    panelRow.appendChild(confirmBtn);
    panelRow.appendChild(confirmCancel);
    panel.appendChild(panelMsg);
    panel.appendChild(panelRow);
    panel.appendChild(confirmHint);

    function resetPanel() {
      panel.classList.remove('open');
      confirmInput.value = '';
      confirmBtn.disabled = true;
      confirmInput.disabled = false;
      confirmHint.style.display = 'none';
      confirmHint.classList.remove('match');
    }

    deleteBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = panel.classList.toggle('open');
      if (open) { confirmInput.focus(); } else { resetPanel(); }
    });

    confirmCancel.addEventListener('click', function (e) {
      e.stopPropagation();
      resetPanel();
    });

    confirmInput.addEventListener('input', function () {
      var matches = confirmInput.value.trim().toUpperCase() === group.name.toUpperCase();
      confirmBtn.disabled = !matches;
      if (confirmInput.value.trim().length === 0) {
        confirmHint.style.display = 'none';
      } else {
        confirmHint.style.display = 'block';
        confirmHint.classList.toggle('match', matches);
        confirmHint.textContent = matches ? 'Ready to delete.' : 'Doesn\u2019t match yet.';
      }
    });

    confirmBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      submitDelete(group.id, group.name, confirmInput, confirmBtn, confirmHint);
    });
    confirmInput.addEventListener('keydown', function (e) {
      e.stopPropagation();
      if (e.key === 'Enter' && !confirmBtn.disabled) submitDelete(group.id, group.name, confirmInput, confirmBtn, confirmHint);
      if (e.key === 'Escape') resetPanel();
    });
    confirmInput.addEventListener('click', function (e) { e.stopPropagation(); });

    var rowsWrap = document.createElement('div');
    rowsWrap.className = 'grp-rows';
    group.members.forEach(function (m) {
      rowsWrap.appendChild(buildMemberRow(m));
    });

    left.addEventListener('click', function () {
      var open = rowsWrap.classList.toggle('open');
      chev.classList.toggle('open', open);
    });

    card.appendChild(head);
    card.appendChild(panel);
    card.appendChild(rowsWrap);
    return card;
  }

  var allGroups = [];
  var lastVisible = [];
  var searchInput = document.getElementById('roadsSearchInput');
  var roadsCountLbl = document.getElementById('roadsCountLbl');
  var emptyMsgText = document.getElementById('emptyMsgText');
  var emptyMsgSub = document.getElementById('emptyMsgSub');

  function currentQuery() {
    return searchInput.value.trim().toUpperCase();
  }

  function render(groups) {
    var container = document.getElementById('groupsContainer');
    var emptyMsg = document.getElementById('emptyMsg');
    var query = currentQuery();
    var visible = query
      ? groups.filter(function (g) { return g.name.toUpperCase().indexOf(query) !== -1; })
      : groups;
    lastVisible = visible;

    container.innerHTML = '';
    emptyMsg.style.display = visible.length === 0 ? 'block' : 'none';
    if (visible.length === 0) {
      if (query) {
        emptyMsgText.textContent = 'No roads match "' + searchInput.value.trim() + '".';
        emptyMsgSub.textContent = 'Try a different search, or clear it to see all roads.';
      } else {
        emptyMsgText.textContent = 'No roads yet.';
        emptyMsgSub.textContent = 'Add the first road to get started.';
      }
    }
    visible.forEach(function (g) {
      container.appendChild(buildGroup(g));
    });

    roadsCountLbl.textContent = query
      ? visible.length + ' of ' + groups.length + (groups.length === 1 ? ' road' : ' roads')
      : groups.length + (groups.length === 1 ? ' road' : ' roads');
  }

  searchInput.addEventListener('input', function () { render(allGroups); });

  var addRoadBtn = document.getElementById('addRoadBtn');
  var addRoadForm = document.getElementById('addRoadForm');
  var addRoadInput = document.getElementById('addRoadInput');
  var addRoadSubmit = document.getElementById('addRoadSubmit');
  var addRoadCancel = document.getElementById('addRoadCancel');
  var addRoadMsg = document.getElementById('addRoadMsg');

  addRoadBtn.addEventListener('click', function () {
    addRoadForm.classList.toggle('show');
    if (addRoadForm.classList.contains('show')) {
      addRoadInput.focus();
    }
  });

  addRoadCancel.addEventListener('click', function () {
    addRoadForm.classList.remove('show');
    addRoadInput.value = '';
    addRoadMsg.textContent = '';
    addRoadMsg.className = 'add-road-msg';
  });

  function submitAddRoad() {
    var name = addRoadInput.value.trim();
    addRoadMsg.className = 'add-road-msg';
    if (name.length < 3) {
      addRoadMsg.textContent = 'Min 3 characters.';
      addRoadMsg.className = 'add-road-msg err';
      return;
    }
    addRoadSubmit.disabled = true;
    addRoadMsg.textContent = 'Adding\u2026';
    fetch('../api/admin/roads.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ action: 'create', name: name })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        addRoadSubmit.disabled = false;
        if (!data.success) {
          addRoadMsg.textContent = data.error || 'Could not add road.';
          addRoadMsg.className = 'add-road-msg err';
          return;
        }
        addRoadMsg.textContent = 'Added "' + data.name + '".';
        addRoadMsg.className = 'add-road-msg ok';
        addRoadInput.value = '';
        loadRoads();
      })
      .catch(function () {
        addRoadSubmit.disabled = false;
        addRoadMsg.textContent = 'Network error \u2014 could not add road.';
        addRoadMsg.className = 'add-road-msg err';
      });
  }

  addRoadSubmit.addEventListener('click', submitAddRoad);
  addRoadInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') submitAddRoad();
  });

  function loadRoads() {
    fetch('../api/admin/roads.php', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        document.getElementById('loadingMsg').style.display = 'none';
        if (!data.success) {
          document.getElementById('errorMsg').style.display = 'block';
          document.getElementById('errorMsg').textContent = data.error || 'Could not load roads.';
          return;
        }
        allGroups = data.road_groups;
        var totalSegments = allGroups.reduce(function (sum, g) { return sum + g.total_segments; }, 0);
        document.getElementById('roadsSubtitle').textContent =
          allGroups.length + (allGroups.length === 1 ? ' road' : ' roads') +
          ' \u00b7 ' + totalSegments + ' segments audited';
        render(allGroups);
      })
      .catch(function () {
        document.getElementById('loadingMsg').style.display = 'none';
        document.getElementById('errorMsg').style.display = 'block';
        document.getElementById('errorMsg').textContent = 'Network error \u2014 could not load roads.';
      });
  }

  loadRoads();

  // ── Export (CSV + Excel) ─────────────────────────────────────
  // Both formats export exactly `lastVisible` — the road groups
  // currently shown after the on-page search filter — never the
  // full unfiltered `allGroups`.
  var exportExcelBtn = document.getElementById('exportExcelBtn');
  var exportMsg = document.getElementById('exportMsg');

  function exportRows() {
    return lastVisible.map(function (g) {
      return {
        name: g.name,
        entry_count: g.entry_count,
        total_segments: g.total_segments,
        is_verified: !!g.is_verified,
        created_at: g.created_at
      };
    });
  }

  function downloadBlob(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  function showExportMsg(text, isErr) {
    exportMsg.textContent = text;
    exportMsg.style.display = text ? 'block' : 'none';
    exportMsg.style.color = isErr ? 'var(--tdanger-txt)' : 'var(--gray)';
  }

  exportExcelBtn.addEventListener('click', function () {
    var rows = exportRows();
    if (rows.length === 0) {
      showExportMsg('Nothing to export — no roads match the current search.', true);
      return;
    }
    exportExcelBtn.disabled = true;
    showExportMsg('Generating Excel file…');
    fetch('../api/admin/export-roads.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ rows: rows })
    })
      .then(function (r) {
        if (!r.ok) return r.json().then(function (d) { throw new Error(d.error || 'Export failed.'); });
        return r.blob();
      })
      .then(function (blob) {
        downloadBlob(blob, 'CycleAudit-Roads-' + new Date().toISOString().slice(0, 10) + '.xlsx');
        showExportMsg('');
      })
      .catch(function (e) {
        showExportMsg(e.message || 'Network error — could not export.', true);
      })
      .finally(function () {
        exportExcelBtn.disabled = false;
      });
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
