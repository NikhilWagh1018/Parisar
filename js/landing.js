/* js/landing.js */

const nav = document.getElementById('nav');
window.addEventListener('scroll', () => {
  nav.classList.toggle('sc', scrollY > 40);
  document.getElementById('topBtn').classList.toggle('show', scrollY > 300);
});

// Hamburger menu toggle
const ham = document.getElementById('ham');
const nl = document.getElementById('nl');
if (ham && nl) {
  ham.addEventListener('click', () => {
    nl.classList.toggle('open');
    ham.classList.toggle('active');
  });
  // Close menu when a nav link is clicked
  nl.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', () => {
      nl.classList.remove('open');
      ham.classList.remove('active');
    });
  });
  // Close menu on outside click
  document.addEventListener('click', (e) => {
    if (!ham.contains(e.target) && !nl.contains(e.target)) {
      nl.classList.remove('open');
      ham.classList.remove('active');
    }
  });
}

// Scroll reveal
const obs = new IntersectionObserver(es => es.forEach(e => {
  if (e.isIntersecting) e.target.classList.add('vs');
}), { threshold: .1 });
document.querySelectorAll('.rv').forEach(el => obs.observe(el));