/* js/landing_audits.js
   Landing page "Audit data": published audits as cards, year -> month -> audits,
   with city / condition / search filters. Reads /api/public/audits.php. */
(function () {
  'use strict';

  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
                'August', 'September', 'October', 'November', 'December'];
  var $ = function (id) { return document.getElementById(id); };
  var grid = $('ax-grid'), crumbs = $('ax-crumbs'), msg = $('ax-msg'), bar = $('ax-filters');
  if (!grid) return;

  var state = { all: [], city: '', cond: '', q: '', year: null, month: null };

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
  function fmtDate(iso) {
    var p = String(iso).split('-');
    return Number(p[2]) + ' ' + MONTHS[Number(p[1]) - 1].slice(0, 3) + ' ' + p[0];
  }
  function km(n) { return (Math.round(n * 10) / 10) + ' km'; }

  function filtered() {
    var q = state.q.trim().toLowerCase();
    return state.all.filter(function (a) {
      if (state.city && String(a.city_id) !== state.city) return false;
      if (state.cond && a.condition !== state.cond) return false;
      if (q && (a.name + ' ' + a.city + ' ' + a.state).toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
  }

  function totals(list) {
    var roads = 0, len = 0, cities = {};
    list.forEach(function (a) { roads += a.roads; len += a.length_km; cities[a.city_id] = 1; });
    return plural(roads, 'road', 'roads') + ' · ' + km(len) + ' · ' + plural(Object.keys(cities).length, 'city', 'cities');
  }

  function card(title, sub, count, meta, onClick) {
    var b = el('button', 'ax-card');
    b.type = 'button';
    b.appendChild(el('span', 'ax-card-title', title));
    if (sub) b.appendChild(el('span', 'ax-card-sub', sub));
    b.appendChild(el('span', 'ax-card-count', plural(count, 'audit', 'audits')));
    b.appendChild(el('span', 'ax-card-meta', meta));
    b.appendChild(el('span', 'ax-card-go', 'Open →'));
    b.addEventListener('click', onClick);
    return b;
  }

  function auditCard(a) {
    var c = el('article', 'ax-audit');
    var top = el('div', 'ax-audit-top');
    top.appendChild(el('h4', null, a.name));
    if (a.condition) {
      top.appendChild(el('span', 'ax-cond cond-' + a.condition.toLowerCase().replace(/ /g, '-'), a.condition));
    }
    c.appendChild(top);
    c.appendChild(el('p', 'ax-audit-where', a.city + (a.state ? ', ' + a.state : '')));
    c.appendChild(el('p', 'ax-audit-date', fmtDate(a.date)));
    var dl = el('dl', 'ax-facts');
    [['Roads', a.roads], ['Segments', a.segments], ['Length', km(a.length_km)],
     ['Score', a.score === null ? '–' : String(a.score)]].forEach(function (f) {
      var d = el('div');
      d.appendChild(el('dt', null, f[0]));
      d.appendChild(el('dd', null, String(f[1])));
      dl.appendChild(d);
    });
    c.appendChild(dl);
    return c;
  }

  function renderCrumbs() {
    crumbs.textContent = '';
    var steps = [{ label: 'All years', go: function () { state.year = null; state.month = null; } }];
    if (state.year !== null) {
      steps.push({ label: String(state.year), go: function () { state.month = null; } });
    }
    if (state.month !== null) steps.push({ label: MONTHS[state.month - 1] });
    steps.forEach(function (s, i) {
      if (i) crumbs.appendChild(el('span', 'ax-sep', '›'));
      if (i === steps.length - 1) { crumbs.appendChild(el('span', 'ax-here', s.label)); return; }
      var b = el('button', 'ax-crumb', s.label);
      b.type = 'button';
      b.addEventListener('click', function () { s.go(); render(); });
      crumbs.appendChild(b);
    });
  }

  function render() {
    var list = filtered();
    if (state.year !== null && !list.some(function (a) { return a.year === state.year; })) {
      state.year = null; state.month = null;
    }
    if (state.month !== null && !list.some(function (a) { return a.year === state.year && a.month === state.month; })) {
      state.month = null;
    }
    renderCrumbs();
    grid.textContent = '';
    grid.className = 'ax-grid' + (state.month !== null ? ' ax-grid-audits' : '');
    msg.textContent = 'Showing ' + plural(list.length, 'audit', 'audits') + ' of ' + state.all.length + '.';

    if (!list.length) {
      grid.appendChild(el('p', 'ax-empty', 'No published audits match these filters.'));
      return;
    }
    if (state.year === null) {
      var years = {};
      list.forEach(function (a) { (years[a.year] = years[a.year] || []).push(a); });
      Object.keys(years).sort(function (a, b) { return b - a; }).forEach(function (y) {
        grid.appendChild(card(y, '', years[y].length, totals(years[y]), function () {
          state.year = Number(y); render();
        }));
      });
    } else if (state.month === null) {
      var months = {};
      list.filter(function (a) { return a.year === state.year; })
          .forEach(function (a) { (months[a.month] = months[a.month] || []).push(a); });
      Object.keys(months).sort(function (a, b) { return b - a; }).forEach(function (m) {
        grid.appendChild(card(MONTHS[m - 1], String(state.year), months[m].length, totals(months[m]), function () {
          state.month = Number(m); render();
        }));
      });
    } else {
      list.filter(function (a) { return a.year === state.year && a.month === state.month; })
          .forEach(function (a) { grid.appendChild(auditCard(a)); });
    }
  }

  function setupFilters(cities) {
    var citySel = $('ax-city'), condSel = $('ax-cond'), q = $('ax-q');
    cities.forEach(function (c) {
      var o = el('option', null, c.name);
      o.value = String(c.id);
      citySel.appendChild(o);
    });
    citySel.addEventListener('change', function () { state.city = citySel.value; render(); });
    condSel.addEventListener('change', function () { state.cond = condSel.value; render(); });
    q.addEventListener('input', function () { state.q = q.value; render(); });
    $('ax-clear').addEventListener('click', function () {
      citySel.value = ''; condSel.value = ''; q.value = '';
      state.city = ''; state.cond = ''; state.q = ''; state.year = null; state.month = null;
      render();
    });
    bar.hidden = false;
  }

  fetch('/api/public/audits.php', { headers: { Accept: 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || !d.success) throw new Error('bad response');
      state.all = d.audits || [];
      grid.textContent = '';
      if (!state.all.length) {
        grid.appendChild(el('p', 'ax-empty', 'Published audits will appear here once the first city audit is approved.'));
        crumbs.hidden = true;
        return;
      }
      setupFilters(d.cities || []);
      render();
    })
    .catch(function () {
      grid.textContent = '';
      grid.appendChild(el('p', 'ax-empty', 'Audit data is not available right now. Please try again later.'));
      crumbs.hidden = true;
    });
})();
