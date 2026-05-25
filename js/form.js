// ═══════════════════════════════════════════════════════════════
//  js/form.js  —  Segment Audit Form Logic
//  Reads segment_id + session_id from URL params.
//  Submits to api/segments/submit.php with X-CSRF-Token header.
// ═══════════════════════════════════════════════════════════════

const urlParams  = new URLSearchParams(window.location.search);
const segmentId  = urlParams.get('segment_id');
const sessionId  = urlParams.get('session_id');
const editMode   = urlParams.get('edit_mode') === '1';

// road_id comes from a PHP-injected hidden field (not URL) since form.php looks it up
function getRoadId() {
  const el = document.getElementById('road_id');
  return el ? el.value : '';
}

// ── Inject hidden fields ───────────────────────────────────────
if (segmentId) {
  const h = document.getElementById('segment_id');
  if (h) h.value = segmentId;
}
if (sessionId) {
  const h = document.getElementById('session_id');
  if (h) h.value = sessionId;
}
// Persist edit_mode so submit.php knows to UPDATE, not INSERT
(function () {
  let hEdit = document.getElementById('edit_mode');
  if (!hEdit) {
    hEdit = document.createElement('input');
    hEdit.type = 'hidden';
    hEdit.name = 'edit_mode';
    hEdit.id   = 'edit_mode';
    const form = document.getElementById('auditForm');
    if (form) form.appendChild(hEdit);
  }
  hEdit.value = editMode ? '1' : '0';
})();

// ── CSRF token ─────────────────────────────────────────────────
function getCsrf() {
  const meta = document.querySelector('meta[name="csrf"]');
  return meta ? meta.content : (window.__CSRF__ || '');
}

// ── Obstruction option lists ───────────────────────────────────
const fixedOptions = [
  'Trees','Poles','CCTV','TrafficSignal','SignBoard',
  'TelephonePanel','ElectricalPanel','BusStand',
  'BuiltEncroachment','Bollards','PropertyEntrance','UtilityChambers',
];
const movableOptions = [
  'Hawkers','GarbageBins','ConstructionMaterial',
  'TrafficBarricade','PeopleSitting','Hoardings',
];
const parkedOptions = [
  'ReligiousLandmark','RestaurantEatery','AutoGarage',
  'CommercialRetailShops','OnStreetVending','PublicSpace',
];

// ── Dropdown open / close ──────────────────────────────────────
function openDropdown(type) {
  renderList(type, '');
  document.getElementById(type + 'List').classList.add('open');
  document.getElementById(type + 'Wrapper').classList.add('dropdown-open');
}
function closeDropdown(type) {
  document.getElementById(type + 'List').classList.remove('open');
  document.getElementById(type + 'Wrapper').classList.remove('dropdown-open');
}
document.addEventListener('click', e => {
  ['fixed','movable','parked'].forEach(type => {
    const wrapper = document.getElementById(type + 'Wrapper');
    if (wrapper && !wrapper.contains(e.target)) closeDropdown(type);
  });
});
function filterList(type, inputEl) {
  document.getElementById(type + 'List').classList.add('open');
  document.getElementById(type + 'Wrapper').classList.add('dropdown-open');
  renderList(type, inputEl.value.toLowerCase());
}
function renderList(type, filter) {
  const all       = type === 'fixed' ? fixedOptions
                  : type === 'movable' ? movableOptions : parkedOptions;
  const container = document.getElementById(type + 'List');
  container.innerHTML = '';
  const filtered  = all.filter(i => i.toLowerCase().includes(filter));
  if (!filtered.length) {
    container.insertAdjacentHTML('beforeend',
      '<div class="no-results">No results found</div>');
    return;
  }
  filtered.forEach(item => {
    const id       = type + '_' + item.replace(/\W/g, '_');
    const existing = document.getElementById(id);
    const checked  = existing ? existing.checked
                              : !!document.getElementById('block_' + id);
    container.insertAdjacentHTML('beforeend', `
      <div class="checkbox-item">
        <label>
          <input type="checkbox" id="${id}"
                 ${checked ? 'checked' : ''}
                 onchange="toggleObstruction('${type}','${item}')">
          ${item}
        </label>
      </div>`);
  });
}

