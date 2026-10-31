// Admin dashboard: "+ New Project" form. Plain fetch + the page's CSRF token (data-csrf on #npApp).
(function () {
  'use strict';
  var app = document.getElementById('npApp');
  var openBtn = document.getElementById('npNewBtn');
  var card = document.getElementById('npNewCard');
  var form = document.getElementById('npNewForm');
  if (!app || !openBtn || !card || !form) return;
  var candidates = [];
  try { candidates = JSON.parse(app.dataset.candidates || '[]'); } catch (e) { candidates = []; }
  function csrfHeaders() {
    return { 'Content-Type': 'application/json', 'X-CSRF-Token': app.dataset.csrf || '', 'X-Requested-With': 'XMLHttpRequest' };
  }
  function candidatesFor(cityId) {
    return candidates.filter(function (u) { return u.city_id === null || u.city_id === cityId; });
  }
  function personLabel(u) { return u.name + (u.email ? ' (' + u.email + ')' : '') + (u.home ? ' \u2014 ' + u.home : ''); }

  var toastEl = document.getElementById('caToast');
  function toast(msg, type) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.className = 'ca-toast show' + (type === 'error' ? ' error' : '');
    setTimeout(function () { toastEl.className = 'ca-toast'; }, 3200);
  }

  function showFieldErrors(fields) {
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

  // The state follows the chosen city when we know it; otherwise the Admin types it.
  var cityInput = form.city_name, stateInput = form.state, hint = document.getElementById('npCityHint');
  var byName = {};
  document.querySelectorAll('#npCityList option').forEach(function (o) {
    byName[o.value.trim().toLowerCase()] = {
      id: parseInt(o.getAttribute('data-id'), 10) || 0,
      state: o.getAttribute('data-state') || '',
      leaders: parseInt(o.getAttribute('data-leaders'), 10),
      leaderName: o.getAttribute('data-leader-name') || ''
    };
  });
  var selectedId = 0;
  function say(text, warn) {
    hint.style.display = text ? 'block' : 'none';
    hint.textContent = text || '';
    hint.style.color = warn ? '#b45309' : '';
  }
  function syncCity() {
    var typed = cityInput.value.trim();
    var c = byName[typed.toLowerCase()];
    selectedId = c ? c.id : 0;
    if (c && c.state) { stateInput.value = c.state; stateInput.readOnly = true; }
    else { if (stateInput.readOnly) stateInput.value = ''; stateInput.readOnly = false; }
    updateLeaderField(c);
    if (!typed) say('');
    else if (c && c.id && c.leaders === 0) say('This city has no City Leader yet. Add one under Users, or nobody can add its roads.', true);
    else if (c && c.id && c.leaders > 0) say((c.leaderName || 'This city\'s City Leader') + ' is already the City Leader of ' + typed + '. The project goes to their dashboard. A city has only one City Leader; to change who leads it, open the project and choose Edit.', false);
    else if (!c) say('Pick a city from the list. If it is not there, it has to be added to the city list first.', true);
    else if (!c.id) say('New city: it is added to CycleAudit with this project, and its City Leader and surveyors can then sign up.', false);
    else say('');
  }
  var leaderWrap = document.getElementById('npLeaderWrap'), leaderSel = form.leader_user_id;
  function updateLeaderField(c) {
    var list = c && c.id && c.leaders === 0 ? candidatesFor(c.id) : [];
    leaderSel.innerHTML = '<option value="">— Assign later —</option>';
    list.forEach(function (u) {
      var o = document.createElement('option');
      o.value = u.id; o.textContent = personLabel(u);
      leaderSel.appendChild(o);
    });
    leaderWrap.style.display = list.length ? 'block' : 'none';
  }
  if (window.CitySearch) {
    var cityItems = [];
    document.querySelectorAll('#npCityList option').forEach(function (o) {
      cityItems.push({ label: o.value, state: o.getAttribute('data-state') || '', boost: parseInt(o.getAttribute('data-id'), 10) > 0 ? 1 : 0 });
    });
    var cityAliases = {};
    try { cityAliases = JSON.parse(document.getElementById('npCityList').getAttribute('data-aliases') || '{}'); } catch (e) { cityAliases = {}; }
    window.CitySearch.attach(cityInput, cityItems, syncCity, { aliases: cityAliases });
  }
  // An old "Pick a city" error goes away as soon as the Admin types again.
  cityInput.addEventListener('input', function () {
    var w = cityInput.closest('.ca-field');
    if (w) w.classList.remove('bad');
  });
  cityInput.addEventListener('input', syncCity);
  cityInput.addEventListener('change', syncCity);
  cityInput.addEventListener('blur', function () {
    var al = {};
    try { al = JSON.parse(document.getElementById('npCityList').getAttribute('data-aliases') || '{}'); } catch (e) { al = {}; }
    var k = cityInput.value.replace(/\s+/g, ' ').trim().toLowerCase();
    if (!byName[k] && al[k]) { var hit = al[k].toLowerCase(); if (byName[hit]) { cityInput.value = al[k]; syncCity(); } }
  });
  syncCity();

  openBtn.addEventListener('click', function () {
    card.style.display = card.style.display === 'none' ? 'block' : 'none';
    if (card.style.display === 'block') form.name.focus();
  });
  document.getElementById('npNewCancel').addEventListener('click', function () { card.style.display = 'none'; });

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    if (!byName[cityInput.value.trim().toLowerCase()]) {
      showFieldErrors({ city_name: 'Pick a city from the list.' });
      toast('Pick a city from the list.', 'error');
      return;
    }
    var btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    showFieldErrors({});
    try {
      var res = await fetch('../api/admin/audit_create.php', {
        method: 'POST',
        headers: csrfHeaders(),
        body: JSON.stringify({
          leader_user_id: leaderSel.value || '',
          city_id: selectedId || '', city_name: cityInput.value, name: form.name.value, state: stateInput.value,
          audit_date: form.audit_date.value, programme_info: form.programme_info.value
        })
      });
      var data = null;
      try { data = await res.json(); } catch (e) { /* keep null */ }
      if (!res.ok || !data || !data.success) {
        var err = new Error((data && data.error) || 'Something went wrong.');
        err.fields = (data && data.errors) || {};
        throw err;
      }
      toast('Project created. The City Leader can now add roads.');
      setTimeout(function () { window.location.reload(); }, 900);
    } catch (e) {
      showFieldErrors(e.fields);
      toast(e.message, 'error');
      btn.disabled = false;
    }
  });

  // ── Cities table: "Assign City Leader" opens a picker under the row ──
  function closePickers() { document.querySelectorAll('tr.npPicker').forEach(function (r) { r.remove(); }); }
  function openPicker(btn) {
    var row = btn.closest('tr');
    var existing = row.nextElementSibling;
    var wasOpen = existing && existing.classList.contains('npPicker');
    closePickers();
    if (wasOpen) return;
    var cityId = parseInt(btn.getAttribute('data-city'), 10);
    var cityName = btn.getAttribute('data-name');
    var list = candidatesFor(cityId);
    var tr = document.createElement('tr');
    tr.className = 'npPicker';
    var td = document.createElement('td');
    td.colSpan = row.children.length;
    td.style.background = '#f7faf2';
    if (!list.length) {
      td.textContent = 'No surveyors in ' + cityName + ' yet. Ask the City Leader to sign up and choose ' + cityName +
        ' as their city, then come back here. Someone in another city can be moved on the Users page first.';
    } else {
      var sel = document.createElement('select');
      sel.style.cssText = 'padding:8px 10px;border:1px solid rgba(0,0,0,.14);border-radius:8px;font:inherit;margin-right:8px;min-width:260px';
      sel.innerHTML = '<option value="">— Choose a person —</option>';
      list.forEach(function (u) {
        var o = document.createElement('option');
        o.value = u.id; o.textContent = personLabel(u);
        sel.appendChild(o);
      });
      var go = document.createElement('button');
      go.type = 'button'; go.className = 'ca-btn'; go.textContent = 'Make City Leader';
      var lbl = document.createElement('span');
      lbl.textContent = 'City Leader for ' + cityName + ': ';
      lbl.style.marginRight = '8px';
      td.appendChild(lbl); td.appendChild(sel); td.appendChild(go);
      go.addEventListener('click', async function () {
        if (!sel.value) { toast('Choose a person first.', 'error'); return; }
        go.disabled = true;
        try {
          var res = await fetch('../api/admin/city_leader.php', {
            method: 'POST', headers: csrfHeaders(),
            body: JSON.stringify({ city_id: cityId, user_id: parseInt(sel.value, 10) })
          });
          var data = null;
          try { data = await res.json(); } catch (e) { /* keep null */ }
          if (!res.ok || !data || !data.success) throw new Error((data && data.error) || 'Something went wrong.');
          toast('City Leader assigned.');
          setTimeout(function () { window.location.href = 'platform_dashboard.php'; }, 900);
        } catch (e) {
          toast(e.message, 'error');
          go.disabled = false;
        }
      });
    }
    tr.appendChild(td);
    row.parentNode.insertBefore(tr, row.nextSibling);
  }
  document.querySelectorAll('.npAssign').forEach(function (b) {
    b.addEventListener('click', function () { openPicker(b); });
  });
  var auto = parseInt(app.dataset.assignCity || '0', 10);
  if (auto > 0) {
    var target = document.querySelector('.npAssign[data-city="' + auto + '"]');
    if (target) { target.scrollIntoView({ block: 'center' }); openPicker(target); }
  }
})();
