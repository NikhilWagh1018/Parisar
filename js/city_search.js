// Searchable city dropdown, shared by sign-up, profile and New Project.
//
// CitySearch.attach(input, items, onPick, opts)
//   items  : [{ label, state, boost }]  boost > 0 lifts a city that the app already has
//   onPick : called with the chosen item (the input's value is already set)
//   opts   : { aliases: {typed -> label} }
//
// The list opens only while the person types, never on click or on the browser's
// arrow, and shows the best matches first: exact name, then names that start with
// what was typed, then names with a word that starts with it, then any other match.
(function () {
  'use strict';

  var MAX = 8;
  var css = '.cs-list{position:absolute;z-index:1000;left:0;right:0;top:100%;margin:4px 0 0;padding:4px;list-style:none;' +
    'background:#fff;color:#1b2a1b;border:1px solid rgba(0,0,0,.14);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.14);' +
    'max-height:320px;overflow-y:auto}' +
    '.cs-item{display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding:8px 10px;border-radius:7px;cursor:pointer;font-size:.9rem}' +
    '.cs-item small{color:#6b7a6b;font-size:.75rem;white-space:nowrap}' +
    '.cs-item.on,.cs-item:hover{background:#eaf3df}' +
    '.cs-item mark{background:none;color:inherit;font-weight:700}' +
    '.cs-empty{padding:9px 10px;font-size:.82rem;color:#6b7a6b}';
  var styled = false;
  function addStyle() {
    if (styled) return;
    styled = true;
    var s = document.createElement('style');
    s.textContent = css;
    document.head.appendChild(s);
  }

  function norm(s) { return String(s).replace(/\s+/g, ' ').trim().toLowerCase(); }

  // 0 exact, 1 starts with, 2 a word starts with, 3 contains, -1 no match
  function score(label, q) {
    var l = norm(label);
    if (l === q) return 0;
    if (l.indexOf(q) === 0) return 1;
    var i = l.indexOf(q);
    while (i > 0) {
      if (/[\s(\-]/.test(l.charAt(i - 1))) return 2;
      i = l.indexOf(q, i + 1);
    }
    return l.indexOf(q) > -1 ? 3 : -1;
  }

  function rank(items, typed, aliases) {
    var q = norm(typed);
    if (!q) return [];
    var viaAlias = aliases && aliases[q] ? norm(aliases[q]) : null;
    var out = [];
    items.forEach(function (it) {
      var sc = score(it.label, q);
      if (sc < 0 && viaAlias && norm(it.label) === viaAlias) sc = 0;
      if (sc >= 0) out.push({ it: it, sc: sc });
    });
    out.sort(function (a, b) {
      return (a.sc - b.sc) || ((b.it.boost || 0) - (a.it.boost || 0)) ||
        (a.it.label.length - b.it.label.length) || (a.it.label < b.it.label ? -1 : 1);
    });
    return out.slice(0, MAX).map(function (r) { return r.it; });
  }

  function highlight(label, typed) {
    var q = norm(typed), i = label.toLowerCase().indexOf(q);
    var frag = document.createDocumentFragment();
    if (!q || i < 0) { frag.appendChild(document.createTextNode(label)); return frag; }
    frag.appendChild(document.createTextNode(label.slice(0, i)));
    var m = document.createElement('mark');
    m.textContent = label.slice(i, i + q.length);
    frag.appendChild(m);
    frag.appendChild(document.createTextNode(label.slice(i + q.length)));
    return frag;
  }

  function attach(input, items, onPick, opts) {
    addStyle();
    opts = opts || {};
    input.removeAttribute('list');          // no browser dropdown, no arrow
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');

    var holder = input.parentNode;
    if (getComputedStyle(holder).position === 'static') holder.style.position = 'relative';
    var ul = document.createElement('ul');
    ul.className = 'cs-list';
    ul.setAttribute('role', 'listbox');
    ul.hidden = true;
    input.insertAdjacentElement('afterend', ul);

    var shown = [], active = -1;

    function close() { ul.hidden = true; active = -1; input.setAttribute('aria-expanded', 'false'); }
    function mark() {
      Array.prototype.forEach.call(ul.children, function (li, i) {
        li.classList.toggle('on', i === active);
        if (i === active) li.scrollIntoView({ block: 'nearest' });
      });
    }
    function pick(i) {
      var it = shown[i];
      if (!it) return;
      input.value = it.label;
      close();
      if (onPick) onPick(it);
    }
    function open() {
      var typed = input.value;
      shown = rank(items, typed, opts.aliases);
      ul.textContent = '';
      if (!norm(typed)) { close(); return; }
      if (!shown.length) {
        var e = document.createElement('li');
        e.className = 'cs-empty';
        e.textContent = 'No city found. Check the spelling.';
        ul.appendChild(e);
      }
      shown.forEach(function (it, i) {
        var li = document.createElement('li');
        li.className = 'cs-item';
        li.setAttribute('role', 'option');
        var name = document.createElement('span');
        name.appendChild(highlight(it.label, typed));
        var st = document.createElement('small');
        st.textContent = it.state;
        li.appendChild(name);
        li.appendChild(st);
        li.addEventListener('mousedown', function (ev) { ev.preventDefault(); pick(i); });
        ul.appendChild(li);
      });
      active = shown.length ? 0 : -1;
      ul.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      mark();
    }

    // Open only from typing.
    input.addEventListener('input', open);
    input.addEventListener('keydown', function (ev) {
      if (ul.hidden) return;
      if (ev.key === 'ArrowDown') { ev.preventDefault(); active = Math.min(active + 1, shown.length - 1); mark(); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); active = Math.max(active - 1, 0); mark(); }
      else if (ev.key === 'Enter') { if (active > -1 && shown.length) { ev.preventDefault(); pick(active); } }
      else if (ev.key === 'Escape') { close(); }
      else if (ev.key === 'Tab') { if (active > -1 && shown.length && norm(input.value) !== norm(shown[active].label) && score(shown[active].label, norm(input.value)) === 0) pick(active); close(); }
    });
    input.addEventListener('blur', close);
    return { close: close };
  }

  window.CitySearch = { attach: attach, rank: rank };
})();