// ════════════════════════════════════════════════════════════════
//  NUMERIC COUNTER ARCHITECTURE  (single source of truth)
//
//  Design principles:
//  • ONE oninput handler does ALL sanitisation — no keydown tricks,
//    no onfocus hacks, no competing listeners.
//  • The field is NEVER mutated during editing except to strip
//    non-digit characters.  Leading-zero removal is NOT deferred;
//    it is done inline but only when the result is unambiguous
//    (i.e. the string starts with 0 AND has more digits after it).
//  • Caret position is restored after every sanitisation so typing
//    feels native on desktop AND mobile / Android virtual keyboards
//    (which send key="Unidentified" — breaking any keydown approach).
//  • blur is the only place a missing value is snapped to "0".
//  • +/− buttons call adjustCounter which normalises via the same
//    numeric read used at submit time.
//  • No global state, no WeakMap, no closures per-field needed.
// ════════════════════════════════════════════════════════════════

// ── Internal helper: read a counter field as a safe integer ───
function _counterRead(el) {
  const n = parseInt(el.value, 10);
  return isFinite(n) && n >= 0 ? n : 0;
}

// ── +/− button handler ────────────────────────────────────────
// Reads, clamps, writes.  No mutation of an in-progress edit.
function adjustCounter(id, delta) {
  const el = document.getElementById(id);
  if (!el) return;
  el.value = Math.max(0, _counterRead(el) + delta);
}

// ── oninput: the ONE place that sanitises the raw typed string ─
//
// Allowed mid-edit values:   ""  "0"  "7"  "34"  "100"
// Forbidden:                 "03"  "007"  "-1"  "3.5"  "abc"
//
// Algorithm:
//   1. Strip every character that is not 0-9.
//   2. Strip leading zeros only when a non-zero digit follows
//      (i.e. "034" → "34", but "0" stays "0", "" stays "").
//   3. If the sanitised value differs from what the browser shows,
//      write it back and restore the caret at the correct position.
function counterInput(id) {
  const el = document.getElementById(id);
  if (!el) return;

  const raw    = el.value;
  const caret  = el.selectionStart;           // caret before any rewrite
  const oldLen = raw.length;

  // Step 1 – digits only
  let clean = raw.replace(/[^0-9]/g, '');

  // Step 2 – remove leading zeros (e.g. "034" → "34"), keep lone "0" and ""
  clean = clean.replace(/^0+([1-9])/, '$1');  // "034"→"34", "007"→"7"
  // "00" → "0"  (all-zeros collapsed)
  if (clean.length > 1 && /^0+$/.test(clean)) clean = '0';

  // Step 3 – write back only if something changed (avoids needless cursor jump)
  if (clean !== raw) {
    const removed   = oldLen - clean.length;
    el.value        = clean;
    const newCaret  = Math.max(0, caret - removed);
    try { el.setSelectionRange(newCaret, newCaret); } catch (_) { /* read-only */ }
  }
}

// ── onblur: normalise – empty or invalid → "0" ────────────────
function counterBlur(id) {
  const el = document.getElementById(id);
  if (!el) return;
  if (el.value === '' || !/^\d+$/.test(el.value)) el.value = '0';
}

// ── Template: builds a counter row ───────────────────────────
// Attributes used:
//   oninput   → counterInput  (sanitisation during typing)
//   onblur    → counterBlur   (normalise on leave)
//   onwheel   → prevent scroll-hijack
// NOT used:
//   onkeydown  (broken on Android virtual keyboards)
//   onfocus    (el.select() causes confusion when user clicks mid-value)
function makeCounter(id, labelText) {
  return `
    <div class="counter-row">
      <span class="counter-label">${labelText}</span>
      <div class="counter-ctrl">
        <button type="button" onclick="adjustCounter('${id}',-1)">−</button>
        <input type="text" inputmode="numeric" pattern="[0-9]*"
               id="${id}" name="${id}" value="0"
               oninput="counterInput('${id}')"
               onblur="counterBlur('${id}')"
               onwheel="event.preventDefault()">
        <button type="button" onclick="adjustCounter('${id}',1)">+</button>
      </div>
    </div>`;
}

