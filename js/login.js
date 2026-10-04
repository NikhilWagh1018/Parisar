/* js/login.js — behaviour for auth/login.php (Option F)
   Show/hide password, friendly inline checks, and a busy state on submit.
   Sign-in itself is handled by the server. */
(function () {
  'use strict';

  var form = document.getElementById('login-form');
  if (!form) return;

  // ── Show / hide password ────────────────────────────────────
  document.querySelectorAll('[data-toggle-pass]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-toggle-pass'));
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  });

  // ── Inline checks ───────────────────────────────────────────
  var email = document.getElementById('email');
  var password = document.getElementById('password');

  function setError(input, message) {
    var msg = document.getElementById(input.id + '-msg');
    if (!msg) return;
    if (message) {
      input.setAttribute('aria-invalid', 'true');
      msg.textContent = message;
      msg.hidden = false;
      input.setAttribute('aria-describedby', msg.id);
    } else {
      input.removeAttribute('aria-invalid');
      msg.hidden = true;
      msg.textContent = '';
    }
  }

  [email, password].forEach(function (input) {
    input.addEventListener('input', function () { setError(input, ''); });
  });

  var busy = false;
  var submit = form.querySelector('.btn-submit');
  var label = submit.querySelector('.btn-label');

  form.addEventListener('submit', function (e) {
    if (busy) { e.preventDefault(); return; }

    var first = null;
    if (email.value.trim() === '') {
      setError(email, 'Enter your email address.');
      first = first || email;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
      setError(email, 'Enter a valid email address.');
      first = first || email;
    }
    if (password.value === '') {
      setError(password, 'Enter your password.');
      first = first || password;
    }
    if (first) {
      e.preventDefault();
      first.focus();
      return;
    }

    // Valid: let the form post, and show that something is happening.
    busy = true;
    submit.classList.add('is-loading');
    submit.setAttribute('aria-busy', 'true');
    label.textContent = 'Signing in…';
  });

  // Back button restores the page from cache — reset the busy state.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    busy = false;
    submit.classList.remove('is-loading');
    submit.removeAttribute('aria-busy');
    label.textContent = 'Sign in';
  });
})();
