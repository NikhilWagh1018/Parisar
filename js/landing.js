/* js/landing.js — extracted from index.html */

const nav=document.getElementById('nav');
window.addEventListener('scroll',()=>{nav.classList.toggle('sc',scrollY>40);document.getElementById('topBtn').classList.toggle('show',scrollY>300)});
const obs=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting)e.target.classList.add('vs')}),{threshold:.1});
document.querySelectorAll('.rv').forEach(el=>obs.observe(el));