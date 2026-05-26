// ═══════════════════════════════════════════════════════════════
//  js/segment.js
//  Road & segment definition UI logic.
//  API endpoints used:
//    POST api/roads/create.php
//    POST api/roads/segments/save.php
//    GET  api/roads/segments/index.php?road_id=
//    POST api/audit-sessions/create.php
//    PUT  api/segments/complete.php
// ═══════════════════════════════════════════════════════════════

// ── State ──────────────────────────────────────────────────────
let roadData        = {};
let segments        = [];
let manualCount     = 0;
let _currentRoadId  = null;
window._currentSessionId = null;

// ── CSRF token ─────────────────────────────────────────────────
function getCsrf() {
  const meta = document.querySelector('meta[name="csrf"]');
  return meta ? meta.content : (window.__CSRF__ || '');
}

// ── Boot ───────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
  document.getElementById('segmentLength')
    .addEventListener('change', function () {
      document.getElementById('customLengthInput').style.display =
        this.value === 'custom' ? 'block' : 'none';
      updateAutoPreview();
    });
  document.getElementById('roadLength')
    .addEventListener('input', updateAutoPreview);
  document.getElementById('customSegmentLength')
    ?.addEventListener('input', updateAutoPreview);

  const params   = new URLSearchParams(window.location.search);
  const status   = params.get('status');
  const segId    = parseInt(params.get('segment_id') || '0');
  const roadIdQS = parseInt(params.get('road_id')    || '0');

  if (status === 'done' && segId) {
    const sessionIdQS2 = parseInt(params.get('session_id') || '0');
    const roadIdDone   = parseInt(params.get('road_id')    || '0');
    if (roadIdDone   > 0) _currentRoadId           = roadIdDone;
    if (sessionIdQS2 > 0) window._currentSessionId = sessionIdQS2;
    markSegmentCompleted(segId);
  } else if (roadIdQS > 0) {
    _currentRoadId = roadIdQS;
    ensureSession(roadIdQS).then(() => loadSegmentsFromDB(roadIdQS));
  } else {
    showRoadForm();
  }
});

// Re-sync when tab becomes visible again (surveyor switches back)
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && _currentRoadId) {
    loadSegmentsFromDB(_currentRoadId);
  }
});

// ── Ensure an audit session exists for this road ───────────────
async function ensureSession(roadId) {
  try {
    const data = await apiFetch('../api/audit-sessions/create.php', 'POST', { road_id: roadId });
    if (data.success) {
      window._currentSessionId = data.session_id;
    }
  } catch (e) {
    console.warn('ensureSession failed:', e);
  }
}

// ── Mark segment completed on return from audit form ──────────
async function markSegmentCompleted(segId) {
  try {
    if (window._currentSessionId) {
      await apiFetch('../api/segments/complete.php', 'PUT', {
        segment_id: segId,
        session_id: window._currentSessionId,
      });
    }
  } catch (e) {
    console.warn('markSegmentCompleted failed:', e);
  } finally {
    window.history.replaceState({}, '', 'segment.php');
    if (_currentRoadId) loadSegmentsFromDB(_currentRoadId);
    else showRoadForm();
  }
}

// ── Load segments from DB ──────────────────────────────────────
async function loadSegmentsFromDB(roadId) {
  try {
    const res  = await fetch(
      `../api/roads/segments/index.php?road_id=${roadId}`,
      { headers: { 'Accept': 'application/json' } }
    );
    const rawText = await res.text();
    let data;
    try { data = JSON.parse(rawText); }
    catch { console.error('loadSegmentsFromDB bad JSON:', rawText.slice(0, 200)); return; }

    if (data.success && data.road && data.segments.length > 0) {
      roadData = {
        id:            data.road.id,
        name:          data.road.name,
        start:         data.road.start_point,
        end:           data.road.end_point,
        length:        data.road.total_length,
        gpsStart:      data.road.gps_start,
        gpsEnd:        data.road.gps_end,
        method:        data.road.segment_method,
        segmentLength: data.road.segment_length,
      };
      segments = data.segments.map(s => ({
        id:            s.id,
        number:        s.segment_number,
        startDistance: s.start_distance,
        endDistance:   s.end_distance,
        length:        s.length,
        startLandmark: s.start_label  || '',
        endLandmark:   s.end_label    || '',
        status:        s.status,
        auditData:     s.status === 'completed'
                         ? { completedAt: s.completed_at }
                         : null,
      }));
      displaySegments();
    } else {
      showRoadForm();
    }
  } catch {
    showRoadForm();
  }
}

