/* js/landing_collab.js
   Landing page "Collaborations": the organisations the Admin lists as working with Parisar.
   Reads /api/public/collaborators.php. The section and its menu link stay hidden until there is
   at least one collaborator to show, so an empty list never leaves a blank section.

   Speed: the last list is kept in localStorage and drawn straight away on a repeat visit (no
   pop-in), then checked against the server; the page only redraws if the list has changed.
   Cards fade in as they scroll into view. */
(function () {
  'use strict';

  var section = document.getElementById('collaborations');
  var grid = document.getElementById('collab-grid');
  var navLink = document.getElementById('nav-collab');
  if (!section || !grid) return;

  var CACHE_KEY = 'parisar.collab.v3';
  var LOGO_PREFIX = '/api/public/collaborator_logo.php?';
  var shown = null;               // the list on screen, as text, so an unchanged list is not redrawn
  var seen = null;                // reveals the cards when they scroll into view

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
  // Logos only ever come from our own endpoint.
  function safeLogo(u) {
    return typeof u === 'string' && u.indexOf(LOGO_PREFIX) === 0 ? u : null;
  }
  // The Admin's logo size, in percent: 50 to 150, 100 = the automatic fit.
  function scaleOf(n) {
    n = parseInt(n, 10);
    return n >= 50 && n <= 150 ? n : 100;
  }
  // Where the Admin put the logo in its circle, in percent of the tile: -50 to 50, 0 = centred.
  function posOf(n) {
    n = parseInt(n, 10);
    return n >= -50 && n <= 50 ? n : 0;
  }
  // Keep only well-formed rows (the cached copy is read back from the browser, so it is checked too).
  function clean(list) {
    return (Array.isArray(list) ? list : []).filter(function (c) {
      return c && typeof c === 'object' && typeof c.name === 'string' && c.name !== '';
    }).map(function (c) {
      return {
        name: c.name,
        role: typeof c.role === 'string' && c.role ? c.role : null,
        url: safeUrl(c.url),
        logo_url: safeLogo(c.logo_url),
        scale: scaleOf(c.logo_scale),
        x: posOf(c.logo_x),
        y: posOf(c.logo_y)
      };
    });
  }

  function mark(name) { return el('span', 'collab-mark', initials(name)); }

  function card(c, i) {
    var node = c.url ? el('a', 'collab-card') : el('div', 'collab-card');
    if (c.url) {
      node.href = c.url;
      node.target = '_blank';
      node.rel = 'noopener noreferrer';
      node.setAttribute('aria-label', c.name + ' (opens in a new tab)');
    }
    node.style.setProperty('--d', Math.min(i % 6, 5) * 0.06 + 's');   // a short stagger along each row

    var logo = el('div', 'collab-logo');
    logo.style.setProperty('--ls', String(c.scale / 100));
    logo.style.setProperty('--lx', c.x + '%');
    logo.style.setProperty('--ly', c.y + '%');
    if (c.logo_url) {
      var img = el('img');
      img.alt = c.name;
      img.loading = 'lazy';
      img.decoding = 'async';
      // A logo that fails to load falls back to the initials, so a card is never broken.
      img.onerror = function () { logo.textContent = ''; logo.appendChild(mark(c.name)); };
      img.src = c.logo_url;
      logo.appendChild(img);
    } else {
      logo.appendChild(mark(c.name));
    }
    node.appendChild(logo);
    node.appendChild(el('div', 'collab-name', c.name));
    if (c.role) node.appendChild(el('div', 'collab-role', c.role));
    if (c.url) {
      var visit = el('div', 'collab-visit', 'Visit website ');
      visit.appendChild(el('span', '', '↗'));
      node.appendChild(visit);
    }
    return node;
  }

  function reveal(nodes) {
    if (seen) { seen.disconnect(); seen = null; }
    if (!('IntersectionObserver' in window)) {
      nodes.forEach(function (n) { n.classList.add('vs'); });
      return;
    }
    seen = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add('vs');
        seen.unobserve(e.target);
      });
    }, { threshold: 0.15 });
    nodes.forEach(function (n) { seen.observe(n); });
  }

  function hide() {
    grid.textContent = '';
    section.hidden = true;
    if (navLink) navLink.hidden = true;
  }

  function show(list) {
    var sig = JSON.stringify(list);
    if (sig === shown) return;      // nothing changed since what is already on screen
    shown = sig;
    if (!list.length) { hide(); return; }

    var frag = document.createDocumentFragment();
    var nodes = list.map(function (c, i) { var n = card(c, i); frag.appendChild(n); return n; });
    grid.textContent = '';
    grid.appendChild(frag);
    section.hidden = false;
    if (navLink) navLink.hidden = false;
    reveal(nodes);
    // The page may have been opened at #collaborations before the section existed.
    if (location.hash === '#collaborations') section.scrollIntoView();
  }

  function readCache() {
    try { var s = localStorage.getItem(CACHE_KEY); return s ? clean(JSON.parse(s)) : []; }
    catch (e) { return []; }
  }
  function writeCache(list) {
    try {
      if (list.length) localStorage.setItem(CACHE_KEY, JSON.stringify(list));
      else localStorage.removeItem(CACHE_KEY);
    } catch (e) { /* storage blocked: the page just waits for the server next time */ }
  }

  var cached = readCache();
  if (cached.length) show(cached);  // instant on a repeat visit; corrected below if the Admin changed it

  fetch('/api/public/collaborators.php', { headers: { 'Accept': 'application/json' }, priority: 'low' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) {
      if (!d || !d.success || !Array.isArray(d.collaborators)) return;
      var list = clean(d.collaborators);
      writeCache(list);
      show(list);
    })
    .catch(function () { /* offline or blocked: keep whatever is on screen */ });
})();
