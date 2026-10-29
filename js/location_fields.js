// City + State inputs (sign-up and profile). Picking a known city from the
// suggestions fills its state and locks that box; an unknown city leaves the
// State box open so the person chooses a state from the list.
(function () {
  'use strict';
  window.initLocationFields = function (cityId, stateId) {
    var city = document.getElementById(cityId);
    var state = document.getElementById(stateId);
    var list = document.getElementById('cityList');
    if (!city || !state || !list) return;
    var map = {};
    Array.prototype.forEach.call(list.options, function (o) {
      map[o.value.toLowerCase()] = o.getAttribute('data-state') || '';
    });
    var autoFilled = false;
    function sync() {
      var key = city.value.replace(/\s+/g, ' ').trim().toLowerCase();
      var st = Object.prototype.hasOwnProperty.call(map, key) ? map[key] : '';
      if (st) {
        state.value = st;
        state.readOnly = true;
        autoFilled = true;
      } else {
        if (autoFilled) state.value = '';
        state.readOnly = false;
        autoFilled = false;
      }
    }
    city.addEventListener('input', sync);
    city.addEventListener('change', sync);
    sync();
  };
})();