// ── Show blank road form ───────────────────────────────────────
function showRoadForm() {
  roadData = {}; segments = []; _currentRoadId = null;
  window._currentSessionId = null;

  ['roadName','roadStart','roadEnd','roadLength','roadGpsStart','roadGpsEnd']
    .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });

  selectMethod('auto');
  document.getElementById('segmentLength').value             = '200';
  document.getElementById('customLengthInput').style.display = 'none';
  document.getElementById('autoPreview').classList.remove('show');
  document.getElementById('manualSegmentsList').innerHTML    = '';
  manualCount = 0;
  updateManualEmpty();
  clearErrors();

  document.getElementById('roadSetupSection').style.display = 'block';
  document.getElementById('segmentsSection').style.display  = 'none';
  setStep(1);
}

// ── GPS helper ─────────────────────────────────────────────────
function getGPS(endpoint) {
  if (!navigator.geolocation) {
    showToast('Geolocation not supported.', 'error'); return;
  }
  showToast('Getting location…');
  navigator.geolocation.getCurrentPosition(
    pos => {
      const coord = `${pos.coords.latitude.toFixed(6)}, ${pos.coords.longitude.toFixed(6)}`;
      const id    = endpoint === 'start' ? 'roadGpsStart' : 'roadGpsEnd';
      document.getElementById(id).value = coord;
      showToast('Location captured!', 'success');
    },
    () => showToast('Location access denied.', 'error')
  );
}

// ── Validation ─────────────────────────────────────────────────
function clearErrors() {
  document.querySelectorAll('.field-error').forEach(el => el.classList.remove('show'));
  document.querySelectorAll('input.error, select.error')
          .forEach(el => el.classList.remove('error'));
}
function showFieldError(fieldId, errId) {
  document.getElementById(fieldId)?.classList.add('error');
  document.getElementById(errId)?.classList.add('show');
  return false;
}
function validateRoadFields() {
  clearErrors(); let valid = true;
  if (!document.getElementById('roadName').value.trim() ||
       document.getElementById('roadName').value === '__custom__')
    { showFieldError('roadName', 'err-roadName');   valid = false; }
  if (!document.getElementById('roadStart').value.trim())
    { showFieldError('roadStart', 'err-roadStart');  valid = false; }
  if (!document.getElementById('roadEnd').value.trim())
    { showFieldError('roadEnd', 'err-roadEnd');    valid = false; }
  const len = parseFloat(document.getElementById('roadLength').value);
  if (!len || len < 50)
    { showFieldError('roadLength', 'err-roadLength'); valid = false; }
  return valid;
}

// ── Method selection ───────────────────────────────────────────
function selectMethod(method) {
  ['auto','manual'].forEach(m => {
    document.getElementById(`opt-${m}`)?.classList.remove('selected');
  });
  document.getElementById(`opt-${method}`)?.classList.add('selected');
  document.getElementById('autoContent').classList.remove('active');
  document.getElementById('manualContent').classList.remove('active');
  document.getElementById(`${method}Content`).classList.add('active');
  if (method === 'auto') updateAutoPreview();
  updateManualEmpty();
}

// ── Auto preview ───────────────────────────────────────────────
function updateAutoPreview() {
  const roadLen = parseFloat(document.getElementById('roadLength').value) || 0;
  const sel     = document.getElementById('segmentLength').value;
  const segLen  = sel === 'custom'
    ? parseFloat(document.getElementById('customSegmentLength').value) || 0
    : parseFloat(sel);

  const preview = document.getElementById('autoPreview');
  if (!roadLen || !segLen) { preview.classList.remove('show'); return; }

  const count   = Math.ceil(roadLen / segLen);
  const lastLen = roadLen - (count - 1) * segLen;
  document.getElementById('previewCount').textContent  = count;
  document.getElementById('previewLength').textContent = `${segLen} m`;
  document.getElementById('previewLast').textContent   = `${lastLen.toFixed(1)} m`;
  preview.classList.add('show');
}