// ── Obstruction toggle ─────────────────────────────────────────
function toggleObstruction(type, label) {
  const id        = type + '_' + label.replace(/\W/g, '_');
  const container = document.getElementById(type + 'Inputs');
  const cb        = document.getElementById(id);
  if (cb && cb.checked) {
    if (document.getElementById('block_' + id)) return;
    const block     = document.createElement('div');
    block.className = 'item-block';
    block.id        = 'block_' + id;
    block.innerHTML = `
      <div class="item-block-header">
        <span class="item-pin">📍</span>
        <strong>${label}</strong>
      </div>
      <div class="item-block-counters">
        ${makeCounter(id + '_slowed',  'Cyclist Slowed Down')}
        ${makeCounter(id + '_partial', 'Partial Obstruction')}
        ${makeCounter(id + '_total',   'Total Obstruction')}
      </div>`;
    container.appendChild(block);
  } else {
    document.getElementById('block_' + id)?.remove();
  }
}

// ── Missing Length field (decimal numeric, not a counter) ──────
// Allows digits and a single decimal point.  No leading zeros before decimal.
function missingLengthInput(el) {
  const raw   = el.value;
  const caret = el.selectionStart;
  const old   = raw.length;

  // Allow digits and a single dot
  let clean = raw.replace(/[^0-9.]/g, '');

  // Keep only the first decimal point
  const dotIdx = clean.indexOf('.');
  if (dotIdx !== -1) {
    clean = clean.slice(0, dotIdx + 1) + clean.slice(dotIdx + 1).replace(/\./g, '');
  }

  // Remove leading zeros before a digit (e.g. "05" → "5", but "0." → "0.")
  clean = clean.replace(/^0+([0-9])/, '$1');

  if (clean !== raw) {
    const removed  = old - clean.length;
    el.value       = clean;
    const newCaret = Math.max(0, caret - removed);
    try { el.setSelectionRange(newCaret, newCaret); } catch (_) {}
  }
}

function missingLengthBlur(el) {
  // Remove trailing dot  ("5." → "5")
  if (el.value.endsWith('.')) el.value = el.value.slice(0, -1);
  // Empty is fine for this optional field — don't snap to 0
}

// ── Missing Length toggle ──────────────────────────────────────
function toggleMissingLength(radio) {
  const box = document.getElementById('missingLengthBox');
  box.style.display = radio.value === 'Yes' ? 'block' : 'none';
  if (radio.value !== 'Yes') {
    document.getElementById('missingLength').value = '';
  }
}

// ── Intersections ──────────────────────────────────────────────
let intersections  = [];
let intUIDCounter  = 0;

function radioRow(name, values) {
  return values
    .map(v => `<label><input type="radio" name="${name}" value="${v}"> ${v}</label>`)
    .join('');
}

function buildIntersectionBody(uid) {
  const p = `int${uid}_`;
  return `
    <div class="int-gps-row">
      <div>
        <label>GPS Coordinates</label>
        <input type="text" id="${p}gps"  name="${p}gps"
               placeholder="e.g. 18.5204, 73.8567">
      </div>
      <div>
        <label>Landmark Name</label>
        <input type="text" id="${p}name" name="${p}name"
               placeholder="e.g. Near signal">
      </div>
    </div>
    <div class="int-fields">
      <div class="int-field">
        <label>Ramp off track</label>
        <div class="options">
          ${radioRow(p + 'offRamp', ['Comfortable','Uncomfortable','No Ramp'])}
        </div>
      </div>
      <div class="int-field">
        <label>Ramp back to track</label>
        <div class="options">
          ${radioRow(p + 'onRamp', ['Comfortable','Uncomfortable','No Ramp'])}
        </div>
      </div>
      <div class="int-field">
        <label>Markings</label>
        <div class="options">
          ${radioRow(p + 'Markings', ['Present','Absent'])}
        </div>
      </div>
      <div class="int-field">
        <label>Signage</label>
        <div class="options">
          ${radioRow(p + 'Signage', ['Present','Absent'])}
        </div>
      </div>
      <div class="int-field">
        <label>Traffic Calming Device</label>
        <div class="options">
          ${radioRow(p + 'TrafficCalming', ['Present','Absent'])}
        </div>
      </div>
      <div class="int-field">
        <label>Discontinuity</label>
        <div class="options">
          ${radioRow(p + 'Discontinuity', ['Yes','No','NA'])}
        </div>
      </div>
      <div class="int-field">
        <label>Tapering of Track Width at Intersection</label>
        <div class="options">
          ${radioRow(p + 'Tapering', ['Yes','No','NA'])}
        </div>
      </div>
      <div class="int-field">
        <label>Obstruction Type</label>
        <div class="options">
          ${radioRow(p + 'ObstructionType', ['Partial','Total','None'])}
        </div>
      </div>
    </div>
    <div class="int-divider"></div>
    <button type="button" class="btn-remove-int"
            onclick="removeIntersection(${uid})">
      <svg viewBox="0 0 24 24" fill="currentColor" width="13" height="13"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
      Remove
    </button>`;
}

