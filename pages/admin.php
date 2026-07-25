<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/admin.php  (v2 — road_groups based)
//  Road verification panel — admin-only. One toggle per real road
//  (road_groups), with duplicate audit-session rows still visible
//  underneath for transparency, but no longer requiring individual
//  toggling.
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
<title>Verify Roads — CycleAudit</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/dashboard.css">
<style nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  .info-banner {
    display: flex; align-items: flex-start; gap: 10px;
    font-size: 0.85rem; color: var(--gray); margin-bottom: 20px;
    padding: 14px 16px; border-radius: var(--r);
    background: var(--gp); border: 1px solid var(--bd); line-height: 1.5;
  }
  .info-banner svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; color: var(--g); }

  .toolbar-card {
    background: #fff; border: 1px solid var(--bd); border-radius: var(--r);
    padding: 12px 16px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 20px; flex-wrap: wrap; font-size: 0.85rem;
  }
  .toolbar-check { display: flex; align-items: center; gap: 7px; cursor: pointer; color: var(--gray); font-weight: 500; }
  .toolbar-check input { accent-color: var(--g); width: 15px; height: 15px; cursor: pointer; }

  .bulk-toolbar {
    display: none; align-items: center; gap: 10px; flex-wrap: wrap;
    padding: 12px 16px; border-radius: var(--r); margin-bottom: 16px;
    background: var(--gp); border: 1px solid var(--bd); font-size: 0.85rem;
  }
  .bulk-toolbar.show { display: flex; }
  .bulk-count { font-weight: 700; color: var(--gd); }
  .bulk-btn {
    font-size: 0.78rem; font-weight: 600; padding: 6px 14px; border-radius: 999px;
    border: 1px solid var(--bd); background: #fff; color: var(--ink); cursor: pointer; transition: var(--T);
  }
  .bulk-btn:hover { background: #fff; border-color: var(--g); }
  .bulk-btn.bulk-danger { border-color: rgba(139,26,26,.25); color: #8b1a1a; }
  .bulk-btn.bulk-danger:hover { background: #fce8e8; border-color: #8b1a1a; }
  .bulk-btn:disabled { opacity: 0.5; cursor: wait; }
  .bulk-clear {
    margin-left: auto; font-size: 0.78rem; color: var(--gray); cursor: pointer;
    background: none; border: none; text-decoration: underline; padding: 0;
  }
  .grp-delete-btn {
    font-size: 0.78rem; color: #8b1a1a; cursor: pointer;
    background: none; border: none; text-decoration: underline; padding: 0; flex-shrink: 0;
  }
  .grp-delete-btn:disabled { opacity: 0.5; cursor: wait; }

  .grp-card { padding: 16px 18px; margin-bottom: 12px; }
  .grp-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
  .grp-head-left { display: flex; align-items: center; gap: 12px; min-width: 0; cursor: pointer; flex: 1; }
  .grp-check { flex-shrink: 0; width: 16px; height: 16px; accent-color: var(--g); cursor: pointer; }
  .grp-name { font-family: 'Playfair Display', serif; font-weight: 700; font-size: 1.02rem; color: var(--ink); }
  .grp-count { font-size: 0.76rem; color: var(--grl); flex-shrink: 0; }
  .grp-head-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }

  .badge {
    font-size: 0.7rem; font-weight: 700; padding: 4px 11px; border-radius: 999px;
    flex-shrink: 0; white-space: nowrap; letter-spacing: .01em;
  }
  .badge-flagged { background: var(--tdanger-bg); color: var(--tdanger-txt); }

  .status-text { font-size: 0.78rem; font-weight: 600; flex-shrink: 0; }
  .status-text.st-visible { color: var(--tsuccess-txt); }
  .status-text.st-hidden  { color: var(--grl); }

  .flag-btn {
    font-size: 0.72rem; font-weight: 600; padding: 5px 12px; border-radius: 999px;
    border: 1px solid rgba(139,26,26,.28); background: transparent; color: #8b1a1a;
    cursor: pointer; flex-shrink: 0; white-space: nowrap; transition: var(--T);
  }
  .flag-btn:hover { background: var(--tdanger-bg); }
  .flag-btn.is-flagged { background: #8b1a1a; color: white; border-color: #8b1a1a; }
  .flag-btn:disabled { opacity: 0.5; cursor: wait; }

  .toggle-switch { position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0; }
  .toggle-switch input { opacity: 0; width: 0; height: 0; }
  .toggle-slider {
    position: absolute; cursor: pointer; inset: 0; background-color: var(--ttoggle-bg);
    transition: var(--T); border-radius: 999px;
  }
  .toggle-slider::before {
    position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px;
    background-color: white; transition: var(--T); border-radius: 50%; box-shadow: 0 1px 2px rgba(0,0,0,.15);
  }
  .toggle-switch input:checked + .toggle-slider { background-color: var(--g); }
  .toggle-switch input:checked + .toggle-slider::before { transform: translateX(18px); }
  .toggle-switch input:disabled + .toggle-slider { opacity: 0.5; cursor: wait; }

  .chev { display: inline-flex; flex-shrink: 0; color: var(--grl); transition: transform 0.2s; }
  .chev svg { width: 14px; height: 14px; }
  .chev.open { transform: rotate(90deg); }

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

  .add-road-form {
    display: none; align-items: center; gap: 10px; flex-wrap: wrap;
    padding: 12px 16px; border-radius: var(--r); margin-bottom: 16px;
    background: var(--gp); border: 1px solid var(--bd); font-size: 0.85rem;
  }
  .add-road-form.show { display: flex; }
  .add-road-form input {
    flex: 1 1 260px; min-width: 200px; padding: 8px 12px; border-radius: 8px;
    border: 1px solid var(--bd); font-family: 'DM Sans', sans-serif; font-size: 0.85rem;
    color: var(--ink); background: #fff;
  }
  .add-road-form input:focus { outline: 2px solid var(--g); outline-offset: 1px; }
  .add-road-msg { font-size: 0.78rem; font-weight: 600; }
  .add-road-msg.err { color: #8b1a1a; }
  .add-road-msg.ok { color: var(--tsuccess-txt); }

  .empty-state { text-align: center; padding: 40px 20px; color: var(--grl); font-size: 0.88rem; }

  @media (max-width: 600px) {
    .grp-head { flex-wrap: wrap; }
    .grp-head-left { flex: 1 1 100%; }
    .grp-head-right { flex: 1 1 100%; justify-content: flex-start; margin-top: 8px; }
    .toolbar-card { gap: 12px; }
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
      Verify Roads
    </a>
    <a class="nav-item" href="admin_surveyors.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Users
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
    <div class="topbar-left"><h2>Verify Roads</h2></div>
    <button id="addRoadBtn" class="btn-new">+ Add Road</button>
  </div>

  <div style="padding: 0 4px 4px;">
    <div class="info-banner">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
      <span>Each row below is one real road. The toggle verifies the road itself — not an individual audit entry — so flipping it on instantly makes that road visible publicly, regardless of how many surveyors independently audited it. Click a road name to see who created each underlying entry.</span>
    </div>

    <div id="loadingMsg" class="card" style="text-align:center;padding:36px;color:var(--grl);">
      Loading roads…
    </div>
    <div id="errorMsg" class="card" style="display:none;text-align:center;padding:36px;color:#8b1a1a;"></div>
    <div class="toolbar-card">
      <label class="toolbar-check"><input type="checkbox" id="selectAllCheck"> Select all visible</label>
      <label class="toolbar-check"><input type="checkbox" id="showFlaggedCheck"> Show flagged groups</label>
    </div>
    <div class="add-road-form" id="addRoadForm">
      <input type="text" id="addRoadInput" placeholder="Road name (e.g. KOTHRUD ROAD)" maxlength="255">
      <button class="bulk-btn" id="addRoadSubmit" style="background:var(--g);color:#fff;border-color:var(--g);">Add</button>
      <button class="bulk-clear" id="addRoadCancel">Cancel</button>
      <span id="addRoadMsg" class="add-road-msg"></span>
    </div>
    <div class="bulk-toolbar" id="bulkToolbar">
      <span class="bulk-count" id="bulkCount">0 selected</span>
      <button class="bulk-btn" id="bulkVerifyBtn">Mark Visible</button>
      <button class="bulk-btn" id="bulkHideBtn">Mark Hidden</button>
      <button class="bulk-btn bulk-danger" id="bulkFlagBtn">Flag</button>
      <button class="bulk-btn" id="bulkUnflagBtn">Unflag</button>
      <button class="bulk-clear" id="bulkClearBtn">Clear selection</button>
    </div>
    <div id="groupsContainer"></div>
    <div id="emptyMsg" class="card empty-state" style="display:none;">No roads match this filter.</div>
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

  function toggleGroup(id, input, action) {
    input.disabled = true;
    fetch('../api/admin/roads.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ id: id, action: action })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        input.disabled = false;
        if (!data.success) {
          input.checked = !input.checked;
          alert(data.error || 'Could not update road. Please try again.');
          return;
        }
        loadRoads();
      })
      .catch(function () {
        input.disabled = false;
        input.checked = !input.checked;
        alert('Network error \u2014 could not update road.');
      });
  }

  function deleteGroup(id, name, btn) {
    if (!confirm('Delete "' + name + '"? This can\'t be undone.')) return;
    btn.disabled = true;
    fetch('../api/admin/roads.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ id: id, action: 'delete' })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        if (!data.success) {
          alert(data.error || 'Could not delete road. Please try again.');
          return;
        }
        loadRoads();
      })
      .catch(function () {
        btn.disabled = false;
        alert('Network error \u2014 could not delete road.');
      });
  }

  function toggleFlag(id, btn) {
    btn.disabled = true;
    fetch('../api/admin/roads.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ id: id, action: 'flag' })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        if (!data.success) {
          alert(data.error || 'Could not update flag. Please try again.');
          return;
        }
        loadRoads();
      })
      .catch(function () {
        btn.disabled = false;
        alert('Network error \u2014 could not update flag.');
      });
  }

  function buildGroup(group) {
    var card = document.createElement('div');
    card.className = 'card grp-card';

    var head = document.createElement('div');
    head.className = 'grp-head';

    var left = document.createElement('div');
    left.className = 'grp-head-left';

    var check = document.createElement('input');
    check.type = 'checkbox';
    check.className = 'grp-check';
    check.checked = selectedIds.has(group.id);
    check.addEventListener('click', function (e) {
      e.stopPropagation();
      if (check.checked) {
        selectedIds.add(group.id);
      } else {
        selectedIds.delete(group.id);
      }
      updateBulkToolbar();
    });

    var chev = document.createElement('span');
    chev.className = 'chev';
    chev.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>';

    var nameEl = document.createElement('span');
    nameEl.className = 'grp-name';
    nameEl.textContent = group.name;

    var countEl = document.createElement('span');
    countEl.className = 'grp-count';
    countEl.textContent = group.entry_count + (group.entry_count === 1 ? ' entry' : ' entries') + ' \u00b7 ' + group.total_segments + ' segments total';

    left.appendChild(check);
    left.appendChild(chev);
    left.appendChild(nameEl);
    left.appendChild(countEl);

    var right = document.createElement('div');
    right.className = 'grp-head-right';

    var flagBtn = document.createElement('button');
    flagBtn.className = 'flag-btn' + (group.is_flagged ? ' is-flagged' : '');
    flagBtn.textContent = group.is_flagged ? 'Unflag' : 'Flag as illegitimate';
    flagBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleFlag(group.id, flagBtn);
    });

    if (group.is_flagged) {
      // Flagged groups are forced hidden and the toggle can't be used until
      // unflagged, so we skip it entirely rather than show a dead control.
      var flagBadge = document.createElement('span');
      flagBadge.className = 'badge badge-flagged';
      flagBadge.textContent = 'Flagged';
      right.appendChild(flagBadge);
      right.appendChild(flagBtn);
    } else {
      var statusText = document.createElement('span');
      statusText.className = 'status-text ' + (group.is_verified ? 'st-visible' : 'st-hidden');
      statusText.textContent = group.is_verified ? 'Visible' : 'Hidden';

      var label = document.createElement('label');
      label.className = 'toggle-switch';
      var input = document.createElement('input');
      input.type = 'checkbox';
      input.checked = !!group.is_verified;
      input.addEventListener('change', function () {
        toggleGroup(group.id, input, 'verify');
      });
      var slider = document.createElement('span');
      slider.className = 'toggle-slider';
      label.appendChild(input);
      label.appendChild(slider);

      right.appendChild(statusText);
      right.appendChild(flagBtn);
      right.appendChild(label);
    }

    if (group.entry_count === 0) {
      var deleteBtn = document.createElement('button');
      deleteBtn.className = 'grp-delete-btn';
      deleteBtn.textContent = 'Delete';
      deleteBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        deleteGroup(group.id, group.name, deleteBtn);
      });
      right.appendChild(deleteBtn);
    }

    head.appendChild(left);
    head.appendChild(right);

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
    card.appendChild(rowsWrap);
    return card;
  }

  var showFlagged = false;
  var allGroups = [];
  var selectedIds = new Set();

  function updateBulkToolbar() {
    var toolbar = document.getElementById('bulkToolbar');
    var count = selectedIds.size;
    document.getElementById('bulkCount').textContent = count + (count === 1 ? ' selected' : ' selected');
    toolbar.classList.toggle('show', count > 0);
  }

  function bulkAction(action, value) {
    if (selectedIds.size === 0) return;
    var ids = Array.from(selectedIds);
    var btns = document.querySelectorAll('.bulk-btn');
    btns.forEach(function (b) { b.disabled = true; });
    fetch('../api/admin/roads.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ ids: ids, action: action, value: value })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btns.forEach(function (b) { b.disabled = false; });
        if (!data.success) {
          alert(data.error || 'Could not update selected roads. Please try again.');
          return;
        }
        selectedIds.clear();
        updateBulkToolbar();
        loadRoads();
      })
      .catch(function () {
        btns.forEach(function (b) { b.disabled = false; });
        alert('Network error \u2014 could not update selected roads.');
      });
  }

  function render(groups) {
    var container = document.getElementById('groupsContainer');
    var emptyMsg = document.getElementById('emptyMsg');
    container.innerHTML = '';
    var visible = showFlagged ? groups : groups.filter(function (g) { return !g.is_flagged; });
    emptyMsg.style.display = visible.length === 0 ? 'block' : 'none';
    visible.forEach(function (g) {
      container.appendChild(buildGroup(g));
    });
  }

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
        render(allGroups);
      })
      .catch(function () {
        document.getElementById('loadingMsg').style.display = 'none';
        document.getElementById('errorMsg').style.display = 'block';
        document.getElementById('errorMsg').textContent = 'Network error \u2014 could not load roads.';
      });
  }

  document.getElementById('showFlaggedCheck').addEventListener('change', function (e) {
    showFlagged = e.target.checked;
    document.getElementById('selectAllCheck').checked = false;
    render(allGroups);
  });

  document.getElementById('selectAllCheck').addEventListener('change', function (e) {
    var visible = showFlagged ? allGroups : allGroups.filter(function (g) { return !g.is_flagged; });
    if (e.target.checked) {
      visible.forEach(function (g) { selectedIds.add(g.id); });
    } else {
      visible.forEach(function (g) { selectedIds.delete(g.id); });
    }
    updateBulkToolbar();
    render(allGroups);
  });

  document.getElementById('bulkVerifyBtn').addEventListener('click', function () { bulkAction('verify', true); });
  document.getElementById('bulkHideBtn').addEventListener('click', function () { bulkAction('verify', false); });
  document.getElementById('bulkFlagBtn').addEventListener('click', function () { bulkAction('flag', true); });
  document.getElementById('bulkUnflagBtn').addEventListener('click', function () { bulkAction('flag', false); });
  document.getElementById('bulkClearBtn').addEventListener('click', function () {
    selectedIds.clear();
    updateBulkToolbar();
    render(allGroups);
  });

  loadRoads();
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
