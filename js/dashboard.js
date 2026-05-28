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
      // ── Get-Started empty state ───────────────────────────────
      // Hide the stat cards so the first-time view isn't misleading
      document.getElementById('statGrid').style.display = 'none';

      container.innerHTML = `
        <div class="get-started-card">
          <div class="gs-icon">🚴</div>
          <h3 class="gs-title">Welcome to CycleAudit!</h3>
          <p class="gs-body">
            You haven't defined any roads yet.<br>
            Start by creating your first road — then assign segments and begin auditing.
          </p>
          <div class="gs-steps">
            <div class="gs-step"><span class="gs-num">1</span><span>Create a road with a name and group</span></div>
            <div class="gs-step"><span class="gs-num">2</span><span>Add segments along the route</span></div>
            <div class="gs-step"><span class="gs-num">3</span><span>Submit audit data for each segment</span></div>
            <div class="gs-step"><span class="gs-num">4</span><span>View the scored report</span></div>
          </div>
          <a href="segment.php" class="gs-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="18" height="18"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Create Your First Road
          </a>
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
            <strong>${escHtml(road.road_name)}</strong>
            <span>${road.road_public_id}</span>
          </div>
          <div class="prog-wrap">
            <div class="prog-track">
              <div class="prog-fill" data-w="${pct}%"></div>
            </div>
            <div class="prog-lbl">${done}/${total} segments</div>
          </div>
          <div>
            <span class="sess-badge ${sessClass}">${sessLabel}</span>
            ${road.last_activity
              ? `<div style="font-size:.68rem;color:var(--grl);margin-top:3px">${formatDate(road.last_activity)}</div>`
              : ''}
          </div>
          <div style="font-size:.8rem;color:var(--gray)">
            ${road.total_length ? road.total_length + ' m' : '—'}
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <a class="action-btn btn-audit" href="segment.php?road_id=${road.road_id}">
              ✏️ Audit
            </a>
            ${road.session_id
              ? `<a class="action-btn btn-report" href="report.php?session_id=${road.session_id}">📄 Report</a>`
              : ''}
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