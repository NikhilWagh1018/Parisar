// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
//  js/form.js  â€”  Segment Audit Form Logic
//  Reads segment_id + session_id from URL params.
//  Submits to api/segments/submit.php with X-CSRF-Token header.
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

const urlParams  = new URLSearchParams(window.location.search);
const segmentId  = urlParams.get('segment_id');
const sessionId  = urlParams.get('session_id');
const editMode   = urlParams.get('edit_mode') === '1';

// road_id comes from a PHP-injected hidden field (not URL) since form.php looks it up
function getRoadId() {
  const el = document.getElementById('road_id');
  return el ? el.value : '';
}

// â”€â”€ Inject hidden fields â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ CSRF token â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function getCsrf() {
  const meta = document.querySelector('meta[name="csrf"]');
  return meta ? meta.content : (window.__CSRF__ || '');
}

// â”€â”€ Obstruction option lists â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ Dropdown open / close â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
//  NUMERIC COUNTER ARCHITECTURE  (single source of truth)
//
//  Design principles:
//  â€¢ ONE oninput handler does ALL sanitisation â€” no keydown tricks,
//    no onfocus hacks, no competing listeners.
//  â€¢ The field is NEVER mutated during editing except to strip
//    non-digit characters.  Leading-zero removal is NOT deferred;
//    it is done inline but only when the result is unambiguous
//    (i.e. the string starts with 0 AND has more digits after it).
//  â€¢ Caret position is restored after every sanitisation so typing
//    feels native on desktop AND mobile / Android virtual keyboards
//    (which send key="Unidentified" â€” breaking any keydown approach).
//  â€¢ blur is the only place a missing value is snapped to "0".
//  â€¢ +/âˆ’ buttons call adjustCounter which normalises via the same
//    numeric read used at submit time.
//  â€¢ No global state, no WeakMap, no closures per-field needed.
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
//  NUMERIC COUNTER ARCHITECTURE  v3 â€” blank-display / zero-store
//
//  Display contract:
//  â€¢ Fields are VISUALLY BLANK by default â€” value="" in HTML
//  â€¢ Blank display = numeric value 0 (converted at submit/button)
//  â€¢ Typing is uncontrolled mid-edit: only non-digits are stripped.
//  â€¢ Leading zeros are collapsed on blur, never during typing.
//
//  Event model â€” ONE handler per concern, zero overlap:
//    oninput  â†’ counterInput   strips non-digit chars only
//    onblur   â†’ counterBlur    collapses leading zeros, blank stays blank
//    onclick  â†’ adjustCounter  +/âˆ’ buttons, blank-aware
//
//  Blank-to-zero happens ONLY at:
//    â€¢ adjustCounter: reads blank as 0 before applying delta
//    â€¢ submitFullAudit: _numVal() reads blank as 0 in FormData
//    â€¢ PHP backend: (int)$_POST[field] coerces "" to 0
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

// â”€â”€ Safe integer read â€” blank/invalid treated as 0 â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”
function _numVal(el) {
  if (!el) return 0;
  const n = parseInt(el.value, 10);
  return Number.isFinite(n) && n >= 0 ? n : 0;
}

// â”€â”€ +/âˆ’ button handler â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”
// blank + (+) â†’ "1"     "1" + (âˆ’) â†’ blank (0 shown as blank)
function adjustCounter(id, delta) {
  const el = document.getElementById(id);
  if (!el) return;
  const next = Math.max(0, _numVal(el) + delta);
  el.value = next === 0 ? '' : String(next);
}

// â”€â”€ oninput: strip non-digits ONLY â€” never reformat mid-type â€”â€”â€”â€”â€”
// "034" is intentionally NOT collapsed here â€” the user might still
// be typing.  Collapse happens on blur only.
function counterInput(id) {
  const el = document.getElementById(id);
  if (!el) return;

  const raw    = el.value;
  const caret  = el.selectionStart;
  const oldLen = raw.length;

  const clean = raw.replace(/[^0-9]/g, '');

  if (clean !== raw) {
    el.value = clean;
    const newCaret = Math.max(0, caret - (oldLen - clean.length));
    try { el.setSelectionRange(newCaret, newCaret); } catch (_) {}
  }
}

