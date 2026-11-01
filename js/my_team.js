/* js/my_team.js — the "My team" screen (pages/city_team.php).
   Two lists: my team, and the other surveyors of the city. Search and filters only change what is
   shown. Add / Remove sends the surveyor's id to api/admin/team.php; every decision is made on the
   server, and a row moves to the other list only after the server says yes. */
(function () {
  'use strict';
  var app = document.getElementById('tmApp');
  if (!app) { return; }
  var csrf = app.getAttribute('data-csrf') || '';
  var rows = [];
  try { rows = JSON.parse(document.getElementById('tmData').textContent) || []; } catch (e) { rows = []; }

  var listMine = document.getElementById('tmListMine');
  var listAll  = document.getElementById('tmListAll');
  var msgEl    = document.getElementById('tmMsg');
  var search   = document.getElementById('tmSearch');
  var filter   = 'all';
  var busy     = {};

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }

  function say(text, isErr) {
    msgEl.textContent = text || '';
    msgEl.className = 'tm-msg' + (isErr ? ' err' : '');
  }

  function matches(r, q) {
    return !q || (r.name + ' ' + r.email).toLowerCase().indexOf(q) !== -1;
  }

  function row(r) {
    var li = el('li', 'tm-item');
    var who = el('div', 'tm-who');
    who.appendChild(el('b', '', r.name));
    who.appendChild(el('small', '', r.email));
    li.appendChild(who);
    if (r.state === 'other') {
      li.appendChild(el('span', 'tm-tag tm-other', 'In ' + r.other_leader + "'s team"));
      li.appendChild(el('span', 'tm-note', 'Only that leader can change this.'));
      return li;
    }
    if (r.state === 'free') { li.appendChild(el('span', 'tm-tag', 'No team yet')); }
    var b = el('button', 'tm-btn' + (r.state === 'mine' ? ' tm-remove' : ''), r.state === 'mine' ? 'Remove' : 'Add to my team');
    b.type = 'button';
    b.disabled = !!busy[r.id];
    b.addEventListener('click', function () { change(r); });
    li.appendChild(b);
    return li;
  }

  function fill(list, items, emptyText) {
    list.textContent = '';
    if (!items.length) { var li = el('li', 'tm-empty', emptyText); list.appendChild(li); return; }
    items.forEach(function (r) { list.appendChild(row(r)); });
  }

  function render() {
    var q = search.value.trim().toLowerCase();
    var mine = rows.filter(function (r) { return r.state === 'mine'; });
    var rest = rows.filter(function (r) { return r.state !== 'mine'; });
    var free = rest.filter(function (r) { return r.state === 'free'; }).length;

    var shownMine = mine.filter(function (r) { return matches(r, q); });
    var shownRest = rest.filter(function (r) {
      return matches(r, q) && (filter === 'all' || r.state === filter);
    });

    fill(listMine, shownMine, mine.length ? 'No one in your team matches your search.' : 'Nobody is in your team yet. Add surveyors from the list.');
    fill(listAll, shownRest, rest.length ? 'No surveyors match this search or filter.' : 'Every surveyor of your city is in your team.');

    document.getElementById('tmStatMine').textContent  = String(mine.length);
    document.getElementById('tmStatFree').textContent  = String(free);
    document.getElementById('tmStatOther').textContent = String(rest.length - free);
    document.getElementById('tmCountMine').textContent = String(mine.length);
    document.getElementById('tmCountAll').textContent  = String(rest.length);
  }

  function change(r) {
    var adding = r.state !== 'mine';
    busy[r.id] = true; say('', false); render();
    fetch('../api/admin/team.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ action: adding ? 'add' : 'remove', user_id: r.id })
    }).then(function (res) { return res.json(); })
      .then(function (res) {
        delete busy[r.id];
        if (res && res.success) {
          r.state = adding ? 'mine' : 'free';
          r.other_leader = null;
          say(r.name + (adding ? ' is now in your team.' : ' was removed from your team.'), false);
        } else {
          say((res && res.error) || 'Something went wrong. Please try again.', true);
        }
        render();
      })
      .catch(function () {
        delete busy[r.id];
        say('Something went wrong. Please try again.', true);
        render();
      });
  }

  search.addEventListener('input', render);
  app.querySelectorAll('.tm-chip').forEach(function (c) {
    c.addEventListener('click', function () {
      filter = c.getAttribute('data-filter');
      app.querySelectorAll('.tm-chip').forEach(function (o) { o.classList.toggle('on', o === c); });
      render();
    });
  });
  render();
})();
