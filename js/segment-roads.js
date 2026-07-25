/* js/segment-roads.js — road dropdown + page logic, extracted from pages/segment.php */

// ═══════════════════════════════════════════════════════
//  Searchable Road Dropdown
// ═══════════════════════════════════════════════════════

let ROAD_LIST = [];
let _rdListLoaded = false;

function loadRoadList() {
  fetch('../api/roads/groups.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.success) {
        ROAD_LIST = data.roads;
      }
      _rdListLoaded = true;
      if (_rdOpen) roadRenderDropdown(document.getElementById('roadSearchInput').value);
    })
    .catch(function () {
      _rdListLoaded = true;
      if (_rdOpen) roadRenderDropdown(document.getElementById('roadSearchInput').value);
    });
}
loadRoadList();

let _rdHighlight = -1;
let _rdOpen = false;

function roadDropdownOpen() {
  roadRenderDropdown(document.getElementById('roadSearchInput').value);
  document.getElementById('roadDropdown').classList.add('open');
  _rdOpen = true;
}

function roadDropdownClose() {
  document.getElementById('roadDropdown').classList.remove('open');
  _rdOpen = false;
  _rdHighlight = -1;
}

function roadSearchFilter(q) {
  const inp = document.getElementById('roadSearchInput');
  inp.classList.toggle('has-value', q.length > 0);
  roadRenderDropdown(q);
  if (!_rdOpen) {
    document.getElementById('roadDropdown').classList.add('open');
    _rdOpen = true;
  }
}

function roadRenderDropdown(q) {
  const dd  = document.getElementById('roadDropdown');
  const raw = q.trim().toUpperCase();
  _rdHighlight = -1;

  const filtered = raw.length === 0
    ? ROAD_LIST
    : ROAD_LIST.filter(r => r.includes(raw));

  let html = '';

  // ── Pinned: Other / Custom — admin only, always at top ──
  if (window.IS_ADMIN) {
    html += `<div class="road-dropdown-item pinned" onclick="roadSelectCustom()">
      <span>✦</span> Other / Custom Road…
    </div>`;
  }

  if (filtered.length > 0) {
    html += `<div class="road-dropdown-section-label">Pune Cycle Track Roads</div>`;
    filtered.forEach((road, i) => {
      const label = raw.length > 0
        ? road.replace(raw, `<span class="match-bold">${raw}</span>`)
        : road;
      html += `<div class="road-dropdown-item" data-idx="${i}" data-val="${road}"
                onclick="roadSelectItem('${road}')">
        <span>🛣</span> <span>${label}</span>
      </div>`;
    });
  } else if (!_rdListLoaded) {
    html += `<div class="road-dropdown-empty">Loading roads…</div>`;
  } else if (raw.length > 0) {
    // No match
    if (window.IS_ADMIN) {
      html += `<div class="road-dropdown-empty">
        No match for "<strong>${raw}</strong>"
      </div>
      <div class="road-dropdown-item pinned" onclick="roadSelectCustomFill('${raw}')">
        <span>＋</span> Add "<strong>${raw}</strong>" as custom road
      </div>`;
    } else {
      html += `<div class="road-dropdown-empty">
        No match for "<strong>${raw}</strong>". Can't find your road? Ask an admin to add it.
      </div>`;
    }
  }

  dd.innerHTML = html;
}

function roadSelectItem(val) {
  // Set hidden select
  const sel = document.getElementById('roadName');
  sel.value = val;
  // Update visible input
  document.getElementById('roadSearchInput').value = val;
  document.getElementById('roadSearchInput').classList.add('has-value');
  // Hide custom wrap
  document.getElementById('customRoadWrap').classList.remove('open');
  document.getElementById('customRoadName').value = '';
  // Hide error
  const err = document.getElementById('err-roadName');
  if (err) err.style.display = 'none';
  roadDropdownClose();
}