// â”€â”€ onblur: collapse leading zeros; blank stays blank â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”
// "034" â†’ "34"   "007" â†’ "7"   "0" â†’ blank   "" â†’ blank
function counterBlur(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const n = parseInt(el.value, 10);
  el.value = (Number.isFinite(n) && n > 0) ? String(n) : '';
}

// â”€â”€ Template: counter row with blank-default input â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”â€”
function makeCounter(id, labelText) {
  return `
    <div class="counter-row">
      <span class="counter-label">${labelText}</span>
      <div class="counter-ctrl">
        <button type="button" onclick="adjustCounter('${id}',-1)">âˆ’</button>
        <input type="text" inputmode="numeric" pattern="[0-9]*"
               id="${id}" name="${id}" value=""
               placeholder="0"
               oninput="counterInput('${id}')"
               onblur="counterBlur('${id}')"
               onwheel="event.preventDefault()">
        <button type="button" onclick="adjustCounter('${id}',1)">+</button>
      </div>
    </div>`;
}

// â”€â”€ Obstruction toggle â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
        <span class="item-pin">ðŸ“</span>
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

// â”€â”€ Missing Length field (decimal numeric, not a counter) â”€â”€â”€â”€â”€â”€
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

  // Remove leading zeros before a digit (e.g. "05" â†’ "5", but "0." â†’ "0.")
  clean = clean.replace(/^0+([0-9])/, '$1');

  if (clean !== raw) {
    const removed  = old - clean.length;
    el.value       = clean;
    const newCaret = Math.max(0, caret - removed);
    try { el.setSelectionRange(newCaret, newCaret); } catch (_) {}
  }
}

function missingLengthBlur(el) {
  // Remove trailing dot  ("5." â†’ "5")
  if (el.value.endsWith('.')) el.value = el.value.slice(0, -1);
  // Empty is fine for this optional field â€” don't snap to 0
}

// â”€â”€ Missing Length toggle â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function toggleMissingLength(radio) {
  const box = document.getElementById('missingLengthBox');
  box.style.display = radio.value === 'Yes' ? 'block' : 'none';
  if (radio.value !== 'Yes') {
    document.getElementById('missingLength').value = '';
  }
}

// â”€â”€ Intersections â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
               <div class="gps-input-row">
               placeholder="e.g. 18.5204, 73.8567">
                 <button type="button" class="gps-btn" onclick="fillGPS('${p}gps')"
                         title="Auto-fill from device location">
                   <span class="gps-btn-icon">GPS</span>
                 </button>
               </div>
               <div class="gps-error" id="${p}gps-error"></div>
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
          â€” click to collapse
        </span>
      </div>
      <span class="int-chevron">â–¾</span>
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
    ? 'â€” click to expand'
    : 'â€” click to collapse';
}

function removeIntersection(uid) {
  document.getElementById('intCard_' + uid)?.remove();
  intersections = intersections.filter(id => id !== uid);
}

// â”€â”€ Footpath score â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function updateFootpathScore() {
  const checked = document.querySelectorAll(
    'input[name="footpath_rating[]"]:checked').length;
  document.getElementById('footpathScore').textContent =
    (checked * 20) + '%';
}

// â”€â”€ Form submit â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
        FormStateManager.clear();
      alert('Audit saved successfully!');
      // Back to segment.php â€” segment.js picks up ?status=done
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
    alert('Network error. Please try again.');
  }
}

// â”€â”€ Validation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€