// ── Generate auto segments ─────────────────────────────────────
async function generateAutoSegments() {
  if (!validateRoadFields()) return;

  const sel     = document.getElementById('segmentLength').value;
  const segLen  = sel === 'custom'
    ? parseFloat(document.getElementById('customSegmentLength').value) || 0
    : parseFloat(sel);
  const roadLen = parseFloat(document.getElementById('roadLength').value);

  if (!segLen || segLen < 10) {
    showToast('Segment length must be at least 10 m.', 'error'); return;
  }

  const count   = Math.ceil(roadLen / segLen);
  const segsArr = [];
  for (let i = 0; i < count; i++) {
    const startD = i * segLen;
    const endD   = Math.min((i + 1) * segLen, roadLen);
    segsArr.push({
      segment_number: i + 1,
      start_label:    `${startD}m`,
      end_label:      `${endD}m`,
      start_distance: startD,
      end_distance:   endD,
      length:         parseFloat((endD - startD).toFixed(2)),
    });
  }

  const roadName = document.getElementById('roadName').value.trim();
  await saveRoadAndSegments(roadName, segsArr, 'auto', segLen);
}

// ── Manual segment UI ──────────────────────────────────────────
function addManualSegment() {
  manualCount++;
  const n    = manualCount;
  const list = document.getElementById('manualSegmentsList');
  const div  = document.createElement('div');
  div.className = 'manual-seg-row';
  div.id        = `mseg-${n}`;
  div.innerHTML = `
    <div class="mseg-num">${n}</div>
    <div class="mseg-fields">
      <input type="text"   placeholder="Start landmark" id="ms-sl-${n}">
      <input type="text"   placeholder="End landmark"   id="ms-el-${n}">
      <input type="number" placeholder="Start (m)"      id="ms-sd-${n}" min="0">
      <input type="number" placeholder="End (m)"        id="ms-ed-${n}" min="0">
    </div>
    <button style="background:#fee2e2;color:#dc2626;border:none;border-radius:6px;
                   padding:6px 10px;cursor:pointer;font-weight:700"
            onclick="removeManualSeg(${n})">✕</button>`;
  list.appendChild(div);
  updateManualCount();
  updateManualEmpty();
}

function removeManualSeg(n) {
  document.getElementById(`mseg-${n}`)?.remove();
  updateManualCount();
  updateManualEmpty();
}

function updateManualCount() {
  const rows = document.querySelectorAll('.manual-seg-row');
  document.getElementById('manualCountBadge').textContent =
    `${rows.length} segment${rows.length !== 1 ? 's' : ''}`;
}

function updateManualEmpty() {
  const rows = document.querySelectorAll('.manual-seg-row');
  const el   = document.getElementById('manualEmptyState');
  if (el) el.style.display = rows.length === 0 ? 'block' : 'none';
}

async function saveManualSegments() {
  if (!validateRoadFields()) return;

  const rows = document.querySelectorAll('.manual-seg-row');
  if (rows.length === 0) {
    showToast('Add at least one segment.', 'error'); return;
  }

  const segsArr = []; let valid = true;
  rows.forEach((row, idx) => {
    const n  = row.id.replace('mseg-', '');
    const sl = document.getElementById(`ms-sl-${n}`)?.value.trim() || '';
    const el = document.getElementById(`ms-el-${n}`)?.value.trim() || '';
    const sd = parseFloat(document.getElementById(`ms-sd-${n}`)?.value || '0');
    const ed = parseFloat(document.getElementById(`ms-ed-${n}`)?.value || '0');
    if (!sl || !el || isNaN(sd) || isNaN(ed) || ed <= sd) { valid = false; return; }
    segsArr.push({
      segment_number: idx + 1,
      start_label:    sl,
      end_label:      el,
      start_distance: sd,
      end_distance:   ed,
      length:         parseFloat((ed - sd).toFixed(2)),
    });
  });

  if (!valid) {
    showToast('Fill all segment fields. End must be greater than Start.', 'error'); return;
  }

  const roadName = document.getElementById('roadName').value.trim();
  const roadLen  = parseFloat(document.getElementById('roadLength').value);
  await saveRoadAndSegments(roadName, segsArr, 'manual', roadLen / segsArr.length);
}

