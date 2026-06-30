<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/admin.php
//  Road verification panel — admin-only. Lets a non-technical
//  admin flag which surveyor-created roads should show publicly,
//  without needing raw SQL access.
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
  .grp-card { margin-bottom: 14px; }
  .grp-head {
    display: flex; align-items: center; justify-content: space-between;
    cursor: pointer; gap: 12px;
  }
  .grp-head-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .grp-name { font-weight: 600; }
  .grp-count {
    font-size: 0.78rem; opacity: 0.65; flex-shrink: 0;
  }
  .badge {
    font-size: 0.72rem; font-weight: 600; padding: 3px 9px; border-radius: 999px;
    flex-shrink: 0; white-space: nowrap;
  }
  .badge-visible { background: rgba(34,197,94,0.15); color: #16a34a; }
  .badge-hidden  { background: rgba(148,163,184,0.2); color: #64748b; }
  .grp-rows {
    margin-top: 12px; display: none; flex-direction: column; gap: 8px;
  }
  .grp-rows.open { display: flex; }
  .road-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 9px 12px; border-radius: 8px;
    background: rgba(127,127,127,0.06); font-size: 0.85rem;
  }
  .road-row-meta { opacity: 0.7; }
  .toggle-switch {
    position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0;
  }
  .toggle-switch input { opacity: 0; width: 0; height: 0; }
  .toggle-slider {
    position: absolute; cursor: pointer; inset: 0; background-color: #cbd5e1;
    transition: 0.2s; border-radius: 999px;
  }
  .toggle-slider::before {
    position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px;
    background-color: white; transition: 0.2s; border-radius: 50%;
  }
  .toggle-switch input:checked + .toggle-slider { background-color: #16a34a; }
  .toggle-switch input:checked + .toggle-slider::before { transform: translateX(18px); }
  .toggle-switch input:disabled + .toggle-slider { opacity: 0.5; cursor: wait; }
  .chev { transition: transform 0.2s; flex-shrink: 0; opacity: 0.5; }
  .chev.open { transform: rotate(90deg); }
</style>
</head>
<body class="light">

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
    <div class="nav-section">Admin</div>
    <a class="nav-item active" href="admin.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 1-1.5 3-9 3s-9-2-9-3 1.5-3 9-3 9 2 9 3z"/></svg>
      Verify Roads
    </a>
  </nav>

  <div class="sb-user">
    <div class="sb-avatar" id="sbAvatar">
      <?php if ($CURRENT_USER_PIC): ?>
        <img src="<?= htmlspecialchars($CURRENT_USER_PIC) ?>" alt="">
      <?php else: ?>
        <?= $initials ?>
      <?php endif; ?>
    </div>
    <div style="min-width:0">
      <div style="font-weight:600;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($CURRENT_USER_NAME) ?></div>
      <div style="font-size:.72rem;opacity:.6"><?= htmlspecialchars($CURRENT_USER_ROLE) ?></div>
    </div>
  </div>
</aside>

<div class="sb-overlay" id="sb-overlay"></div>

<main>
  <div class="topbar">
    <button id="sb-toggle" aria-label="Menu">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
    </button>
    <h2>Verify Roads</h2>
  </div>

  <div style="padding: 0 4px 4px;">
    <p style="opacity:.7;font-size:.9rem;margin-bottom:18px;">
      Roads start hidden from the public landing page until you verify them here.
      Toggle a road on to make it (and every duplicate entry under the same name) visible publicly.
    </p>

    <div id="loadingMsg" class="card" style="text-align:center;padding:30px;opacity:.6;">
      Loading roads…
    </div>
    <div id="errorMsg" class="card" style="display:none;text-align:center;padding:30px;color:#dc2626;"></div>
    <div id="groupsContainer"></div>
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

  function groupKey(name) {
    return String(name).trim().toUpperCase();
  }

  function fmtDate(iso) {
    try {
      var d = new Date(iso);
      return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) {
      return iso;
    }
  }

  function buildRow(road) {
    var row = document.createElement('div');
    row.className = 'road-row';

    var meta = document.createElement('div');
    meta.className = 'road-row-meta';
    meta.innerHTML =
      '#' + escapeHtml(road.id) + ' &middot; ' +
      escapeHtml(road.creator_name || 'Unknown') + ' &middot; ' +
      escapeHtml(road.segment_count) + ' segment' + (road.segment_count === 1 ? '' : 's') + ' &middot; ' +
      escapeHtml(fmtDate(road.created_at));

    var label = document.createElement('label');
    label.className = 'toggle-switch';

    var input = document.createElement('input');
    input.type = 'checkbox';
    input.checked = !!road.is_verified;
    input.addEventListener('change', function () {
      toggleRoad(road.id, input, row);
    });

    var slider = document.createElement('span');
    slider.className = 'toggle-slider';

    label.appendChild(input);
    label.appendChild(slider);
    row.appendChild(meta);
    row.appendChild(label);
    return row;
  }

  function toggleRoad(id, input, row) {
    input.disabled = true;
    fetch('../api/admin/roads.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ id: id })
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
        alert('Network error — could not update road.');
      });
  }

  function buildGroup(name, rows) {
    var anyVerified = rows.some(function (r) { return r.is_verified; });
    var totalSegments = rows.reduce(function (sum, r) { return sum + (r.segment_count || 0); }, 0);

    var card = document.createElement('div');
    card.className = 'card grp-card';

    var head = document.createElement('div');
    head.className = 'grp-head';

    var left = document.createElement('div');
    left.className = 'grp-head-left';

    var chev = document.createElement('span');
    chev.className = 'chev';
    chev.innerHTML = '&#9656;';

    var nameEl = document.createElement('span');
    nameEl.className = 'grp-name';
    nameEl.textContent = name;

    var countEl = document.createElement('span');
    countEl.className = 'grp-count';
    countEl.textContent = rows.length + (rows.length === 1 ? ' entry' : ' entries') + ' · ' + totalSegments + ' segments total';

    left.appendChild(chev);
    left.appendChild(nameEl);
    left.appendChild(countEl);

    var badge = document.createElement('span');
    badge.className = 'badge ' + (anyVerified ? 'badge-visible' : 'badge-hidden');
    badge.textContent = anyVerified ? 'Visible on public site' : 'Hidden from public site';

    head.appendChild(left);
    head.appendChild(badge);

    var rowsWrap = document.createElement('div');
    rowsWrap.className = 'grp-rows';
    rows.forEach(function (r) {
      rowsWrap.appendChild(buildRow(r));
    });

    head.addEventListener('click', function () {
      var open = rowsWrap.classList.toggle('open');
      chev.classList.toggle('open', open);
    });

    card.appendChild(head);
    card.appendChild(rowsWrap);
    return card;
  }

  function render(roads) {
    var groups = {};
    var order = [];
    roads.forEach(function (r) {
      var key = groupKey(r.name);
      if (!groups[key]) {
        groups[key] = { displayName: r.name, rows: [] };
        order.push(key);
      }
      groups[key].rows.push(r);
    });
    order.sort(function (a, b) { return a.localeCompare(b); });

    var container = document.getElementById('groupsContainer');
    container.innerHTML = '';
    order.forEach(function (key) {
      var g = groups[key];
      container.appendChild(buildGroup(g.displayName, g.rows));
    });
  }

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
        render(data.roads);
      })
      .catch(function () {
        document.getElementById('loadingMsg').style.display = 'none';
        document.getElementById('errorMsg').style.display = 'block';
        document.getElementById('errorMsg').textContent = 'Network error — could not load roads.';
      });
  }

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
  <script src="../js/theme.js"></script>
</body>
</html>
