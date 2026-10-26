// Shared styled confirmation dialog (replaces window.confirm on the City Leader / Admin screens).
// Usage:  if (!(await caConfirm({ title: 'Close audit', message: '...', confirmText: 'Close audit' }))) return;
// Resolves true on confirm, false on Cancel, Esc or a click outside the box.
// opts: { title, message, confirmText, danger }. Text is set with textContent, never as HTML.
// Styles live in css/city_audit.css (.ca-modal*).
(function () {
  'use strict';

  window.caConfirm = function (opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var prev = document.activeElement;
      var bg = document.createElement('div');
      bg.className = 'ca-modal-bg';
      var box = document.createElement('div');
      box.className = 'ca-modal';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      var h = document.createElement('h3');
      h.textContent = opts.title || 'Please confirm';
      var p = document.createElement('p');
      p.textContent = opts.message || '';
      var row = document.createElement('div');
      row.className = 'ca-modal-actions';
      var no = document.createElement('button');
      no.type = 'button'; no.className = 'ca-btn ghost'; no.textContent = 'Cancel';
      var yes = document.createElement('button');
      yes.type = 'button'; yes.className = 'ca-btn' + (opts.danger ? ' ca-btn-danger' : '');
      yes.textContent = opts.confirmText || 'Confirm';
      row.appendChild(no); row.appendChild(yes);
      box.appendChild(h); box.appendChild(p); box.appendChild(row);
      bg.appendChild(box);
      document.body.appendChild(bg);

      function done(v) {
        document.removeEventListener('keydown', onKey, true);
        bg.remove();
        if (prev && prev.focus) prev.focus();
        resolve(v);
      }
      function onKey(ev) {
        if (ev.key === 'Escape') { ev.preventDefault(); done(false); }
        else if (ev.key === 'Tab') {
          ev.preventDefault();
          (document.activeElement === no ? yes : no).focus();
        }
      }
      document.addEventListener('keydown', onKey, true);
      no.addEventListener('click', function () { done(false); });
      yes.addEventListener('click', function () { done(true); });
      bg.addEventListener('mousedown', function (ev) { if (ev.target === bg) done(false); });
      (opts.danger ? no : yes).focus();
    });
  };
})();