function addIntersection() {
  intUIDCounter++;
  const uid  = intUIDCounter;
  intersections.push(uid);
  const card = document.createElement('div');
  card.className = 'intersection-card';
  card.id        = 'intCard_' + uid;
  card.innerHTML = `
    <div class="int-header open" id="intHeader_${uid}"
         onclick="toggleIntersection(${uid})">
      <div class="int-header-left">
        <div class="int-badge">${intersections.length}</div>
        <span class="int-title">Intersection ${intersections.length}</span>
        <span class="int-subtitle" id="intSubtitle_${uid}">
          — click to collapse
        </span>
      </div>
      <span class="int-chevron">▾</span>
    </div>
    <div class="int-body open" id="intBody_${uid}">
      ${buildIntersectionBody(uid)}
    </div>`;
  document.getElementById('intersectionsContainer').appendChild(card);
}

function toggleIntersection(uid) {
  const header = document.getElementById('intHeader_' + uid);
  const body   = document.getElementById('intBody_'   + uid);
  if (!header || !body) return;
  const isOpen = body.classList.contains('open');
  body.classList.toggle('open',   !isOpen);
  header.classList.toggle('open', !isOpen);
  const sub = document.getElementById('intSubtitle_' + uid);
  if (sub) sub.textContent = isOpen
    ? '— click to expand'
    : '— click to collapse';
}

function removeIntersection(uid) {
  document.getElementById('intCard_' + uid)?.remove();
  intersections = intersections.filter(id => id !== uid);
}

// ── Footpath score ─────────────────────────────────────────────
function updateFootpathScore() {
  const checked = document.querySelectorAll(
    'input[name="footpath_rating[]"]:checked').length;
  document.getElementById('footpathScore').textContent =
    (checked * 20) + '%';
}

// ── Form submit ────────────────────────────────────────────────
async function submitFullAudit() {
  if (!validateForm()) return;

  const form     = document.getElementById('auditForm');
  const formData = new FormData(form);

  // Build clean intersection payload
  const intersectionData = intersections.map(uid => {
    const p = 'int' + uid + '_';
    return {
      gps_coords:       document.getElementById(p + 'gps')?.value  || null,
      landmark_name:    document.getElementById(p + 'name')?.value || null,
      off_ramp:         document.querySelector(`input[name="${p}offRamp"]:checked`)?.value         || null,
      on_ramp:          document.querySelector(`input[name="${p}onRamp"]:checked`)?.value          || null,
      markings:         document.querySelector(`input[name="${p}Markings"]:checked`)?.value        || null,
      signage:          document.querySelector(`input[name="${p}Signage"]:checked`)?.value         || null,
      traffic_calming:  document.querySelector(`input[name="${p}TrafficCalming"]:checked`)?.value  || null,
      discontinuity:    document.querySelector(`input[name="${p}Discontinuity"]:checked`)?.value   || null,
      tapering:         document.querySelector(`input[name="${p}Tapering"]:checked`)?.value        || null,
      obstruction_type: document.querySelector(`input[name="${p}ObstructionType"]:checked`)?.value || null,
    };
  });
  formData.set('intersections', JSON.stringify(intersectionData));

  // Ensure session_id is in FormData (belt-and-suspenders over hidden field)
  if (sessionId) formData.set('session_id', sessionId);

  try {
    const response = await fetch('../api/segments/submit.php', {
      method:  'POST',
      headers: { 'X-CSRF-Token': getCsrf() },
      body:    formData,
    });

    const result = await response.json();

    if (result.success) {
      alert('Audit saved successfully!');
      // Back to segment.php — segment.js picks up ?status=done
      const roadId = getRoadId();
      window.location.href =
        `segment.php?segment_id=${segmentId}&status=done` +
        (sessionId ? `&session_id=${sessionId}` : '') +
        (roadId    ? `&road_id=${roadId}`        : '');
    } else {
      alert('Error: ' + (result.error || result.message || 'Unknown error'));
      console.error('Submit error:', result);
    }
  } catch (error) {
    console.error('Fetch failed:', error);
    alert('Failed to connect to server. Check that XAMPP is running.');
  }
}

