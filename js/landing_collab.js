/* js/landing_collab.js
   Landing page "Collaborations": the organisations the Admin lists as working with Parisar.
   Reads /api/public/collaborators.php. The section and its menu link stay hidden until there is
   at least one collaborator to show, so an empty list never leaves a blank section. */
(function () {
  'use strict';

  var section = document.getElementById('collaborations');
  var grid = document.getElementById('collab-grid');
  var navLink = document.getElementById('nav-collab');
  if (!section || !grid) return;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }
  function initials(name) {
    var w = (String(name).match(/[\p{L}\p{N}]+/gu) || []).slice(0, 2);
    return w.map(function (x) { return x.charAt(0).toUpperCase(); }).join('') || '?';
  }
  // Only http(s) addresses become links, whatever the server sent.
  function safeUrl(u) {
    return typeof u === 'string' && /^https?:\/\//i.test(u) ? u : null;
  }

  function mark(name) { return el('span', 'collab-mark', initials(name)); }

  function card(c, i) {
    var url = safeUrl(c.url);
    var node = url ? el('a', 'collab-card') : el('div', 'collab-card');
    if (url) {
      node.href = url;
      node.target = '_blank';
      node.rel = 'noopener noreferrer';
      node.setAttribute('aria-label', c.name + ' (opens in a new tab)');
    }
    node.style.animationDelay = Math.min(i, 12) * 0.05 + 's';

    var logo = el('div', 'collab-logo');
    if (c.logo_url) {
      var img = el('img');
      img.src = c.logo_url;
      img.alt = c.name;
      img.loading = 'lazy';
      img.decoding = 'async';
      // A logo that fails to load falls back to the initials, so a card is never broken.
      img.onerror = function () { logo.textContent = ''; logo.appendChild(mark(c.name)); };
      logo.appendChild(img);
    } else {
      logo.appendChild(mark(c.name));
    }
    node.appendChild(logo);
    node.appendChild(el('div', 'collab-name', c.name));
    if (c.role) node.appendChild(el('div', 'collab-role', c.role));
    if (url) node.appendChild(el('div', 'collab-visit', 'Visit website ↗'));
    return node;
  }

  function show(list) {
    grid.textContent = '';
    if (!list.length) return;
    var frag = document.createDocumentFragment();
    list.forEach(function (c, i) { frag.appendChild(card(c, i)); });
    grid.appendChild(frag);
    section.hidden = false;
    if (navLink) navLink.hidden = false;
    // The page may have been opened at #collaborations before the section existed.
    if (location.hash === '#collaborations') section.scrollIntoView();
  }

  fetch('/api/public/collaborators.php', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) { if (d && d.success && Array.isArray(d.collaborators)) show(d.collaborators); })
    .catch(function () { /* leave the section hidden */ });
})();