// ── Core save: road → segments → session ──────────────────────
async function saveRoadAndSegments(roadName, segsArr, method, segmentLength) {
  showToast('Saving…');
  try {
    const roadPayload = {
      name:           roadName,
      start_point:    document.getElementById('roadStart').value.trim(),
      end_point:      document.getElementById('roadEnd').value.trim(),
      total_length:   parseFloat(document.getElementById('roadLength').value),
      gps_start:      document.getElementById('roadGpsStart').value.trim(),
      gps_end:        document.getElementById('roadGpsEnd').value.trim(),
      segment_method: method,
      segment_length: segmentLength,
    };

    let roadId;

    if (_currentRoadId) {
      // ── Edit mode: update existing road ──────────────────────
      roadPayload.road_id = _currentRoadId;
      const roadResp = await apiFetch('../api/roads/update.php', 'POST', roadPayload);
      if (!roadResp.success) {
        showToast(
          roadResp.errors ? roadResp.errors.join(' ') : (roadResp.error || 'Failed to update road.'),
          'error'
        );
        return;
      }
      roadId = _currentRoadId;
    } else {
      // ── Create mode: new road ─────────────────────────────────
      const roadResp = await apiFetch('../api/roads/create.php', 'POST', roadPayload);
      if (!roadResp.success) {
        showToast(
          roadResp.errors ? roadResp.errors.join(' ') : (roadResp.error || 'Failed to create road.'),
          'error'
        );
        return;
      }
      roadId = roadResp.road_id;
      _currentRoadId = roadId;
    }

    // 2. Save segments
    const segResp = await apiFetch('../api/roads/segments/save.php', 'POST', {
      road_id:  roadId,
      segments: segsArr,
    });

    if (!segResp.success) {
      showToast(segResp.error || 'Failed to save segments.', 'error'); return;
    }

    // 3. Create / resume audit session
    await ensureSession(roadId);

    // 4. Load and display
    await loadSegmentsFromDB(roadId);
    showToast(`Saved — ${segResp.segments_saved} segments created!`, 'success');

  } catch (e) {
    console.error(e);
    showToast('Network error. Please try again.', 'error');
  }
}

// ── Display segments view ──────────────────────────────────────
function displaySegments() {
  document.getElementById('roadSetupSection').style.display = 'none';
  document.getElementById('segmentsSection').style.display  = 'block';
  setStep(2);

  document.getElementById('roadNameDisplay').textContent  = roadData.name || '';
  document.getElementById('roadRouteDisplay').textContent =
    `${roadData.start || ''} → ${roadData.end || ''}`;

  const pills = document.getElementById('roadPills');
  pills.innerHTML = `
    <span class="pill">📏 ${roadData.length}m</span>
    <span class="pill">🔖 ${segments.length} segments</span>
    <span class="pill">${roadData.method === 'auto' ? '⚡ Auto' : '✏️ Manual'}</span>`;

  const done    = segments.filter(s => s.status === 'completed').length;
  const pending = segments.length - done;
  const pct     = segments.length ? Math.round((done / segments.length) * 100) : 0;
  const allDone = pending === 0 && segments.length > 0;

  document.getElementById('progressBar').style.width     = pct + '%';
  document.getElementById('progressPercent').textContent = pct + '%';
  document.getElementById('progressLabel').textContent   = `${done} of ${segments.length} completed`;

  const viewBtn = document.getElementById('viewResultsBtn');
  if (viewBtn) {
    viewBtn.disabled = !allDone;
    viewBtn.title    = allDone ? '' : `${pending} segment(s) still pending`;
  }

  document.getElementById('completionBanner').style.display =
    allDone ? 'block' : 'none';
  document.getElementById('blockedBanner').style.display    =
    (!allDone && segments.length > 0) ? 'block' : 'none';

  if (allDone) {
    document.getElementById('completionTitle').textContent =
      `All ${segments.length} segments audited ✓ — Road ready for final scoring.`;
  } else if (segments.length > 0) {
    document.getElementById('blockedText').textContent =
      `${pending} segment(s) still pending — complete all to unlock the result.`;
  }

  const list        = document.getElementById('segmentsList');
  list.innerHTML    = '';
  const totalPending = segments.filter(s => s.status !== 'completed').length;

  if (segments.length === 0) {
    list.innerHTML = '<div class="empty-state"><p>No segments found.</p></div>'; return;
  }

  segments.forEach(seg => {
    const isDone        = seg.status === 'completed';
    const isLastPending = !isDone && totalPending === 1;
    const card          = document.createElement('div');
    card.className      = `seg-list-card${isDone ? ' seg-done' : ''}`;

    let statusHtml;
    if (isDone) {
      const ts = seg.auditData?.completedAt
        ? `<span class="seg-timestamp">Audited ${formatTime(seg.auditData.completedAt)}</span>` : '';
      statusHtml = `<div class="status-col"><span class="status-chip status-completed">✓ Audited</span>${ts}</div>`;
    } else if (isLastPending) {
      statusHtml = `<div class="status-col"><span class="status-chip status-blocking">⚠ Last Remaining</span><span class="seg-timestamp">Blocks final result</span></div>`;
    } else {
      statusHtml = `<div class="status-col"><span class="status-chip status-pending">Pending</span><span class="seg-timestamp">Needed for final score</span></div>`;
    }

    card.innerHTML = `
      <div class="seg-num ${isDone ? 'done' : ''}">${isDone ? '✓' : seg.number}</div>
      <div class="seg-list-info">
        <div class="seg-list-name">Segment ${seg.number}: ${seg.startLandmark} → ${seg.endLandmark}</div>
        <div class="seg-list-meta">${seg.startDistance}m – ${seg.endDistance}m &nbsp;|&nbsp; ${seg.length.toFixed(0)}m</div>
      </div>
      ${statusHtml}
      <div class="seg-actions">
        ${isDone
          ? `<button class="btn btn-warning btn-sm" onclick="editAuditedSegment(${seg.id})">✏️ Edit</button>
             <button class="btn btn-secondary btn-sm" onclick="viewSegmentResult(${seg.id})">Results</button>`
          : `<button class="btn btn-primary btn-sm" onclick="auditSegment(${seg.id})">Start Audit</button>`}
      </div>`;
    list.appendChild(card);
  });
}