// ── Validation ─────────────────────────────────────────────────
function validateForm() {
  let ok = true;
  ['startLandmark','endLandmark','gpsStart','gpsEnd'].forEach(id => {
    const val  = document.getElementById(id)?.value.trim();
    const wrap = document.getElementById('wrap-' + id);
    if (!val) { wrap?.classList.add('field-error'); ok = false; }
    else       { wrap?.classList.remove('field-error'); }
  });
  if (!ok) {
    document.querySelector('.field-error')
      ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  return ok;
}
function clearError(id) {
  document.getElementById(id)?.classList.remove('field-error');
}

// ── Progress bar ───────────────────────────────────────────────


// ── Reset / Confirm ────────────────────────────────────────────
function resetForm()    { document.getElementById('confirmOverlay').classList.add('active');    }
function closeConfirm() { document.getElementById('confirmOverlay').classList.remove('active'); }

async function doReset() {
  // Close the confirm overlay immediately
  closeConfirm();

  // Show a loading state on the reset button
  const resetBtn = document.querySelector('.btn-reset');
  const origText = resetBtn ? resetBtn.textContent : '';
  if (resetBtn) { resetBtn.textContent = '⏳ Resetting…'; resetBtn.disabled = true; }

  try {
    // ── 1. Clear audit data from the database ─────────────────
    const segId  = document.getElementById('segment_id')?.value || segmentId;
    const sessId = document.getElementById('session_id')?.value || sessionId;

    if (segId && sessId) {
      const resp = await fetch('../api/segments/reset.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': getCsrf(),
        },
        body: JSON.stringify({ segment_id: parseInt(segId), session_id: parseInt(sessId) }),
      });
      const result = await resp.json();
      if (!result.success) {
        console.error('Reset failed:', result.error);
        alert('Reset failed: ' + (result.error || 'Unknown error'));
        if (resetBtn) { resetBtn.textContent = origText; resetBtn.disabled = false; }
        return;
      }
    }

    // ── 2. Clear the form DOM in-place ─────────────────────────
    // Text inputs & textareas
    document.querySelectorAll('#auditForm input[type="text"], #auditForm input[type="number"], #auditForm textarea')
      .forEach(el => {
        if (el.id === 'segment_id' || el.id === 'session_id' || el.id === 'road_id' || el.name === 'edit_mode') return;
        el.value = el.name === 'signage_count' ? '0' : '';
      });

    // Radio buttons
    document.querySelectorAll('#auditForm input[type="radio"]').forEach(el => { el.checked = false; });

    // Checkboxes
    document.querySelectorAll('#auditForm input[type="checkbox"]').forEach(el => { el.checked = false; });

    // Obstruction tags & counter blocks
    ['fixed', 'movable', 'parked'].forEach(type => {
      const tags   = document.getElementById(type + 'Tags');
      const inputs = document.getElementById(type + 'Inputs');
      if (tags)   tags.innerHTML   = '';
      if (inputs) inputs.innerHTML = '';
      // Uncheck all checkboxes in the dropdown
      document.querySelectorAll(`#${type}List input[type="checkbox"]`).forEach(cb => { cb.checked = false; });
    });

    // Intersections
    const intContainer = document.getElementById('intersectionsContainer');
    if (intContainer) intContainer.innerHTML = '';

    // Missing length box
    const missingBox = document.getElementById('missingLengthBox');
    if (missingBox) missingBox.style.display = 'none';

    // Footpath score badge
    updateFootpathScore();

    // ── 3. Strip edit_mode from URL and remove the edit banner ─
    const hEdit = document.getElementById('edit_mode');
    if (hEdit) hEdit.value = '0';

    // Remove the edit-mode banner if present
    document.querySelectorAll('.form-page-heading ~ div').forEach(el => {
      if (el.style.background?.includes('#fff8e1')) el.remove();
    });

    // Update the URL to remove edit_mode without reloading
    const newUrl = new URL(window.location.href);
    newUrl.searchParams.delete('edit_mode');
    window.history.replaceState({}, '', newUrl.toString());

    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });

    // Show a brief success toast
    showResetToast('✅ Form cleared — all data has been reset.');

  } catch (err) {
    console.error('doReset error:', err);
    alert('An unexpected error occurred during reset. Please try again.');
  } finally {
    if (resetBtn) { resetBtn.textContent = origText; resetBtn.disabled = false; }
  }
}

