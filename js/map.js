// ═══════════════════════════════════════════════════════════════
//  js/map.js
//  Map View page. Fetches api/segments/map-data.php and renders
//  each GPS-tagged segment as a status-colored dot marker.
//  Supports two scopes via the toolbar toggle:
//    "mine" — the logged-in user's own latest audit per segment
//    "all"  — the latest audit by anyone per segment (shows who)
// ═══════════════════════════════════════════════════════════════

(function () {
  const PUNE_CENTER = [18.5204, 73.8567];

  let map = null;
  let markers = [];
  let currentScope = 'mine';

  function dotIcon(status) {
    const cls = status === 'completed' ? 'completed' : 'pending';
    return L.divIcon({
      className: '',
      html: `<div class="map-dot-marker ${cls}"></div>`,
      iconSize: [16, 16],
      iconAnchor: [8, 8],
      popupAnchor: [0, -8],
    });
  }

  function popupHtml(p, scope) {
    const statusCls   = p.status === 'completed' ? 'completed' : 'pending';
    const statusLabel = p.status === 'completed' ? 'Completed' : 'Pending';
    const label       = p.start_label ? `Segment ${p.segment_number} — ${p.start_label}` : `Segment ${p.segment_number}`;
    const surveyorRow = (scope === 'all' && p.surveyor_name)
      ? `<div class="map-popup-surveyor">Audited by ${escapeHtml(p.surveyor_name)}</div>`
      : '';
    return `
      <div class="map-popup-road">${escapeHtml(p.road_name)}</div>
      <div class="map-popup-seg">${escapeHtml(label)}</div>
      ${surveyorRow}
      <span class="map-popup-status ${statusCls}">${statusLabel}</span><br>
      <a class="map-popup-link" href="view.php?segment_id=${p.segment_id}&road_id=${p.road_id}">View segment &rarr;</a>
    `;
  }

  function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str ?? '';
    return d.innerHTML;
  }

  function clearMarkers() {
    markers.forEach((m) => map.removeLayer(m));
    markers = [];
  }

  async function loadScope(scope) {
    currentScope = scope;
    const canvas = document.getElementById('map-canvas');
    const empty  = document.getElementById('map-empty-state');

    let points = [];
    try {
      const res  = await fetch(`../api/segments/map-data.php?scope=${encodeURIComponent(scope)}`);
      const data = await res.json();
      if (data.success) points = data.points;
    } catch (e) {
      console.error('Failed to load map data', e);
    }

    if (points.length === 0) {
      clearMarkers();
      canvas.style.display = 'none';
      if (empty) {
        empty.style.display = 'block';
        empty.textContent = scope === 'all'
          ? 'No GPS-tagged segments yet across any surveyor.'
          : "No GPS-tagged segments yet — audit a road with GPS capture on the form to see it appear here.";
      }
      return;
    }

    canvas.style.display = '';
    if (empty) empty.style.display = 'none';

    if (!map) {
      map = L.map(canvas).setView(PUNE_CENTER, 12);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19,
      }).addTo(map);
    } else {
      map.invalidateSize();
    }

    clearMarkers();
    points.forEach((p) => {
      const marker = L.marker([p.lat, p.lng], { icon: dotIcon(p.status) })
        .bindPopup(popupHtml(p, scope));
      marker.addTo(map);
      markers.push(marker);
    });

    if (markers.length > 1) {
      const group = L.featureGroup(markers);
      map.fitBounds(group.getBounds().pad(0.15));
    } else {
      map.setView([points[0].lat, points[0].lng], 15);
    }
  }

  function initToggle() {
    const buttons = document.querySelectorAll('.map-scope-btn');
    buttons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const scope = btn.dataset.scope;
        if (scope === currentScope) return;
        buttons.forEach((b) => b.classList.toggle('active', b === btn));
        loadScope(scope);
      });
    });
  }

  function init() {
    if (!document.getElementById('map-canvas')) return;
    initToggle();
    loadScope('mine');
  }

  document.addEventListener('DOMContentLoaded', init);
})();
