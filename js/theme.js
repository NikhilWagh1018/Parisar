/* js/theme.js — Dark/Light theme persistence
   Add <script src="../js/theme.js"></script> BEFORE </body> on all pages
   ─────────────────────────────────────────── */

(function() {
  // Apply saved theme immediately (before paint)
  var saved = localStorage.getItem('ca_theme') || 'light';
  document.body.classList.add(saved);
})();

function toggleTheme() {
  var body = document.body;
  var isDark = body.classList.contains('dark');
  body.classList.toggle('dark', !isDark);
  body.classList.toggle('light', isDark);
  localStorage.setItem('ca_theme', isDark ? 'light' : 'dark');
  // Update toggle button icon
  var btns = document.querySelectorAll('.theme-toggle');
  btns.forEach(function(btn) {
    btn.textContent = isDark ? '🌙' : '☀️';
  });
}

// Set correct icon on page load
document.addEventListener('DOMContentLoaded', function() {
  var isDark = document.body.classList.contains('dark');
  var btns = document.querySelectorAll('.theme-toggle');
  btns.forEach(function(btn) {
    btn.textContent = isDark ? '☀️' : '🌙';
    btn.setAttribute('onclick', 'toggleTheme()');
    btn.setAttribute('aria-label', 'Toggle theme');
    btn.setAttribute('title', isDark ? 'Switch to light mode' : 'Switch to dark mode');
  });
});
