// ═══════════════════════════════════════════════════════════════
//  js/map.js
//  Map View page. Fetches api/segments/map-data.php and renders
//  each GPS-tagged segment as a status-colored dot marker.
// ═══════════════════════════════════════════════════════════════

(function () {
  const STATUS_COLOR = {
    completed: '#2d5c10', // var(--tgreen)
    pending:   '#92600a', // var(--tamber)
  };

  const PUNE_CENTER = [18.5204, 73.8567];

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

  function popupHtml(p) {
    const statusCls   = p.status === 'completed' ? 'completed' : 'pending';
    const statusLabel = p.status === 'completed' ? 'Completed' : 'Pending';
    const label       = p.start_label ? `Segment ${p.segment_number} — ${p.start_label}` : `Segment ${p.segment_number}`;
    return `
      <div class="map-popup-road">${escapeHtml(p.road_name)}</div>
      <div class="map-popup-seg">${escapeHtml(label)}</div>
      <span class="map-popup-status ${statusCls}">${statusLabel}</span><br>
      <a class="map-popup-link" href="view.php?segment_id=${p.segment_id}&road_id=${p.road_id}">View segment &rarr;</a>
    `;
  }

  function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str ?? '';
    return d.innerHTML;
  }

  async function init() {
    const canvas = document.getElementById('map-canvas');
    const empty  = document.getElementById('map-empty-state');
    if (!canvas) return;

    let points = [];
    try {
      const res  = await fetch('../api/segments/map-data.php');
      const data = await res.json();
      if (data.success) points = data.points;
    } catch (e) {
      console.error('Failed to load map data', e);
    }

    if (points.length === 0) {
      canvas.style.display = 'none';
      if (empty) empty.style.display = 'block';
      return;
    }

    const map = L.map(canvas).setView(PUNE_CENTER, 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
      maxZoom: 19,
    }).addTo(map);

    const markers = [];
    points.forEach((p) => {
      const marker = L.marker([p.lat, p.lng], { icon: dotIcon(p.status) })
        .bindPopup(popupHtml(p));
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

  document.addEventListener('DOMContentLoaded', init);
})();
