// Admin dashboard: "+ New Project" form. Plain fetch + the page's CSRF token (data-csrf on #npApp).
(function () {
  'use strict';
  var app = document.getElementById('npApp');
  var openBtn = document.getElementById('npNewBtn');
  var card = document.getElementById('npNewCard');
  var form = document.getElementById('npNewForm');
  if (!app || !openBtn || !card || !form) return;

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
  var citySel = form.city_id, stateInput = form.state, noLeader = document.getElementById('npNoLeader');
  function syncCity() {
    var opt = citySel.options[citySel.selectedIndex];
    var known = opt ? (opt.getAttribute('data-state') || '') : '';
    if (known) { stateInput.value = known; stateInput.readOnly = true; }
    else { stateInput.readOnly = false; if (!citySel.value) stateInput.value = ''; }
    if (noLeader) noLeader.style.display = opt && opt.getAttribute('data-leaders') === '0' ? 'block' : 'none';
  }
  citySel.addEventListener('change', syncCity);
  syncCity();

  openBtn.addEventListener('click', function () {
    card.style.display = card.style.display === 'none' ? 'block' : 'none';
    if (card.style.display === 'block') form.name.focus();
  });
  document.getElementById('npNewCancel').addEventListener('click', function () { card.style.display = 'none'; });

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    var btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    showFieldErrors({});
    try {
      var res = await fetch('../api/admin/audit_create.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': app.dataset.csrf || '', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({
          city_id: citySel.value, name: form.name.value, state: stateInput.value,
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
})();
