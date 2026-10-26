// Dashboard: "Mark all as read" for the notifications card.
(function () {
  'use strict';
  var btn = document.getElementById('notifReadAll');
  var card = document.getElementById('notifCard');
  if (!btn || !card || typeof CSRF === 'undefined') return;

  btn.addEventListener('click', async function () {
    btn.disabled = true;
    try {
      var res = await fetch('../api/user/notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action: 'read_all' })
      });
      var data = await res.json();
      if (!res.ok || !data.success) throw new Error('failed');
      card.remove();
    } catch (e) {
      btn.disabled = false;
    }
  });
})();
