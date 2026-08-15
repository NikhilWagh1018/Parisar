/* js/dashboard.js — extracted from pages/dashboard.php */


// ── Load dashboard data ───────────────────────────────────────
async function loadDashboard() {
  try {
    const res  = await fetch('../api/dashboard/stats.php', {
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF }
    });
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); }
    catch { showToast('Unexpected server response. Please try again.', 'error'); return; }

    if (!data.success) {
      showToast('Failed to load dashboard data.', 'error');
      return;
    }

    // ── Stats (not rendered for admins — "My Activity" grid removed) ──
    if (document.getElementById('statGrid')) {
      document.getElementById('st-roads').textContent  = data.stats.total_roads;
      document.getElementById('st-segs').textContent   = data.stats.total_segments;
      document.getElementById('st-done').textContent   = data.stats.completed_segments;
      document.getElementById('st-active').textContent = data.stats.active_sessions;
      document.getElementById('st-streak').textContent = data.stats.current_streak;
    }

    // ── Roads table (not rendered for admins — "My Roads" card removed) ──
    const container = document.getElementById('roadsContainer');
    if (!container) return;

    if (data.roads.length === 0) {
      container.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon">🗺️</div>
          <p>No roads defined yet.<br>
          <a href="segment.php">Define your first road →</a></p>
        </div>`;
      return;
    }

    container.innerHTML = data.roads.map(road => {
      const total     = road.total_segments;
      const done      = road.completed_segments;
      const pct       = total > 0 ? Math.round((done / total) * 100) : 0;
      const sessClass = road.session_status === 'active'     ? 'sess-active'
                      : road.session_status === 'completed'  ? 'sess-completed'
                      : 'sess-none';
      const sessLabel = road.session_status
                      ? road.session_status.charAt(0).toUpperCase() + road.session_status.slice(1)
                      : 'No session';

      return `
        <div class="road-row">
          <div class="road-name-col">
            <div class="road-name-info">
              <strong>${escHtml(road.road_name)}</strong>
              <span>${road.road_public_id}</span>
            </div>
            <span class="sess-badge ${sessClass}">${sessLabel}</span>
          </div>
          <div class="road-meta">
            ${road.last_activity ? `<span>${formatDate(road.last_activity)}</span>` : '<span>No activity</span>'}
            ${road.total_length ? `<div class="dot"></div><span>${road.total_length} m</span>` : ''}
            <div class="dot"></div>
            <span>${done}/${total} segs</span>
          </div>
          <div class="prog-wrap">
            <div class="prog-row">
              <div class="prog-bar prog-track">
                <div class="prog-fill" data-w="${pct}%"></div>
              </div>
              <span class="prog-lbl">${pct}%</span>
            </div>
          </div>
          <div class="road-actions">
            ${road.is_finalized
              ? '' /* Finalized roads are locked and read-only — nothing to view, only Report/PDF matters. */
              : `<a class="action-btn btn-audit" href="segment.php?road_id=${road.road_id}">✏️ Audit</a>`}
            ${road.is_finalized
              ? `<a class="action-btn btn-report" href="report.php?session_id=${road.session_id}"><span>📄</span> Report</a>`
              : '<a class="action-btn btn-report" style="opacity:.4;pointer-events:none" title="Available after Final Submit">📄 Report</a>'}
            <button class="action-btn btn-delete" onclick="promptDelete(${road.road_id}, \`${escHtml(road.road_name)}\`)">🗑</button>
          </div>
        </div>`;
    }).join('');

    // Animate progress bars after render
    requestAnimationFrame(() => {
      document.querySelectorAll('.prog-fill').forEach(el => {
        el.style.width = el.dataset.w;
      });
    });

  } catch (err) {
    console.error(err);
    showToast('Network error. Please try again.', 'error');
  }
}

// ── Delete road ───────────────────────────────────────────────
let pendingDeleteId = null;

function promptDelete(roadId, roadName) {
  pendingDeleteId = roadId;
  document.getElementById('deleteModalMsg').textContent =
    `Delete "${roadName}" and all its segments, sessions and audit data? This cannot be undone.`;
  document.getElementById('deleteModal').classList.add('show');
}

function closeDeleteModal() {
  pendingDeleteId = null;
  document.getElementById('deleteModal').classList.remove('show');
}

document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {
  if (!pendingDeleteId) return;
  const roadIdToDelete = pendingDeleteId;  // capture before closeDeleteModal clears it
  closeDeleteModal();
  try {
    const res  = await fetch('../api/roads/delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ road_id: roadIdToDelete })
    });
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); }
    catch { showToast('Unexpected server response.', 'error'); return; }
    if (data.success) {
      showToast('Road deleted.', 'success');
      loadDashboard();
    } else {
      showToast(data.error || 'Delete failed.', 'error');
    }
  } catch {
    showToast('Network error.', 'error');
  }
});

// ── Helpers ───────────────────────────────────────────────────
function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function formatDate(iso) {
  try { return new Date(iso).toLocaleDateString('en-IN', { day:'numeric', month:'short', year:'numeric' }); }
  catch { return ''; }
}
function showToast(msg, type = '') {
  const wrap  = document.getElementById('toastWrap');
  const toast = document.createElement('div');
  toast.className = 'toast ' + type;
  toast.textContent = msg;
  wrap.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add('show'));
  setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 400); }, 3500);
}

// ── User menu popup ───────────────────────────────────────────
function toggleUserMenu() {
  const popup = document.getElementById('sbPopup');
  const btn   = document.getElementById('sbUserBtn');
  const open  = popup.classList.toggle('show');
  btn.classList.toggle('open', open);
}
// Close when clicking outside
document.addEventListener('click', e => {
  const popup = document.getElementById('sbPopup');
  if (!popup.classList.contains('show')) return;
  if (!document.getElementById('sbUserBtn').contains(e.target) &&
      !popup.contains(e.target)) {
    popup.classList.remove('show');
    document.getElementById('sbUserBtn').classList.remove('open');
  }
});

// ── Boot ──────────────────────────────────────────────────────
loadDashboard();
if (document.getElementById('adminOverview')) {
  loadAdminOverview();
}

// Show toast if returning from a successful audit
if (new URLSearchParams(location.search).get('audit') === 'done') {
  showToast('✅ Audit submitted successfully!', 'success');
  history.replaceState(null, '', location.pathname);
}

// ── Admin overview (org-wide stats / pending queue / activity) ──
async function loadAdminOverview() {
  try {
    const res  = await fetch('../api/admin/dashboard_overview.php', {
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF }
    });
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); }
    catch { return; } // fail quietly — admin section is supplementary

    if (!data.success) return;

    const s = data.org_stats;
    const pct = s.total_segments > 0
      ? Math.round((s.completed_segments / s.total_segments) * 100)
      : 0;

    document.getElementById('ao-roads').textContent      = s.total_roads;
    document.getElementById('ao-segs').textContent       = s.total_segments;
    document.getElementById('ao-done').textContent       = pct + '%';
    document.getElementById('ao-surveyors').textContent  = s.total_surveyors;

    // ── Recent activity feed ──
    const activityEl = document.getElementById('recentActivityContainer');
    if (data.recent_activity.length === 0) {
      activityEl.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon">🕒</div>
          <p>No recent activity yet.</p>
        </div>`;
    } else {
      activityEl.innerHTML = data.recent_activity.map(a => {
        const verb = a.action === 'segment_edited' ? 'edited' : 'submitted';
        const what = a.road_name
          ? `segment ${a.segment_number ?? ''} on <strong>${escHtml(a.road_name)}</strong>`
          : 'a segment';
        return `
          <div class="activity-row">
            <span class="activity-text">
              <strong>${escHtml(a.user_name || 'Someone')}</strong> ${verb} ${what}
            </span>
            <span class="activity-time">${formatDate(a.created_at)}</span>
          </div>`;
      }).join('');
    }

    // ── Pending verification queue ──
    const pendingEl = document.getElementById('pendingQueueContainer');
    if (data.pending_queue.length === 0) {
      pendingEl.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon">✅</div>
          <p>Nothing waiting on verification.</p>
        </div>`;
    } else {
      pendingEl.innerHTML = data.pending_queue.map(p => `
        <div class="pending-row">
          <div class="road-name-info">
            <strong>${escHtml(p.canonical_name)}</strong>
            <span>${p.member_count} road${p.member_count === 1 ? '' : 's'} · added ${formatDate(p.created_at)}</span>
          </div>
          <a class="btn-link" href="admin.php">Review →</a>
        </div>`).join('');
      if (s.pending_roads > data.pending_queue.length) {
        pendingEl.innerHTML += `
          <div class="pending-more">+ ${s.pending_roads - data.pending_queue.length} more waiting</div>`;
      }
    }

    // ── By surveyor / by organisation breakdowns ──
    renderBreakdownList('bySurveyorContainer', data.by_surveyor, sv => ({
      title: sv.name,
      subtitle: sv.organisation || 'Unspecified',
      count: sv.total
    }), '🏆', 'No audits recorded yet.');

    renderBreakdownList('byOrgContainer', data.by_organisation, og => ({
      title: og.organisation,
      subtitle: null,
      count: og.total
    }), '🏢', 'No audits recorded yet.');

    // ── Audits-over-time trend chart ──
    renderTrendChart(data.audits_over_time);

  } catch {
    // fail quietly — admin section is supplementary, not critical path
  }
}

// Renders a ranked list (used for by-surveyor / by-organisation cards).
// getFields(item) -> { title, subtitle|null, count }
function renderBreakdownList(containerId, items, getFields, emptyIcon, emptyText) {
  const el = document.getElementById(containerId);
  if (!items || items.length === 0) {
    el.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon">${emptyIcon}</div>
        <p>${escHtml(emptyText)}</p>
      </div>`;
    return;
  }
  el.innerHTML = items.map((item, i) => {
    const f = getFields(item);
    return `
      <div class="breakdown-row">
        <div class="breakdown-rank">${i + 1}</div>
        <div class="breakdown-info">
          <strong>${escHtml(f.title)}</strong>
          ${f.subtitle ? `<span>${escHtml(f.subtitle)}</span>` : ''}
        </div>
        <div class="breakdown-count">${f.count}</div>
      </div>`;
  }).join('');
}