// ═══════════════════════════════════════════════════════
//  GPS Auto-fill
// ═══════════════════════════════════════════════════════
function fillGPS(inputId) {
  const input   = document.getElementById(inputId);
  const errorEl = document.getElementById(inputId + '-error');
  const btn     = input?.closest('.gps-input-row')?.querySelector('.gps-btn');
  if (!input) return;
  if (errorEl) errorEl.textContent = '';
  if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
    if (errorEl) errorEl.textContent = 'GPS requires HTTPS. Enter coordinates manually.';
    return;
  }
  if (!navigator.geolocation) {
    if (errorEl) errorEl.textContent = 'Geolocation not supported by this browser.';
    return;
  }
  if (btn) { btn.disabled = true; btn.querySelector('.gps-btn-icon').textContent = '...'; }
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const lat = pos.coords.latitude.toFixed(6);
      const lng = pos.coords.longitude.toFixed(6);
      input.value = lat + ', ' + lng;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      if (btn) { btn.disabled = false; btn.querySelector('.gps-btn-icon').textContent = 'GPS'; }
    },
    (err) => {
      const msgs = {
        1: 'Location access denied. Allow location in browser settings.',
        2: 'Location unavailable. Enter coordinates manually.',
        3: 'Location request timed out. Try again.'
      };
      if (errorEl) errorEl.textContent = msgs[err.code] || 'Could not get location.';
      if (btn) { btn.disabled = false; btn.querySelector('.gps-btn-icon').textContent = 'GPS'; }
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
  );
}

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

// â”€â”€ Progress bar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€


// â”€â”€ Reset / Confirm â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function resetForm()    { document.getElementById('confirmOverlay').classList.add('active');    }
function closeConfirm() { document.getElementById('confirmOverlay').classList.remove('active'); }

async function doReset() {
  // Close the confirm overlay immediately
  closeConfirm();

  // Show a loading state on the reset button
  const resetBtn = document.querySelector('.btn-reset');
  const origText = resetBtn ? resetBtn.textContent : '';
  if (resetBtn) { resetBtn.textContent = 'â³ Resettingâ€¦'; resetBtn.disabled = true; }

  try {
    // â”€â”€ 1. Clear audit data from the database â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

    // â”€â”€ 2. Clear the form DOM in-place â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Text inputs & textareas
    document.querySelectorAll('#auditForm input[type="text"], #auditForm input[type="number"], #auditForm textarea')
      .forEach(el => {
        if (el.id === 'segment_id' || el.id === 'session_id' || el.id === 'road_id' || el.name === 'edit_mode') return;
        el.value = '';
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

    // â”€â”€ 3. Strip edit_mode from URL and remove the edit banner â”€
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
    showResetToast('âœ… Form cleared â€” all data has been reset.');

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

// â”€â”€ Scroll to top â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
window.addEventListener('scroll', () => {
  const btn = document.getElementById('scrollTopBtn');
  if (btn) btn.classList.toggle('visible', window.scrollY > 300);
});

// â”€â”€ Pre-fill helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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
      // Blank represents 0 in display; only show a value if it is genuinely > 0
      if (el) el.value = (val && parseInt(val, 10) > 0) ? String(parseInt(val, 10)) : '';
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
      'âœï¸ <strong>Edit mode</strong> â€” your previous answers have been loaded. ' +
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

    // â”€â”€ Text inputs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    const setVal = (id, val) => {
      const el = document.getElementById(id);
      if (el && val != null) el.value = val;
    };
    setVal('startLandmark', a.start_landmark);
    setVal('endLandmark',   a.end_landmark);
    setVal('gpsStart',      a.gps_start);
    setVal('gpsEnd',        a.gps_end);
    setVal('missingLength', a.missing_length);
    // Counter fields: show blank for 0, show value only if > 0
    const setCounter = (id, val) => {
      const el = document.getElementById(id);
      if (!el) return;
      const n = parseInt(val, 10);
      el.value = (Number.isFinite(n) && n > 0) ? String(n) : '';
    };
    setCounter('signageCount', a.signage_count);

    // â”€â”€ Radio buttons â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

    // â”€â”€ Checkboxes (surface_issues, overhead_issues, footpath_rating)
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

    // â”€â”€ Dimensions / comments â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    const setByName = (name, val) => {
      const el = document.querySelector(`[name="${name}"]`);
      if (el && val != null) el.value = val;
    };
    setByName('segment_width',  a.segment_width);
    setByName('segment_length', a.segment_length);
    setByName('comments',       a.comments);

    // â”€â”€ Obstructions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    prefillObstructions(data.obstructions);

    // â”€â”€ Intersections â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    prefillIntersections(data.intersections);

  } catch (err) {
    console.warn('Could not pre-fill form:', err);
  }
}

