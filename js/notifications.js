// Dashboard notifications: open/close the panel, mark one or all as read.
// Read items stay in the list (dimmed); they are never removed from the page.
(function () {
  'use strict';
  var panel = document.getElementById('notifCard');
  var toggle = document.getElementById('notifToggle');
  var body = document.getElementById('notifBody');
  if (!panel || !toggle || !body) return;
  var readAll = document.getElementById('notifReadAll');
  var countEl = document.getElementById('notifCount');
  var subEl = panel.querySelector('.nt-sub');

  function setOpen(open) {
    panel.setAttribute('data-open', open ? '1' : '0');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    body.hidden = !open;
    try { sessionStorage.setItem('ntOpen', open ? '1' : '0'); } catch (e) { /* storage may be blocked */ }
  }
  toggle.addEventListener('click', function () { setOpen(body.hidden); });
  try { if (sessionStorage.getItem('ntOpen') === '1') setOpen(true); } catch (e) { /* ignore */ }

  function post(payload) {
    return fetch('../api/user/notifications.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (typeof CSRF !== 'undefined' ? CSRF : ((document.getElementById('caApp') || {}).dataset || {}).csrf || ''), 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); });
  }
  function refreshCount() {
    var n = panel.querySelectorAll('.nt-item:not(.is-read)').length;
    if (countEl) { countEl.textContent = n + ' new'; countEl.hidden = n === 0; }
    if (subEl) subEl.textContent = n ? n + ' unread' : 'You are all caught up';
    if (readAll) readAll.disabled = n === 0;
  }

  if (readAll) {
    readAll.addEventListener('click', function () {
      readAll.disabled = true;
      post({ action: 'read_all' }).then(function (d) {
        if (!d.success) throw new Error('failed');
        panel.querySelectorAll('.nt-item').forEach(function (li) { li.classList.add('is-read'); });
        refreshCount();
      }).catch(function () { readAll.disabled = false; });
    });
  }

  // Clicking a notification marks it read and opens what it is about, when it has a link.
  panel.querySelectorAll('.nt-item').forEach(function (li) {
    li.addEventListener('click', function () {
      var link = li.getAttribute('data-link');
      var wasUnread = !li.classList.contains('is-read');
      if (wasUnread) {
        li.classList.add('is-read');
        refreshCount();
      }
      var p = wasUnread ? post({ action: 'read', id: parseInt(li.getAttribute('data-id'), 10) }).catch(function () {}) : Promise.resolve();
      if (link) p.then(function () { window.location.href = link; });
    });
  });
})();
