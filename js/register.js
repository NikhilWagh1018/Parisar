/* register.js — extracted from register.php */

function tog(id, btn) {
  const el = document.getElementById(id);
  el.type  = el.type === 'password' ? 'text' : 'password';
  btn.textContent = el.type === 'password' ? 'Show' : 'Hide';
}
function checkStr(v) {
  let s = 0;
  if (v.length >= 8) s++; if (v.length >= 12) s++;
  if (/[A-Z]/.test(v)) s++; if (/[0-9]/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v)) s++;
  const c = ['','#dc2626','#f97316','#eab308','#84cc16','#16a34a'];
  const w = ['0%','22%','42%','62%','82%','100%'];
  const l = ['','Weak','Fair','Fair','Good','Strong'];
  document.getElementById('sf').style.cssText = 'width:' + w[s] + ';background:' + c[s];
  document.getElementById('sl').textContent   = v.length ? (l[s] || '') : 'Enter a password';
}
document.getElementById('inp-phone').addEventListener('input', function () {
  this.value = this.value.replace(/\D/g, '').slice(0, 10);
});
document.getElementById('inp-name').addEventListener('input', function () {
  this.value = this.value.replace(/[^A-Za-z\s'\-\.]/g, '');
});
document.getElementById('inp-age').addEventListener('input', function () {
  if (+this.value > 80) this.value = 80;
  if (+this.value < 0)  this.value = '';
});