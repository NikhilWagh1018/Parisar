/* js/auth_simple.js — behaviour for the forgot / reset password pages.
   Starts the entrance animation, shows/hides passwords, and marks the
   submit button busy. The server does all the checking. */
(function () {
  'use strict';

  // Start the entrance animation once the fonts are in (or after 700 ms).
  var root = document.documentElement;
  var go = function () { root.classList.add('ready'); };
  if (document.fonts && document.fonts.ready) {
    Promise.race([document.fonts.ready, new Promise(function (r) { setTimeout(r, 700); })]).then(function () {
      requestAnimationFrame(go);
    });
  } else { go(); }

  // Show / hide password (eye icon)
  document.querySelectorAll('[data-toggle-pass]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-toggle-pass'));
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  // Busy state on submit
  var form = document.querySelector('form.card-form');
  if (!form) return;
  var submit = form.querySelector('.btn-submit');
  var label = submit ? submit.querySelector('.btn-label') : null;
  var original = label ? label.textContent : '';
  var busy = false;

  form.addEventListener('submit', function (e) {
    if (busy) { e.preventDefault(); return; }
    if (!form.checkValidity()) return;   // let the browser show its own message
    busy = true;
    submit.classList.add('is-loading');
    submit.setAttribute('aria-busy', 'true');
    if (label) label.textContent = 'Please wait…';
  });

  // Back button restores the page from cache — reset the busy state.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted || !submit) return;
    busy = false;
    submit.classList.remove('is-loading');
    submit.removeAttribute('aria-busy');
    if (label) label.textContent = original;
  });
})();
