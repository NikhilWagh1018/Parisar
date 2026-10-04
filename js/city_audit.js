// City Leader audit screens. Plain fetch + the page's CSRF token (data-csrf on #caApp).
(function () {
  'use strict';
  var app = document.getElementById('caApp');
  if (!app) return;
  var CSRF = app.dataset.csrf || '';
  var toastEl = document.getElementById('caToast');

  function toast(msg, type) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.className = 'ca-toast show' + (type === 'error' ? ' error' : '');
    setTimeout(function () { toastEl.className = 'ca-toast'; }, 3200);
  }

  async function post(url, payload) {
    var res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload)
    });
    var data = null;
    try { data = await res.json(); } catch (e) { /* keep null */ }
    if (!res.ok || !data || !data.success) {
      var err = new Error((data && data.error) || 'Something went wrong.');
      err.fields = (data && data.errors) || {};
      throw err;
    }
    return data;
  }

  function showFieldErrors(form, fields) {
    form.querySelectorAll('.ca-field').forEach(function (f) { f.classList.remove('bad'); });
    Object.keys(fields || {}).forEach(function (name) {
      var input = form.querySelector('[name="' + name + '"]');
      var wrap = input && input.closest('.ca-field');
      if (!wrap) return;
      wrap.classList.add('bad');
      var e = wrap.querySelector('.ca-err');
      if (e) e.textContent = fields[name];
    });
  }

  // ── New audit form (city dashboard) ───────────────────────────
  var openBtn = document.getElementById('caNewBtn');
  var newCard = document.getElementById('caNewCard');
  if (openBtn && newCard) {
    openBtn.addEventListener('click', function () {
      newCard.style.display = newCard.style.display === 'none' ? 'block' : 'none';
      if (newCard.style.display === 'block') newCard.querySelector('input[name="name"]').focus();
    });
    document.getElementById('caNewCancel').addEventListener('click', function () { newCard.style.display = 'none'; });
    var form = document.getElementById('caNewForm');
    form.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      btn.disabled = true;
      showFieldErrors(form, {});
      try {
        var d = await post('../api/city/audit_create.php', {
          name: form.name.value, state: form.state.value,
          audit_year: form.audit_year.value, programme_info: form.programme_info.value
        });
        window.location.href = 'city_audit.php?id=' + encodeURIComponent(d.audit_id);
      } catch (e) {
        showFieldErrors(form, e.fields);
        toast(e.message, 'error');
        btn.disabled = false;
      }
    });
  }

  // ── Audit page: add road with live preview ────────────────────
  var roadForm = document.getElementById('caRoadForm');
  if (roadForm) {
    var segSel = roadForm.segment_choice, segCustom = roadForm.segment_custom;
    var customWrap = document.getElementById('caCustomWrap');
    var prev = document.getElementById('caPreview');

    function segLen() {
      return segSel.value === 'custom' ? parseFloat(segCustom.value) || 0 : parseFloat(segSel.value);
    }
    function updatePreview() {
      customWrap.style.display = segSel.value === 'custom' ? 'block' : 'none';
      var total = parseFloat(roadForm.total_length.value) || 0, len = segLen();
      if (!total || !len) { prev.classList.remove('show'); return; }
      var count = Math.ceil(Math.round(total / len * 1e9) / 1e9);
      var last = total - (count - 1) * len;
      document.getElementById('caPvCount').textContent = count;
      document.getElementById('caPvEach').textContent = len + ' m';
      document.getElementById('caPvLast').textContent = last.toFixed(1) + ' m';
      prev.classList.add('show');
    }
    ['input', 'change'].forEach(function (evn) {
      roadForm.addEventListener(evn, updatePreview);
    });
    updatePreview();

    roadForm.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      var btn = roadForm.querySelector('button[type="submit"]');
      btn.disabled = true;
      showFieldErrors(roadForm, {});
      try {
        await post('../api/city/audit_road_add.php', {
          audit_id: app.dataset.auditId,
          road_group_id: roadForm.road_group_id.value,
          total_length: roadForm.total_length.value,
          segment_length: segLen()
        });
        window.location.reload();
      } catch (e) {
        showFieldErrors(roadForm, e.fields);
        toast(e.message, 'error');
        btn.disabled = false;
      }
    });
  }

  // ── Audit page: keep opened roads open across a reload ────────
  var OPEN_KEY = 'caOpen:' + (app.dataset.auditId || '');
  function saveOpen() {
    try {
      var ids = [];
      document.querySelectorAll('.ca-road.open').forEach(function (c) { ids.push(c.dataset.roadId); });
      sessionStorage.setItem(OPEN_KEY, JSON.stringify(ids));
    } catch (e) { /* storage unavailable: roads just start closed */ }
  }
  function restoreOpen() {
    try {
      var ids = JSON.parse(sessionStorage.getItem(OPEN_KEY) || '[]');
      sessionStorage.removeItem(OPEN_KEY);
      ids.forEach(function (id) {
        var c = document.querySelector('.ca-road[data-road-id="' + id + '"]');
        if (!c) return;
        c.classList.add('open');
        var t = c.querySelector('.ca-toggle');
        if (t) t.textContent = 'Hide segments';
      });
    } catch (e) { /* ignore */ }
  }

  // ── Audit page: expand / remove road ──────────────────────────
  document.querySelectorAll('.ca-toggle').forEach(function (b) {
    b.addEventListener('click', function () {
      var card = b.closest('.ca-road');
      card.classList.toggle('open');
      b.textContent = card.classList.contains('open') ? 'Hide segments' : 'Show segments';
    });
  });
  document.querySelectorAll('.ca-remove').forEach(function (b) {
    b.addEventListener('click', async function () {
      if (!window.confirm('Remove ' + b.dataset.name + ' and its segments from this audit?')) return;
      b.disabled = true;
      try {
        await post('../api/city/audit_road_remove.php', { audit_id: app.dataset.auditId, road_id: b.dataset.roadId });
        window.location.reload();
      } catch (e) {
        toast(e.message, 'error');
        b.disabled = false;
      }
    });
  });
  // ── Audit page: assign surveyors ──────────────────────────────
  document.querySelectorAll('.ca-seg-assign').forEach(function (sel) {
    sel.addEventListener('change', async function () {
      sel.disabled = true;
      try {
        await post('../api/city/audit_assign.php', {
          audit_id: app.dataset.auditId,
          segment_ids: [sel.dataset.segmentId],
          surveyor_id: sel.value
        });
        saveOpen();
        window.location.reload();
      } catch (e) {
        toast(e.message, 'error');
        sel.value = sel.dataset.current || '';
        sel.disabled = false;
      }
    });
  });

  document.querySelectorAll('.ca-assign-road').forEach(function (b) {
    b.addEventListener('click', async function () {
      var sel = b.closest('.ca-road').querySelector('.ca-road-surveyor');
      if (!sel || sel.value === '') { toast('Choose a surveyor first.', 'error'); return; }
      var label = sel.value === '0' ? 'Unassign every pending segment of this road?'
                                    : 'Assign every pending segment of this road to ' + sel.options[sel.selectedIndex].text + '?';
      if (!window.confirm(label)) return;
      b.disabled = true;
      try {
        await post('../api/city/audit_assign.php', {
          audit_id: app.dataset.auditId,
          road_id: b.dataset.roadId,
          surveyor_id: sel.value
        });
        saveOpen();
        window.location.reload();
      } catch (e) {
        toast(e.message, 'error');
        b.disabled = false;
      }
    });
  });

  var activateBtn = document.getElementById('caActivate');
  if (activateBtn) {
    activateBtn.addEventListener('click', async function () {
      if (!window.confirm('Activate this audit? Roads can no longer be added or removed afterwards.')) return;
      activateBtn.disabled = true;
      try {
        await post('../api/city/audit_activate.php', { audit_id: app.dataset.auditId });
        window.location.reload();
      } catch (e) {
        toast(e.message, 'error');
        activateBtn.disabled = false;
      }
    });
  }

  restoreOpen();

  // ── Audit page: review submissions, close, send ───────────────
  document.querySelectorAll('.ca-approve').forEach(function (b) {
    b.addEventListener('click', async function () {
      b.disabled = true;
      try {
        await post('../api/city/audit_review.php', { audit_id: app.dataset.auditId, segment_id: b.dataset.segmentId, action: 'approve' });
        window.location.reload();
      } catch (e) { toast(e.message, 'error'); b.disabled = false; }
    });
  });
  document.querySelectorAll('.ca-sendback-open').forEach(function (b) {
    b.addEventListener('click', function () {
      var box = b.closest('.ca-sub-card').querySelector('div.ca-sendback');
      box.style.display = box.style.display === 'none' ? 'flex' : 'none';
      if (box.style.display === 'flex') box.querySelector('.ca-note').focus();
    });
  });
  document.querySelectorAll('button.ca-sendback').forEach(function (b) {
    b.addEventListener('click', async function () {
      var box = b.closest('div.ca-sendback');
      var note = box.querySelector('.ca-note').value.trim();
      if (!note) { toast('Tell the surveyor what needs to be fixed.', 'error'); return; }
      b.disabled = true;
      try {
        await post('../api/city/audit_review.php', {
          audit_id: app.dataset.auditId, segment_id: b.dataset.segmentId, action: 'send_back',
          note: note, surveyor_id: box.querySelector('.ca-new-surveyor').value
        });
        window.location.reload();
      } catch (e) { toast(e.message, 'error'); b.disabled = false; }
    });
  });
  var closeBtn = document.getElementById('caClose');
  if (closeBtn) closeBtn.addEventListener('click', async function () {
    if (!window.confirm('Close this audit? Surveyors can no longer submit, and the report is generated.')) return;
    closeBtn.disabled = true;
    try {
      await post('../api/city/audit_close.php', { audit_id: app.dataset.auditId, action: 'close' });
      window.location.reload();
    } catch (e) { toast(e.message, 'error'); closeBtn.disabled = false; }
  });
  var sendBtn = document.getElementById('caSend');
  if (sendBtn) sendBtn.addEventListener('click', async function () {
    if (!window.confirm('Send this audit and its report to the Admin?')) return;
    sendBtn.disabled = true;
    try {
      await post('../api/city/audit_close.php', { audit_id: app.dataset.auditId, action: 'send' });
      window.location.reload();
    } catch (e) { toast(e.message, 'error'); sendBtn.disabled = false; }
  });
})();