// ── Navigation ─────────────────────────────────────────────────
function auditSegment(segId) {
  const seg = segments.find(s => s.id === segId);
  if (!seg) return;
  if (seg.status === 'completed') {
    alert('This segment is already audited and locked.'); return;
  }
  const sessionParam = window._currentSessionId
    ? `&session_id=${window._currentSessionId}` : '';
  window.location.href = `form.php?segment_id=${segId}${sessionParam}`;
}

function editAuditedSegment(segId) {
  const seg = segments.find(s => s.id === segId);
  if (!seg) return;

  if (!confirm(
    `Re-open Segment ${seg.number} for editing?\n\n` +
    `Your previous answers will be loaded so you only need to correct what changed.`
  )) return;

  const csrf = document.querySelector('meta[name="csrf"]')?.content ?? '';

  fetch('../api/segments/unlock.php', {
    method : 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    body   : JSON.stringify({ segment_id: segId })
  })
  .then(r => r.text().then(t => { try { return JSON.parse(t); } catch { return {}; } }))
  .then(data => {
    if (data.success) {
      // Pass edit_mode=1 so form.js fetches and pre-fills existing answers
      const sessionParam = window._currentSessionId
        ? `&session_id=${window._currentSessionId}` : '';
      window.location.href =
        `form.php?segment_id=${segId}&edit_mode=1${sessionParam}`;
    } else {
      alert('Could not unlock segment: ' + (data.error ?? 'Unknown error'));
    }
  })
  .catch(() => alert('Network error — please try again.'));
}

function viewSegmentResult(segId) {
  // _currentRoadId is always set once segments load; fall back to URL param
  const params  = new URLSearchParams(window.location.search);
  const roadId  = _currentRoadId || params.get('road_id') || '';
  window.location.href = `view.php?segment_id=${segId}${roadId ? '&road_id=' + roadId : ''}`;
}

// ── Edit road ──────────────────────────────────────────────────
function editRoadInfo() {
  const done  = segments.filter(s => s.status === 'completed').length;
  const total = segments.length;
  let bodyMsg, warnMsg;

  if (done === 0) {
    bodyMsg = `You're about to edit <strong>${roadData.name}</strong>. No audits started yet.`;
    warnMsg = `⚠ Regenerating segments will reset the current segment list.`;
  } else if (done < total) {
    bodyMsg = `<strong>${done}/${total} segments</strong> of <strong>${roadData.name}</strong> have been audited.`;
    warnMsg = `⚠ Editing the road will delete all existing audit progress.`;
  } else {
    bodyMsg = `All <strong>${total} segments</strong> of <strong>${roadData.name}</strong> are fully audited.`;
    warnMsg = `⚠ Editing a fully audited road requires re-auditing all segments.`;
  }

  document.getElementById('editModalBody').innerHTML      = bodyMsg;
  document.getElementById('editModalWarning').textContent = warnMsg;
  document.getElementById('editModal').classList.add('open');
}

