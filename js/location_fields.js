// City + State inputs (sign-up and profile).
// The City box is a search box over a fixed list: typing narrows the suggestions, and only
// a city from the list is accepted. Picking one fills the State box, which is read-only
// because it always comes from the list. Common alternative spellings (Bangalore) are
// corrected to the listed name. The server repeats every check.
(function () {
  'use strict';
  window.initLocationFields = function (cityId, stateId) {
    var city = document.getElementById(cityId);
    var state = document.getElementById(stateId);
    var list = document.getElementById('cityList');
    if (!city || !state || !list) return;

    function key(s) { return String(s).replace(/\s+/g, ' ').trim().toLowerCase(); }
    var map = {}, aliases = {};
    Array.prototype.forEach.call(list.options, function (o) {
      map[key(o.value)] = { label: o.value, state: o.getAttribute('data-state') || '' };
    });
    try { aliases = JSON.parse(list.getAttribute('data-aliases') || '{}'); } catch (e) { aliases = {}; }

    state.readOnly = true;
    state.removeAttribute('list');
    state.removeAttribute('required');
    state.tabIndex = -1;

    var msg = document.createElement('div');
    msg.setAttribute('role', 'alert');
    msg.style.cssText = 'color:#b91c1c;font-size:12px;margin-top:4px;min-height:0';
    city.parentNode.insertBefore(msg, city.nextSibling);

    function lookup(v) {
      var k = key(v);
      if (map[k]) return map[k];
      var a = aliases[k];
      return a && map[key(a)] ? map[key(a)] : null;
    }
    // final = the person has finished (left the box or picked from the list)
    function sync(final) {
      var v = city.value, hit = lookup(v);
      if (hit && (final || key(v) === key(hit.label))) {
        if (final) city.value = hit.label;
        state.value = hit.state;
        city.setCustomValidity('');
        msg.textContent = '';
        return;
      }
      state.value = '';
      if (!key(v)) { city.setCustomValidity(''); msg.textContent = ''; return; }
      city.setCustomValidity('Pick your city from the list.');
      if (final) msg.textContent = 'Pick your city from the list. If it is not there, ask a Parisar admin to add it.';
    }
    city.addEventListener('input', function () { sync(false); });
    city.addEventListener('change', function () { sync(true); });
    city.addEventListener('blur', function () { sync(true); });
    if (city.form) {
      city.form.addEventListener('submit', function (ev) {
        sync(true);
        if (city.value.trim() && !lookup(city.value)) { ev.preventDefault(); city.focus(); }
      });
    }
    if (city.value.trim()) sync(true);
  };
})();