function roadSelectCustom() {
  const sel = document.getElementById('roadName');
  sel.value = '__custom__';
  document.getElementById('roadSearchInput').value = '✦ Other / Custom Road';
  document.getElementById('roadSearchInput').classList.add('has-value');
  const wrap = document.getElementById('customRoadWrap');
  wrap.classList.add('open');
  setTimeout(() => document.getElementById('customRoadName').focus(), 350);
  const err = document.getElementById('err-roadName');
  if (err) err.style.display = 'none';
  roadDropdownClose();
}

function roadSelectCustomFill(val) {
  // Pre-fill custom input with what user typed
  const sel = document.getElementById('roadName');
  sel.value = '__custom__';
  document.getElementById('roadSearchInput').value = '✦ Other / Custom Road';
  document.getElementById('roadSearchInput').classList.add('has-value');
  const wrap = document.getElementById('customRoadWrap');
  wrap.classList.add('open');
  const custInput = document.getElementById('customRoadName');
  custInput.value = val.toUpperCase();
  validateCustomRoad(custInput);
  setTimeout(() => custInput.focus(), 350);
  roadDropdownClose();
}

// Keyboard navigation
function roadSearchKeydown(e) {
  const dd    = document.getElementById('roadDropdown');
  const items = dd.querySelectorAll('.road-dropdown-item');
  if (!_rdOpen || items.length === 0) return;
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    _rdHighlight = Math.min(_rdHighlight + 1, items.length - 1);
    items.forEach((el, i) => el.classList.toggle('highlighted', i === _rdHighlight));
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    _rdHighlight = Math.max(_rdHighlight - 1, 0);
    items.forEach((el, i) => el.classList.toggle('highlighted', i === _rdHighlight));
  } else if (e.key === 'Enter') {
    e.preventDefault();
    if (_rdHighlight >= 0) items[_rdHighlight].click();
  } else if (e.key === 'Escape') {
    roadDropdownClose();
  }
}

// Close on outside click
document.addEventListener('click', function(e) {
  const wrap = document.getElementById('roadSearchWrap');
  if (wrap && !wrap.contains(e.target)) roadDropdownClose();
});

// ── Custom road text input validation ──
function validateCustomRoad(input) {
  input.value = input.value.toUpperCase();
  const hint = document.getElementById('customRoadHint');
  const v    = input.value.trim();
  if (!hint) return;
  if (v.length === 0)    { hint.textContent = 'Min 3 characters. Use official road name if possible.'; hint.className = 'road-hint'; }
  else if (v.length < 3) { hint.textContent = '⚠ Too short (min 3 characters).'; hint.className = 'road-hint err'; }
  else if (!/^[A-Z0-9\s\.\-\/]+$/.test(v)) { hint.textContent = '⚠ Invalid characters.'; hint.className = 'road-hint err'; }
  else                   { hint.textContent = '✓ Looks good!'; hint.className = 'road-hint'; }
}

// ── Patch #roadName.value so segment.js reads custom text transparently ──
(function patchRoadSelect() {
  const sel  = document.getElementById('roadName');
  const cust = document.getElementById('customRoadName');
  if (!sel || !cust) return;
  const proto = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
  Object.defineProperty(sel, 'value', {
    get() {
      const raw = proto.get.call(this);
      if (raw === '__custom__') {
        const v = cust.value.trim().toUpperCase();
        return v.length > 0 ? v : '__custom__';
      }
      return raw;
    },
    set(v) { proto.set.call(this, v); },
    configurable: true,
  });
})();

// ── Helpers ──
function goToDashboard()  { window.location.href = 'dashboard.php'; }
function viewRoadResult() { window.location.href = 'road_result.php'; }

function downloadRoadScore() {
  const sessionId = window._currentSessionId;
  if (!sessionId) { alert('No active session found. Please save segments first.'); return; }
  window.open('report.php?session_id=' + sessionId, '_blank');
}