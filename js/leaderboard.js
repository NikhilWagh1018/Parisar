// ═══════════════════════════════════════════════════════════════
//  js/leaderboard.js
//  Leaderboard page. Fetches api/leaderboard/data.php and renders
//  a ranked table of surveyors by segments completed + distance
//  audited, for either "week" (current ISO week) or "all" (all-time).
// ═══════════════════════════════════════════════════════════════

(function () {
  const MEDALS = { 1: '🥇', 2: '🥈', 3: '🥉' };
  let currentWindow = 'week';

  function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str ?? '';
    return d.innerHTML;
  }

  function formatDistance(m) {
    if (m >= 1000) return (m / 1000).toFixed(2) + ' km';
    return Math.round(m) + ' m';
  }

  function rankCell(rank) {
    return MEDALS[rank]
      ? `<span class="lb-medal">${MEDALS[rank]}</span>`
      : `<span class="lb-rank-num">#${rank}</span>`;
  }

  function renderYourRank(data) {
    const el = document.getElementById('lb-your-rank');
    if (data.your_rank === null) {
      el.style.display = 'none';
      el.innerHTML = '';
      return;
    }
    // Only surface this banner when you're ranked but not visible in the
    // top-50 table below, so it doesn't duplicate your own highlighted row.
    if (data.your_rank <= data.rows.length) {
      el.style.display = 'none';
      el.innerHTML = '';
      return;
    }
    el.style.display = '';
    el.innerHTML = `<div class="lb-your-rank-banner">Your rank: <strong>#${data.your_rank}</strong></div>`;
  }

  async function loadWindow(win) {
    currentWindow = win;
    const tbody = document.getElementById('lb-tbody');
    const empty = document.getElementById('lb-empty-state');
    const wrap  = document.getElementById('lb-table-wrap');

    tbody.innerHTML = `<tr><td colspan="4"><div class="skeleton" style="height:18px;width:100%"></div></td></tr>`;

    let data;
    try {
      const res = await fetch(`../api/leaderboard/data.php?window=${encodeURIComponent(win)}`);
      data = await res.json();
    } catch (e) {
      console.error('Failed to load leaderboard', e);
      tbody.innerHTML = `<tr><td colspan="4">Couldn't load the leaderboard. Try again shortly.</td></tr>`;
      return;
    }

    if (!data.success) {
      tbody.innerHTML = `<tr><td colspan="4">Couldn't load the leaderboard. Try again shortly.</td></tr>`;
      return;
    }

    if (data.rows.length === 0) {
      wrap.style.display = 'none';
      empty.style.display = 'block';
      empty.textContent = win === 'week'
        ? 'No audits yet this week — be the first on the board!'
        : 'No audits yet — get out there and claim the top spot!';
      document.getElementById('lb-your-rank').style.display = 'none';
      return;
    }

    wrap.style.display = '';
    empty.style.display = 'none';

    tbody.innerHTML = data.rows.map((r) => `
      <tr class="${r.is_you ? 'lb-row-you' : ''}">
        <td class="lb-col-rank">${rankCell(r.rank)}</td>
        <td class="lb-col-name">${escapeHtml(r.surveyor_name)}${r.is_you ? ' <span class="lb-you-badge">You</span>' : ''}</td>
        <td class="lb-col-num">${r.segments_completed}</td>
        <td class="lb-col-num">${formatDistance(r.distance_m)}</td>
      </tr>
    `).join('');

    renderYourRank(data);
  }

  function initToggle() {
    const buttons = document.querySelectorAll('.lb-window-btn');
    buttons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const win = btn.dataset.window;
        if (win === currentWindow) return;
        buttons.forEach((b) => b.classList.toggle('active', b === btn));
        loadWindow(win);
      });
    });
  }

  function init() {
    if (!document.getElementById('lb-tbody')) return;
    initToggle();
    loadWindow('week');
  }

  document.addEventListener('DOMContentLoaded', init);
})();