// â”€â”€ Radio toggle (click same radio again to uncheck) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
(function initRadioToggle() {
  // When a <label> wraps a <input type="radio"> (no `for` attr),
  // clicking the label fires: mousedown(label) â†’ mousedown(input) â†’ click(input) â†’ click(label)
  // We must snapshot on the FIRST mousedown (label) and act on the LAST click (label).
  // Clicking the circle directly fires: mousedown(input) â†’ click(input) only.

  function getRadio(el) {
    if (el.type === 'radio') return el;
    const label = el.tagName === 'LABEL' ? el : el.closest('label');
    if (!label) return null;
    return label.htmlFor
      ? document.getElementById(label.htmlFor)
      : label.querySelector('input[type="radio"]');
  }

  // Snapshot on mousedown â€” earliest possible moment
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

  // Act on click â€” use capture so we run before any other handler
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

// â”€â”€ Init â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
['fixed','movable','parked'].forEach(t => renderList(t, ''));
prefillFormIfEditMode();

// â”€â”€ FormStateManager â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Auto-saves form state to localStorage every 30s + on every change.
// Restores on page load if same segment_id and not expired (24h).
// Cleared on successful submit or reset.
const FormStateManager = (() => {
  const TTL    = 24 * 60 * 60 * 1000; // 24 hours in ms
  const PREFIX = 'parisar_form_';

  function _key() {
    return PREFIX + (segmentId || 'noseg');
  }

  function save() {
    const form = document.getElementById('auditForm');
    if (!form) return;

    const data = {};

    // Text / number / textarea inputs
    form.querySelectorAll('input[type="text"], input[type="number"], textarea').forEach(el => {
      if (el.name) data[el.name] = el.value;
    });

    // Radio buttons â€” save the checked one
    const radios = {};
    form.querySelectorAll('input[type="radio"]').forEach(el => {
      if (el.name && el.checked) radios[el.name] = el.value;
    });
    data.__radios = radios;

    // Checkboxes â€” save array of checked values per name
    const checks = {};
    form.querySelectorAll('input[type="checkbox"]').forEach(el => {
      if (!el.name) return;
      const base = el.name.replace('[]', '');
      if (!checks[base]) checks[base] = [];
      if (el.checked) checks[base].push(el.value);
    });
    data.__checks = checks;

    // Intersections array (already tracked in JS)
    try {
      const intData = [];
      document.querySelectorAll('.intersection-card').forEach(card => {
        const uid = card.dataset.uid;
        if (!uid) return;
        const p = 'int_' + uid + '_';
        const get  = id => { const el = document.getElementById(id); return el ? el.value : ''; };
        const getR = n  => { const el = card.querySelector(`input[name="${n}"]:checked`); return el ? el.value : ''; };
        intData.push({
          gps_coords:       get(p + 'gps'),
          landmark_name:    get(p + 'name'),
          off_ramp:         getR(p + 'offRamp'),
          on_ramp:          getR(p + 'onRamp'),
          markings:         getR(p + 'Markings'),
          signage:          getR(p + 'Signage'),
          traffic_calming:  getR(p + 'TrafficCalming'),
          discontinuity:    getR(p + 'Discontinuity'),
          tapering:         getR(p + 'Tapering'),
          obstruction_type: getR(p + 'ObstructionType'),
        });
      });
      data.__intersections = intData;
    } catch(e) { /* non-fatal */ }

    const payload = { ts: Date.now(), seg: segmentId, data };
    try {
      localStorage.setItem(_key(), JSON.stringify(payload));
    } catch(e) { /* storage full or disabled */ }
  }

  function restore() {
    let raw;
    try { raw = localStorage.getItem(_key()); } catch(e) { return; }
    if (!raw) return;

    let payload;
    try { payload = JSON.parse(raw); } catch(e) { clear(); return; }

    // Expire after 24h
    if (!payload.ts || (Date.now() - payload.ts) > TTL) { clear(); return; }
    // Must match current segment
    if (payload.seg !== segmentId) { clear(); return; }

    const d = payload.data || {};

    // Restore text/number/textarea
    Object.entries(d).forEach(([name, val]) => {
      if (name.startsWith('__')) return;
      const el = document.querySelector(`[name="${name}"]`);
      if (el && (el.type === 'text' || el.type === 'number' || el.tagName === 'TEXTAREA')) {
        el.value = val;
      }
    });

    // Restore radios
    Object.entries(d.__radios || {}).forEach(([name, val]) => {
      const el = document.querySelector(`input[type="radio"][name="${name}"][value="${CSS.escape(val)}"]`);
      if (el) { el.checked = true; el.dispatchEvent(new Event('change', { bubbles: true })); }
    });

    // Restore checkboxes
    Object.entries(d.__checks || {}).forEach(([base, vals]) => {
      document.querySelectorAll(`input[type="checkbox"][name="${base}[]"], input[type="checkbox"][name="${base}"]`).forEach(el => {
        el.checked = vals.includes(el.value);
        if (el.checked) el.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });

    // Show a subtle banner
    _showBanner();
  }

  function clear() {
    try { localStorage.removeItem(_key()); } catch(e) {}
  }

  function _showBanner() {
    const existing = document.getElementById('fsm-banner');
    if (existing) return;
    const div = document.createElement('div');
    div.id = 'fsm-banner';
    div.style.cssText = 'position:fixed;bottom:16px;left:50%;transform:translateX(-50%);' +
      'background:#1a1a2e;color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;' +
      'z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.3);display:flex;gap:12px;align-items:center;';
    div.innerHTML = 'ðŸ”„ Draft restored from your last session. ' +
      '<button onclick="FormStateManager.clear();document.getElementById(\'fsm-banner\').remove();location.reload();" ' +
      'style="background:transparent;border:1px solid #fff;color:#fff;padding:2px 10px;border-radius:4px;cursor:pointer;font-size:12px;">Discard</button>';
    document.body.appendChild(div);
    setTimeout(() => { if (div.parentNode) div.remove(); }, 8000);
  }

  // Auto-save on any input/change inside the form
  document.addEventListener('input',  () => save(), true);
  document.addEventListener('change', () => save(), true);

  // Auto-save every 30 seconds
  setInterval(save, 30_000);

  return { save, restore, clear };
})();

// Hook: clear on successful submit
const _origSubmit = submitFullAudit;
submitFullAudit = async function() {
  await _origSubmit.apply(this, arguments);
  // Only clear if submit succeeded (check for success toast or no error alert)
  // We patch via the response â€” override happens inside submitFullAudit already,
  // so we attach a one-time listener on the form's custom success event instead.
};

// Cleaner approach: patch doReset to also clear FSM
const _origDoReset = doReset;
doReset = async function() {
  FormStateManager.clear();
  await _origDoReset.apply(this, arguments);
};

// Restore on load (after prefill runs so edit-mode data wins)
setTimeout(() => {
  if (!editMode) FormStateManager.restore();
}, 100);
