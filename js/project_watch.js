// Open project screens: ask every few seconds whether the Admin deleted the project. If so, show
// a clear message and send the person to their dashboard, so work stops at once.
// Usage: <script src="project_watch.js" data-audit-id="N" | data-road-id="N" | data-segment-id="N" data-home="dashboard.php?notice=project_deleted">
(function () {
  'use strict';
  var me = document.currentScript;
  if (!me) return;
  var q = [];
  ['audit-id', 'road-id', 'segment-id'].forEach(function (k) {
    var v = parseInt(me.getAttribute('data-' + k), 10);
    if (v > 0) q.push(k.replace('-', '_') + '=' + v);
  });
  if (!q.length) return;
  var url = '../api/project_status.php?' + q.join('&');
  var home = me.getAttribute('data-home') || 'dashboard.php?notice=project_deleted';
  var stopped = false;

  function stop() {
    if (stopped) return;
    stopped = true;
    var bg = document.createElement('div');
    bg.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(20,24,16,.55);display:flex;align-items:center;justify-content:center;padding:16px;font-family:inherit';
    var box = document.createElement('div');
    box.style.cssText = 'background:#fff;border-radius:14px;max-width:420px;width:100%;padding:22px;box-shadow:0 20px 50px rgba(0,0,0,.3)';
    box.setAttribute('role', 'alertdialog');
    var h = document.createElement('h3'); h.style.cssText = 'margin:0 0 8px;font-size:1.05rem'; h.textContent = 'This project was deleted';
    var p = document.createElement('p'); p.style.cssText = 'margin:0 0 18px;font-size:.9rem;line-height:1.55;color:#4a4f43';
    p.textContent = 'The Admin deleted this project, so work on it has stopped. Anything you have not submitted cannot be saved.';
    var row = document.createElement('div'); row.style.cssText = 'display:flex;justify-content:flex-end';
    var ok = document.createElement('button'); ok.type = 'button'; ok.textContent = 'Go to dashboard';
    ok.style.cssText = 'background:#dc2626;color:#fff;border:none;border-radius:8px;padding:9px 16px;font:inherit;font-weight:600;cursor:pointer';
    ok.addEventListener('click', function () { window.location.href = home; });
    row.appendChild(ok); box.appendChild(h); box.appendChild(p); box.appendChild(row); bg.appendChild(box);
    document.body.appendChild(bg);
    ok.focus();
  }

  async function check() {
    if (stopped || document.hidden) return;
    try {
      var res = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
      if (res.status === 410) { stop(); return; }
      var data = await res.json();
      if (data && data.success && data.deleted) stop();
    } catch (e) { /* offline or busy: try again next time */ }
  }
  setInterval(check, 8000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
})();
