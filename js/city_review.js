// City Leader segment review + audit report screens.
// Plain fetch + the page's CSRF token (data-csrf on #caApp).
(function () {
  'use strict';
  var app = document.getElementById('caApp');
  if (!app) return;
  var CSRF = app.dataset.csrf || '';
  var toastEl = document.getElementById('caToast');

  function toast(msg, type) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.className = 'ca-toast show' + (type === 'error' ? ' error' : '');
    setTimeout(function () { toastEl.className = 'ca-toast'; }, 3200);
  }

  async function post(url, payload) {
    var res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload)
    });
    var data = null;
    try { data = await res.json(); } catch (e) { /* keep null */ }
    if (!res.ok || !data || !data.success) {
      throw new Error((data && data.error) || 'Something went wrong.');
    }
    return data;
  }

  // ── Segment review page ───────────────────────────────────────
  var approveBtn = document.getElementById('rvApprove');
  if (approveBtn) approveBtn.addEventListener('click', async function () {
    approveBtn.disabled = true;
    try {
      await post('../api/city/audit_review.php', { audit_id: app.dataset.auditId, segment_id: app.dataset.segmentId, action: 'approve' });
      window.location.href = app.dataset.nextUrl;
    } catch (e) { toast(e.message, 'error'); approveBtn.disabled = false; }
  });

  var openBtn = document.getElementById('rvSendBackOpen');
  var box = document.getElementById('rvSendBackBox');
  if (openBtn && box) openBtn.addEventListener('click', function () {
    box.classList.toggle('open');
    if (box.classList.contains('open')) document.getElementById('rvNote').focus();
  });

  var sendBackBtn = document.getElementById('rvSendBack');
  if (sendBackBtn) sendBackBtn.addEventListener('click', async function () {
    var note = document.getElementById('rvNote').value.trim();
    if (!note) { toast('Tell the surveyor what needs to be fixed.', 'error'); return; }
    sendBackBtn.disabled = true;
    try {
      await post('../api/city/audit_review.php', {
        audit_id: app.dataset.auditId, segment_id: app.dataset.segmentId, action: 'send_back',
        note: note, surveyor_id: document.getElementById('rvSurveyor').value
      });
      window.location.href = app.dataset.nextUrl;
    } catch (e) { toast(e.message, 'error'); sendBackBtn.disabled = false; }
  });

  // ── Audit report page ─────────────────────────────────────────
  var closeBtn = document.getElementById('rpClose');
  if (closeBtn) closeBtn.addEventListener('click', async function () {
    if (!window.confirm('Close this audit? Surveyors can no longer submit, and the report becomes final.')) return;
    closeBtn.disabled = true;
    try {
      await post('../api/city/audit_close.php', { audit_id: app.dataset.auditId, action: 'close' });
      window.location.reload();
    } catch (e) { toast(e.message, 'error'); closeBtn.disabled = false; }
  });

  var sendBtn = document.getElementById('rpSend');
  if (sendBtn) sendBtn.addEventListener('click', async function () {
    if (!window.confirm('Send this audit and its report to the Admin?')) return;
    sendBtn.disabled = true;
    try {
      await post('../api/city/audit_close.php', { audit_id: app.dataset.auditId, action: 'send' });
      window.location.reload();
    } catch (e) { toast(e.message, 'error'); sendBtn.disabled = false; }
  });

  // ── Admin decision on the report page ─────────────────────────
  var approveAuditBtn = document.getElementById('rpApprove');
  if (approveAuditBtn) approveAuditBtn.addEventListener('click', async function () {
    if (!window.confirm('Approve this audit? The report becomes final.')) return;
    approveAuditBtn.disabled = true;
    try {
      await post('../api/admin/audit_decide.php', { audit_id: app.dataset.auditId, action: 'approve' });
      window.location.reload();
    } catch (e) { toast(e.message, 'error'); approveAuditBtn.disabled = false; }
  });

  var returnOpen = document.getElementById('rpReturnOpen');
  var returnBox = document.getElementById('rpReturnBox');
  if (returnOpen && returnBox) returnOpen.addEventListener('click', function () {
    returnBox.classList.toggle('open');
    if (returnBox.classList.contains('open')) document.getElementById('rpNote').focus();
  });

  var returnBtn = document.getElementById('rpReturn');
  if (returnBtn) returnBtn.addEventListener('click', async function () {
    var note = document.getElementById('rpNote').value.trim();
    if (!note) { toast('Tell the City Leader what needs to change.', 'error'); return; }
    returnBtn.disabled = true;
    try {
      await post('../api/admin/audit_decide.php', { audit_id: app.dataset.auditId, action: 'return', note: note });
      window.location.reload();
    } catch (e) { toast(e.message, 'error'); returnBtn.disabled = false; }
  });

  var printBtn = document.getElementById('rpPrint');
  if (printBtn) printBtn.addEventListener('click', function () { window.print(); });
})();