function showResetToast(msg) {
  let toast = document.getElementById('resetToast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'resetToast';
    toast.style.cssText =
      'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);' +
      'background:#2d6a2d;color:#fff;padding:12px 24px;border-radius:8px;' +
      'font-size:14px;font-weight:500;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.25);' +
      'transition:opacity .3s ease;';
    document.body.appendChild(toast);
  }
  toast.textContent = msg;
  toast.style.opacity = '1';
  clearTimeout(toast._hideTimer);
  toast._hideTimer = setTimeout(() => { toast.style.opacity = '0'; }, 3000);
}

// ── Scroll to top ──────────────────────────────────────────────
window.addEventListener('scroll', () => {
  const btn = document.getElementById('scrollTopBtn');
  if (btn) btn.classList.toggle('visible', window.scrollY > 300);
});

// ── Pre-fill helpers ───────────────────────────────────────────

function prefillObstructions(obstructions) {
  if (!obstructions || !obstructions.length) return;
  obstructions.forEach(o => {
    const type    = o.category;   // 'fixed' | 'movable' | 'parked'
    const label   = o.type;       // e.g. 'Trees'
    const id      = type + '_' + label.replace(/\W/g, '_');

    // Tick the checkbox in the dropdown (renderList builds the DOM)
    const cb = document.getElementById(id);
    if (cb) {
      cb.checked = true;
      // Fire toggleObstruction to create the counter block
      toggleObstruction(type, label);
    }

    // Set counter values
    const setCounter = (suffix, val) => {
      const el = document.getElementById(id + suffix);
      if (el) el.value = val || 0;
    };
    setCounter('_slowed',  o.slowed);
    setCounter('_partial', o.partial);
    setCounter('_total',   o.total);
  });
}

function prefillIntersections(intersections) {
  if (!intersections || !intersections.length) return;
  intersections.forEach(saved => {
    addIntersection();                           // creates card, pushes uid
    const uid = intUIDCounter;                   // uid just assigned
    const p   = 'int' + uid + '_';

    const setVal = (id, val) => {
      const el = document.getElementById(id);
      if (el && val != null) el.value = val;
    };
    const setRadio = (name, val) => {
      if (!val) return;
      const el = document.querySelector(`input[name="${name}"][value="${val}"]`);
      if (el) el.checked = true;
    };

    setVal(p + 'gps',  saved.gps_coords);
    setVal(p + 'name', saved.landmark_name);
    setRadio(p + 'offRamp',         saved.off_ramp);
    setRadio(p + 'onRamp',          saved.on_ramp);
    setRadio(p + 'Markings',        saved.markings);
    setRadio(p + 'Signage',         saved.signage);
    setRadio(p + 'TrafficCalming',  saved.traffic_calming);
    setRadio(p + 'Discontinuity',   saved.discontinuity);
    setRadio(p + 'Tapering',        saved.tapering);
    setRadio(p + 'ObstructionType', saved.obstruction_type);
  });
}

