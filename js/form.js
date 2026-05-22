// ═══════════════════════════════════════════════════════════════
//  js/form.js  —  Segment Audit Form Logic
//  Reads segment_id + session_id from URL params.
//  Submits to api/segments/submit.php with X-CSRF-Token header.
// ═══════════════════════════════════════════════════════════════

const urlParams  = new URLSearchParams(window.location.search);
const segmentId  = urlParams.get('segment_id');
const sessionId  = urlParams.get('session_id');

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

// ── Counter controls ───────────────────────────────────────────
function adjustCounter(id, delta) {
  const el = document.getElementById(id);
  if (!el) return;
  el.value = Math.max(0, (parseInt(el.value) || 0) + delta);
}
function clampCounter(id) {
  const el = document.getElementById(id);
  if (!el) return;
  if (isNaN(parseInt(el.value)) || parseInt(el.value) < 0) el.value = 0;
}
function makeCounter(id, labelText) {
  return `
    <div class="counter-row">
      <span class="counter-label">${labelText}</span>
      <div class="counter-ctrl">
        <button type="button" onclick="adjustCounter('${id}',-1)">−</button>
        <input type="number" id="${id}" name="${id}" value="0" min="0"
               oninput="clampCounter('${id}')">
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

// ── Missing length toggle ──────────────────────────────────────
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
      gps_coords:    document.getElementById(p + 'gps')?.value  || null,
      landmark_name: document.getElementById(p + 'name')?.value || null,
      off_ramp:  document.querySelector(`input[name="${p}offRamp"]:checked`)?.value  || null,
      on_ramp:   document.querySelector(`input[name="${p}onRamp"]:checked`)?.value   || null,
      markings:  document.querySelector(`input[name="${p}Markings"]:checked`)?.value || null,
      signage:   document.querySelector(`input[name="${p}Signage"]:checked`)?.value  || null,
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
function resetForm()    { document.getElementById('confirmOverlay').classList.add('show');    }
function closeConfirm() { document.getElementById('confirmOverlay').classList.remove('show'); }
function doReset()      { location.reload(); }

// ── Scroll to top ──────────────────────────────────────────────
window.addEventListener('scroll', () => {
  const btn = document.getElementById('scrollTopBtn');
  if (btn) btn.classList.toggle('visible', window.scrollY > 300);
});

// ── Init ───────────────────────────────────────────────────────
['fixed','movable','parked'].forEach(t => renderList(t, ''));