function closeEditModal()  { document.getElementById('editModal').classList.remove('open'); }

function confirmEditRoad() {
  closeEditModal();

  // Clear form state but keep _currentRoadId so save knows to UPDATE not INSERT
  const savedRoadId = _currentRoadId;

  showRoadForm();                // resets fields and _currentRoadId = null
  _currentRoadId = savedRoadId; // restore so saveRoadAndSegments uses update endpoint

  // Pre-populate all fields with existing road data
  if (roadData.name) {
    // Update hidden select
    const sel = document.getElementById('roadName');
    if (sel) {
      // Try to match a known option
      let matched = false;
      for (const opt of sel.options) {
        if (opt.value === roadData.name) { sel.value = roadData.name; matched = true; break; }
      }
      if (!matched) {
        // Custom road
        sel.value = '__custom__';
        const custInput = document.getElementById('customRoadName');
        if (custInput) { custInput.value = roadData.name; }
        document.getElementById('customRoadWrap')?.classList.add('open');
      }
      // Update visible search input
      const searchInput = document.getElementById('roadSearchInput');
      if (searchInput) {
        searchInput.value = matched ? roadData.name : '✦ Other / Custom Road';
        searchInput.classList.add('has-value');
      }
    }
  }
  if (roadData.start)  document.getElementById('roadStart').value    = roadData.start;
  if (roadData.end)    document.getElementById('roadEnd').value      = roadData.end;
  if (roadData.length) document.getElementById('roadLength').value   = roadData.length;
  if (roadData.gpsStart) document.getElementById('roadGpsStart').value = roadData.gpsStart;
  if (roadData.gpsEnd)   document.getElementById('roadGpsEnd').value   = roadData.gpsEnd;

  // Restore segmentation method
  if (roadData.method) {
    selectMethod(roadData.method);
    if (roadData.method === 'auto' && roadData.segmentLength) {
      const sel2 = document.getElementById('segmentLength');
      const knownValues = ['100','200','300','500'];
      const lenStr = String(roadData.segmentLength);
      if (knownValues.includes(lenStr)) {
        sel2.value = lenStr;
      } else {
        sel2.value = 'custom';
        document.getElementById('customLengthInput').style.display = 'block';
        const custLen = document.getElementById('customSegmentLength');
        if (custLen) custLen.value = roadData.segmentLength;
      }
      updateAutoPreview();
    }
  }
}

// ── Step indicator ─────────────────────────────────────────────
function setStep(n) {
  [1, 2, 3].forEach(i => {
    const el = document.getElementById(`step${i}`);
    if (!el) return;
    el.classList.remove('active', 'done');
    if      (i < n) el.classList.add('done');
    else if (i === n) el.classList.add('active');
  });
}

// ── Toast ──────────────────────────────────────────────────────
let _toastTimer;
function showToast(msg, type = '') {
  const t = document.getElementById('toast');
  if (!t) return;
  t.className = `toast ${type}`;
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(_toastTimer);
  _toastTimer = setTimeout(() => t.classList.remove('show'), 3200);
}

// ── Fetch helper with CSRF — always returns parsed JSON ────────
async function apiFetch(url, method, body) {
  const res = await fetch(url, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'Accept':       'application/json',
      'X-CSRF-Token': getCsrf(),
    },
    body: JSON.stringify(body),
  });

  const text = await res.text();
  let json;
  try {
    json = JSON.parse(text);
  } catch {
    console.error('Non-JSON response from', url, '— HTTP', res.status, '\n', text.slice(0, 300));
    json = { success: false, error: 'Server error (HTTP ' + res.status + '). Check Railway logs.' };
  }
  json.__status = res.status;
  json.__ok     = res.ok;
  return json;
}

// ── Timestamp formatter ────────────────────────────────────────
function formatTime(iso) {
  try {
    const d       = new Date(iso);
    const diffMin = Math.floor((Date.now() - d) / 60000);
    const diffHr  = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHr  / 24);
    if (diffMin < 1)   return 'just now';
    if (diffMin < 60)  return `${diffMin}m ago`;
    if (diffHr  < 24)  return `${diffHr}h ago`;
    if (diffDay === 1) return 'yesterday';
    return d.toLocaleDateString('en-IN');
  } catch { return ''; }
}