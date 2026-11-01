/* js/password_requests.js — the "Password requests" panel on the City Leader / Admin dashboards.
   Approve sends the request id and the 6-digit code; Dismiss closes the request.
   Every decision is made on the server (api/admin/password_requests.php); this file only sends
   the click and shows the answer. */
(function () {
  'use strict';
  var panel = document.getElementById('prPanel');
  if (!panel) { return; }
  var csrf = panel.getAttribute('data-csrf') || '';
  var countEl = document.getElementById('prCount');

  function send(payload) {
    return fetch('../api/admin/password_requests.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); });
  }

  function setBusy(item, busy) {
    item.querySelectorAll('button, input').forEach(function (el) { el.disabled = busy; });
  }

  function say(item, text, kind) {
    var m = item.querySelector('.pr-msg');
    m.textContent = text;
    m.className = 'pr-msg ' + (kind || '');
  }

  function finished(item, text) {
    item.classList.add('done');
    item.querySelector('.pr-act').hidden = true;
    say(item, text, 'ok');
    if (countEl) {
      var open = panel.querySelectorAll('.pr-item:not(.done)').length;
      countEl.textContent = String(open);
      if (open === 0) { countEl.hidden = true; }
    }
  }

  function failed(item, res) {
    setBusy(item, false);
    say(item, (res && res.error) || 'Something went wrong. Please try again.', 'err');
    // The request is gone for good (closed, replaced, expired or not yours): take it off the list.
    if (res && (res.tries_left === 0)) { finished(item, res.error); item.querySelector('.pr-msg').className = 'pr-msg err'; }
  }

  panel.addEventListener('click', function (e) {
    var btn = e.target.closest('button');
    if (!btn) { return; }
    var item = btn.closest('.pr-item');
    if (!item) { return; }
    var id = parseInt(item.getAttribute('data-id'), 10);
    var name = (item.querySelector('.pr-who b') || {}).textContent || 'this person';

    if (btn.classList.contains('pr-approve')) {
      var code = item.querySelector('.pr-code').value.replace(/\D+/g, '');
      if (code.length !== 6) { say(item, 'Enter the 6-digit code the person read out.', 'err'); return; }
      setBusy(item, true); say(item, '', '');
      send({ action: 'approve', id: id, code: code })
        .then(function (res) {
          if (res && res.success) { finished(item, 'Approved. ' + name + ' can now sign in with the password they chose.'); }
          else { failed(item, res); }
        })
        .catch(function () { failed(item, null); });
    } else if (btn.classList.contains('pr-dismiss')) {
      if (!window.confirm('Dismiss the request from ' + name + '? Nothing will change for them.')) { return; }
      setBusy(item, true); say(item, '', '');
      send({ action: 'dismiss', id: id })
        .then(function (res) {
          if (res && res.success) { finished(item, 'Dismissed.'); }
          else { failed(item, res); }
        })
        .catch(function () { failed(item, null); });
    }
  });
})();
