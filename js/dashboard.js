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

    // ── Stats ──────────────────────────────────────────────────
    document.getElementById('st-roads').textContent  = data.stats.total_roads;
    document.getElementById('st-segs').textContent   = data.stats.total_segments;
    document.getElementById('st-done').textContent   = data.stats.completed_segments;
    document.getElementById('st-active').textContent = data.stats.active_sessions;

    // ── Roads table ────────────────────────────────────────────
    const container = document.getElementById('roadsContainer');

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
            <div>
              <strong>${escHtml(road.road_name)}</strong>
              <span>${road.road_public_id}</span>
            </div>
            <span class="sess-badge ${sessClass}">${sessLabel}</span>
          </div>
          <div class="road-meta">
            ${road.last_activity ? `<span>${formatDate(road.last_activity)}</span>` : '<span>No activity</span>'}
            ${road.total_length ? `<div class="dot"></div><span>${road.total_length} m</span>` : ''}
          </div>
          <div class="prog-wrap">
            <div class="prog-row">
              <div class="prog-bar prog-track">
                <div class="prog-fill" data-w="${pct}%"></div>
              </div>
              <span class="prog-lbl">${done}/${total} segments</span>
            </div>
          </div>
          <div class="road-actions">
            <a class="action-btn btn-audit" href="segment.php?road_id=${road.road_id}">✏️ Audit</a>
            ${road.session_id
              ? `<a class="action-btn btn-report" href="report.php?session_id=${road.session_id}">📄 Report</a>`
              : '<a class="action-btn btn-report" style="opacity:.4;pointer-events:none">📄 Report</a>'}
            <button class="action-btn btn-delete" onclick="promptDelete(${road.road_id}, '${escHtml(road.road_name)}')">🗑</button>
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

// Show toast if returning from a successful audit
if (new URLSearchParams(location.search).get('audit') === 'done') {
  showToast('✅ Audit submitted successfully!', 'success');
  history.replaceState(null, '', location.pathname);
}