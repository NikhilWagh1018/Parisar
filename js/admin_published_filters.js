// Admin "Published audits" page: City / Condition / Sort / Search filters over the table.
// The server writes each row's city, condition, score, length, date and name into data-* attributes;
// this only shows, hides and reorders those rows. Audits hidden from the home page always stay
// below the visible ones, whatever the sort.
(function () {
  'use strict';
  var body = document.getElementById('pubRows');
  var bar = document.getElementById('pubFilters');
  if (!body || !bar) return;
  var $ = function (id) { return document.getElementById(id); };
  var city = $('pubCity'), cond = $('pubCond'), sort = $('pubSort'), q = $('pubQ');
  var msg = $('pubMsg'), none = $('pubNoMatch');
  var rows = Array.prototype.slice.call(body.querySelectorAll('tr[data-name]'));

  function num(v) { return v === '' || v === null ? null : Number(v); }
  function byName(a, b) { return a.dataset.name.localeCompare(b.dataset.name); }
  function byDateDesc(a, b) { return b.dataset.date.localeCompare(a.dataset.date) || byName(a, b); }
  function byScore(dir) {
    return function (a, b) {
      var x = num(a.dataset.score), y = num(b.dataset.score);
      if (x === null && y === null) return byDateDesc(a, b);
      if (x === null) return 1;
      if (y === null) return -1;
      return (x - y) * dir || byDateDesc(a, b);
    };
  }
  // Lower scores are better, so "best" puts the lowest score first. Unscored audits go last.
  var ORDER = {
    newest: byDateDesc,
    oldest: function (a, b) { return a.dataset.date.localeCompare(b.dataset.date) || byName(a, b); },
    best: byScore(1),
    worst: byScore(-1),
    longest: function (a, b) { return (num(b.dataset.len) - num(a.dataset.len)) || byDateDesc(a, b); },
    name: byName
  };

  function apply() {
    var text = q.value.trim().toLowerCase();
    var shown = 0;
    var cmp = ORDER[sort.value] || byDateDesc;
    rows.sort(function (a, b) {
      return (Number(a.dataset.hidden) - Number(b.dataset.hidden)) || cmp(a, b);
    });
    rows.forEach(function (r) {
      var ok = (!city.value || r.dataset.city === city.value) &&
               (!cond.value || r.dataset.cond === cond.value) &&
               (!text || r.dataset.text.indexOf(text) !== -1);
      r.hidden = !ok;
      if (ok) shown++;
      body.insertBefore(r, none);
    });
    none.hidden = shown !== 0;
    msg.textContent = 'Showing ' + shown + (shown === 1 ? ' audit' : ' audits') + ' of ' + rows.length + '.';
  }

  [city, cond, sort].forEach(function (el) { el.addEventListener('change', apply); });
  q.addEventListener('input', apply);
  $('pubClear').addEventListener('click', function () {
    city.value = ''; cond.value = ''; sort.value = 'newest'; q.value = '';
    apply();
  });
  apply();
})();