async function prefillFormIfEditMode() {
  if (!editMode || !segmentId) return;

  // Show a subtle banner so the user knows data was loaded
  const heading = document.querySelector('.form-page-heading');
  if (heading) {
    const banner = document.createElement('div');
    banner.style.cssText =
      'background:#fff8e1;border:1px solid #f6c90e;border-radius:8px;' +
      'padding:10px 16px;margin-bottom:16px;font-size:13px;color:#7a5c00;';
    banner.innerHTML =
      '✏️ <strong>Edit mode</strong> — your previous answers have been loaded. ' +
      'Review, correct anything, then re-submit.';
    heading.insertAdjacentElement('afterend', banner);
  }

  try {
    const csrf = getCsrf();
    const res  = await fetch(
      `../api/segments/audit-data.php?segment_id=${segmentId}`,
      { headers: { 'X-CSRF-Token': csrf } }
    );
    const data = await res.json();
    if (!data.success || !data.audit) return;

    const a = data.audit;

    // ── Text inputs ─────────────────────────────────────────
    const setVal = (id, val) => {
      const el = document.getElementById(id);
      if (el && val != null) el.value = val;
    };
    setVal('startLandmark', a.start_landmark);
    setVal('endLandmark',   a.end_landmark);
    setVal('gpsStart',      a.gps_start);
    setVal('gpsEnd',        a.gps_end);
    setVal('missingLength', a.missing_length);
    setVal('signageCount',  a.signage_count);

    // ── Radio buttons ────────────────────────────────────────
    const setRadio = (name, val) => {
      if (!val) return;
      const el = document.querySelector(`input[name="${name}"][value="${val}"]`);
      if (el) {
        el.checked = true;
        el.dispatchEvent(new Event('change'));   // trigger any onchange handlers
      }
    };
    setRadio('cycle_track_missing', a.cycle_track_missing);
    setRadio('cyclist_use',         a.cyclist_use);
    setRadio('better_surface',      a.better_surface);
    setRadio('surface_material',    a.surface_material);
    setRadio('people_walking',      a.people_walking);
    setRadio('shade',               a.shade);
    setRadio('light_after_sunset',  a.light_after_sunset);
    setRadio('track_geometry',      a.track_geometry);
    setRadio('buffer_zone',         a.buffer_zone);

    // ── Checkboxes (surface_issues, overhead_issues, footpath_rating)
    const tickCheckboxes = (name, values) => {
      if (!Array.isArray(values)) return;
      values.forEach(val => {
        const el = document.querySelector(
          `input[name="${name}"][value="${val}"]`
        );
        if (el) {
          el.checked = true;
          el.dispatchEvent(new Event('change'));
        }
      });
    };
    tickCheckboxes('surface_issues[]',  a.surface_issues);
    tickCheckboxes('overhead_issues[]', a.overhead_issues);
    tickCheckboxes('footpath_rating[]', a.footpath_rating);
    updateFootpathScore();

    // ── Dimensions / comments ────────────────────────────────
    const setByName = (name, val) => {
      const el = document.querySelector(`[name="${name}"]`);
      if (el && val != null) el.value = val;
    };
    setByName('segment_width',  a.segment_width);
    setByName('segment_length', a.segment_length);
    setByName('comments',       a.comments);

    // ── Obstructions ─────────────────────────────────────────
    prefillObstructions(data.obstructions);

    // ── Intersections ────────────────────────────────────────
    prefillIntersections(data.intersections);

  } catch (err) {
    console.warn('Could not pre-fill form:', err);
  }
}

// ── Radio toggle (click same radio again to uncheck) ───────────
(function initRadioToggle() {
  // When a <label> wraps a <input type="radio"> (no `for` attr),
  // clicking the label fires: mousedown(label) → mousedown(input) → click(input) → click(label)
  // We must snapshot on the FIRST mousedown (label) and act on the LAST click (label).
  // Clicking the circle directly fires: mousedown(input) → click(input) only.

  function getRadio(el) {
    if (el.type === 'radio') return el;
    const label = el.tagName === 'LABEL' ? el : el.closest('label');
    if (!label) return null;
    return label.htmlFor
      ? document.getElementById(label.htmlFor)
      : label.querySelector('input[type="radio"]');
  }

  // Snapshot on mousedown — earliest possible moment
  document.addEventListener('mousedown', e => {
    const radio = getRadio(e.target);
    if (radio && !radio._snapDone) {
      radio._wasChecked = radio.checked;
      // Mark so the synthetic second mousedown (on the input itself) doesn't overwrite
      radio._snapDone = true;
      // Clear the guard after the click sequence ends
      setTimeout(() => { radio._snapDone = false; }, 300);
    }
  }, true);

  // Act on click — use capture so we run before any other handler
  document.addEventListener('click', e => {
    const radio = getRadio(e.target);
    if (!radio) return;

    // Only act on the outermost trigger (label click or direct input click)
    // Ignore the synthetic input click that the browser fires after a label click
    if (e.target.type !== 'radio' && e.target.tagName !== 'LABEL' && !e.target.closest('label')) return;

    if (radio._wasChecked) {
      // Prevent the browser from re-checking it
      e.preventDefault();
      radio.checked = false;
      radio._wasChecked = false;
      radio.dispatchEvent(new Event('change', { bubbles: true }));
    } else {
      radio._wasChecked = false;
    }
  }, true);
})();

// ── Init ───────────────────────────────────────────────────────
['fixed','movable','parked'].forEach(t => renderList(t, ''));
prefillFormIfEditMode();