// Builds a smoothed SVG path through a set of {x,y} points using the
// quadratic-bezier-through-midpoints technique. Chosen over Catmull-Rom
// because it never overshoots below/above neighboring points — important
// here since most values sit at (or near) zero.
function buildSmoothPath(points) {
  if (points.length === 0) return '';
  if (points.length === 1) return `M ${points[0].x},${points[0].y}`;
  let path = `M ${points[0].x},${points[0].y}`;
  for (let i = 1; i < points.length - 1; i++) {
    const midX = (points[i].x + points[i + 1].x) / 2;
    const midY = (points[i].y + points[i + 1].y) / 2;
    path += ` Q ${points[i].x},${points[i].y} ${midX},${midY}`;
  }
  const last = points[points.length - 1];
  path += ` Q ${last.x},${last.y} ${last.x},${last.y}`;
  return path;
}

// Renders a dependency-free SVG gradient area chart for the 30-day audit
// trend, with an animated draw-in and per-day hover tooltips.
function renderTrendChart(days) {
  const el = document.getElementById('trendContainer');
  if (!days || days.length === 0 || days.every(d => d.total === 0)) {
    el.innerHTML = `<div class="trend-empty">No audits recorded in the last 30 days.</div>`;
    return;
  }

  const W = 700, H = 130, padBottom = 16, padTop = 14;
  const max = Math.max(...days.map(d => d.total), 1);
  const scaleY = (H - padBottom - padTop) / max;
  const step = days.length > 1 ? W / (days.length - 1) : 0;
  const baseline = H - padBottom;

  const points = days.map((d, i) => ({
    x: i * step,
    y: baseline - d.total * scaleY,
    date: d.date,
    total: d.total,
  }));

  const linePath = buildSmoothPath(points);
  const areaPath = `${linePath} L ${points[points.length - 1].x},${baseline} L ${points[0].x},${baseline} Z`;

  // Show ~6 evenly-spaced date labels along the x-axis
  const labelStep = Math.ceil(days.length / 6);
  let labels = '';
  points.forEach((p, i) => {
    if (i % labelStep !== 0) return;
    const label = new Date(p.date + 'T00:00:00')
      .toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    labels += `<text class="trend-axis-label" x="${p.x.toFixed(1)}" y="${H - 2}" text-anchor="middle">${label}</text>`;
  });

  let dots = '';
  points.forEach((p, i) => {
    const leftPct = (points.length > 1 ? (p.x / W) * 100 : 50).toFixed(2);
    const topPct = ((p.y / H) * 100).toFixed(2);
    dots += `<div class="trend-dot" data-i="${i}" style="left:${leftPct}%;top:${topPct}%"></div>`;
  });

  el.innerHTML = `
    <svg class="trend-chart" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none">
      <defs>
        <linearGradient id="trendGradient" x1="0" y1="0" x2="0" y2="1">
          <stop class="trend-gradient-start" offset="0%"></stop>
          <stop class="trend-gradient-end" offset="100%"></stop>
        </linearGradient>
      </defs>
      <path class="trend-area" d="${areaPath}" fill="url(#trendGradient)"></path>
      <path class="trend-line" d="${linePath}" fill="none" vector-effect="non-scaling-stroke"></path>
      ${labels}
    </svg>
    <div class="trend-dots-layer">${dots}</div>
    <div class="trend-tooltip"></div>`;

  // Animate the line drawing in via stroke-dasharray/dashoffset.
  const lineEl = el.querySelector('.trend-line');
  const areaEl = el.querySelector('.trend-area');
  if (lineEl) {
    const len = lineEl.getTotalLength();
    lineEl.style.strokeDasharray = `${len}`;
    lineEl.style.strokeDashoffset = `${len}`;
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        lineEl.style.strokeDashoffset = '0';
        if (areaEl) areaEl.classList.add('is-visible');
      });
    });
  }

  // Wire up hover tooltips on the overlay dots (plain HTML divs, not SVG
  // circles, so they stay perfectly round regardless of the non-uniform
  // viewBox scaling from preserveAspectRatio="none").
  const tooltip = el.querySelector('.trend-tooltip');
  el.querySelectorAll('.trend-dot').forEach((dotEl) => {
    const p = points[parseInt(dotEl.dataset.i, 10)];
    const label = new Date(p.date + 'T00:00:00')
      .toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    const show = () => {
      tooltip.textContent = `${label}: ${p.total} audit${p.total === 1 ? '' : 's'}`;
      tooltip.style.left = dotEl.style.left;
      tooltip.style.top = dotEl.style.top;
      tooltip.classList.add('is-visible');
      dotEl.classList.add('is-active');
    };
    const hide = () => {
      tooltip.classList.remove('is-visible');
      dotEl.classList.remove('is-active');
    };
    dotEl.addEventListener('mouseenter', show);
    dotEl.addEventListener('mouseleave', hide);
  });
}