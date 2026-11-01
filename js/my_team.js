/* js/my_team.js — the "My team" panel on the City Leader dashboard.
   Add / Remove sends the surveyor's id to api/admin/team.php; every decision is made on the server.
   This file only sends the click and shows the answer. */
(function () {
  'use strict';
  var panel = document.getElementById('tmPanel');
  if (!panel) { return; }
  var csrf = panel.getAttribute('data-csrf') || '';
  var countEl = document.getElementById('tmCount');

  function send(payload) {
    return fetch('../api/admin/team.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); });
  }

  function say(item, text, isErr) {
    var m = item.querySelector('.tm-msg');
    m.textContent = text;
    m.className = 'tm-msg' + (isErr ? ' err' : '');
  }

  function recount() {
    if (countEl) { countEl.textContent = String(panel.querySelectorAll('.tm-item[data-state="mine"]').length); }
  }

  function show(item, state) {
    var tag = item.querySelector('.tm-tag');
    var btn = item.querySelector('.tm-btn');
    item.setAttribute('data-state', state);
    tag.className = 'tm-tag tm-' + state;
    tag.textContent = state === 'mine' ? 'In your team' : 'No team yet';
    btn.textContent = state === 'mine' ? 'Remove' : 'Add to my team';
    recount();
  }

  panel.addEventListener('click', function (e) {
    var btn = e.target.closest('.tm-btn');
    if (!btn) { return; }
    var item = btn.closest('.tm-item');
    if (!item) { return; }
    var id = parseInt(item.getAttribute('data-id'), 10);
    var adding = item.getAttribute('data-state') !== 'mine';
    btn.disabled = true; say(item, '', false);
    send({ action: adding ? 'add' : 'remove', user_id: id })
      .then(function (res) {
        btn.disabled = false;
        if (res && res.success) { show(item, adding ? 'mine' : 'free'); }
        else { say(item, (res && res.error) || 'Something went wrong. Please try again.', true); }
      })
      .catch(function () {
        btn.disabled = false;
        say(item, 'Something went wrong. Please try again.', true);
      });
  });
})();
