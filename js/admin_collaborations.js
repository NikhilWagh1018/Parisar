// Admin "Collaborations" page: add, edit, show/hide, reorder and remove the organisations listed
// on the home page. Plain fetch + the page's CSRF token (data-csrf on #npApp).
(function () {
  'use strict';
  var app = document.getElementById('npApp');
  var formBg = document.getElementById('coFormBg');
  var confBg = document.getElementById('coConfirmBg');
  if (!app || !formBg || !confBg) return;
  var CSRF = app.dataset.csrf || '';
  var MAX_LOGO = 1024 * 1024;
  var toastEl = document.getElementById('caToast');

  function toast(msg, type) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.className = 'ca-toast show' + (type === 'error' ? ' error' : '');
    setTimeout(function () { toastEl.className = 'ca-toast'; }, 3600);
  }
  function open(bg) { bg.hidden = false; }
  function close(bg) { bg.hidden = true; }
  [formBg, confBg].forEach(function (bg) {
    bg.addEventListener('mousedown', function (ev) { if (ev.target === bg) close(bg); });
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { close(formBg); close(confBg); } });

  async function send(url, body, isForm) {
    var headers = { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' };
    if (!isForm) headers['Content-Type'] = 'application/json';
    var res = await fetch(url, { method: 'POST', headers: headers, body: isForm ? body : JSON.stringify(body) });
    var data = null;
    try { data = await res.json(); } catch (e) { /* keep null */ }
    if (!res.ok || !data || !data.success) {
      var err = new Error((data && data.error) || 'Something went wrong.');
      err.fields = (data && data.errors) || {};
      throw err;
    }
    return data;
  }
  function reload(notice) { window.location.href = 'collaborations.php' + (notice ? '?notice=' + notice : ''); }

  // ── Add / edit ─────────────────────────────────────────────
  var form = document.getElementById('coForm');
  var saveBtn = document.getElementById('coFormSave');
  var title = document.getElementById('coFormTitle');
  var prev = document.getElementById('coLogoPrev');
  var removeWrap = document.getElementById('coRemoveWrap');
  var removeBox = form.elements['remove_logo'];
  var savedSrc = '';          // the logo the collaborator has now (edit only)

  function showErrors(fields) {
    form.querySelectorAll('.ca-field').forEach(function (f) { f.classList.remove('bad'); });
    Object.keys(fields || {}).forEach(function (name) {
      var input = form.querySelector('[name="' + name + '"]');
      var wrap = input && input.closest('.ca-field');
      if (!wrap) return;
      wrap.classList.add('bad');
      var e = wrap.querySelector('.ca-err');
      if (e) e.textContent = fields[name];
    });
  }
  function setPreview(src) {
    if (src) { prev.src = src; prev.hidden = false; } else { prev.removeAttribute('src'); prev.hidden = true; }
  }

  function openForm(data) {
    form.reset(); showErrors({});
    var edit = !!data;
    form.elements['collaborator_id'].value = edit ? data.id : '';
    form.elements['name'].value = edit ? data.name : '';
    form.elements['role_label'].value = edit ? data.role : '';
    form.elements['website_url'].value = edit ? data.url : '';
    savedSrc = edit && data.logo ? data.logoSrc : '';
    setPreview(savedSrc);
    removeWrap.hidden = !savedSrc;
    title.textContent = edit ? 'Edit collaborator' : 'Add a collaborator';
    saveBtn.textContent = edit ? 'Save changes' : 'Add collaborator';
    open(formBg);
    form.elements['name'].focus();
  }
  document.getElementById('coAddBtn').addEventListener('click', function () { openForm(null); });
  document.getElementById('coFormCancel').addEventListener('click', function () { close(formBg); });

  document.querySelectorAll('.coEdit').forEach(function (b) {
    b.addEventListener('click', function () {
      openForm({ id: b.dataset.id, name: b.dataset.name, role: b.dataset.role, url: b.dataset.url,
                 logo: b.dataset.logo === '1', logoSrc: b.dataset.logoSrc });
    });
  });

  form.elements['logo'].addEventListener('change', function () {
    var f = form.elements['logo'].files[0];
    if (!f) { setPreview(removeBox.checked ? '' : savedSrc); return; }
    if (f.size > MAX_LOGO) {
      showErrors({ logo: 'The logo is too large. The limit is 1 MB.' });
      form.elements['logo'].value = '';
      setPreview(removeBox.checked ? '' : savedSrc);
      return;
    }
    showErrors({});
    var r = new FileReader();
    r.onload = function () { setPreview(String(r.result)); };
    r.readAsDataURL(f);
  });
  removeBox.addEventListener('change', function () {
    if (form.elements['logo'].files[0]) return;
    setPreview(removeBox.checked ? '' : savedSrc);
  });

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    saveBtn.disabled = true;
    var label = saveBtn.textContent;
    saveBtn.textContent = 'Saving…';
    var editing = form.elements['collaborator_id'].value !== '';
    try {
      await send('../api/admin/collaborator_save.php', new FormData(form), true);
      reload(editing ? 'collab_updated' : 'collab_added');
    } catch (err) {
      showErrors(err.fields);
      if (!Object.keys(err.fields || {}).length) toast(err.message, 'error');
      saveBtn.disabled = false;
      saveBtn.textContent = label;
    }
  });

  // ── Show / hide, move ──────────────────────────────────────
  document.querySelectorAll('.coToggle').forEach(function (b) {
    b.addEventListener('click', async function () {
      b.disabled = true;
      try {
        await send('../api/admin/collaborator_action.php', { collaborator_id: parseInt(b.dataset.id, 10), action: b.dataset.do });
        reload(b.dataset.do === 'hide' ? 'collab_hidden' : 'collab_shown');
      } catch (err) { toast(err.message, 'error'); b.disabled = false; }
    });
  });
  document.querySelectorAll('.coMove').forEach(function (b) {
    b.addEventListener('click', async function () {
      b.disabled = true;
      try {
        await send('../api/admin/collaborator_action.php', { collaborator_id: parseInt(b.dataset.id, 10), action: b.dataset.dir });
        reload('');
      } catch (err) { toast(err.message, 'error'); b.disabled = false; }
    });
  });

  // ── Remove ─────────────────────────────────────────────────
  var confText = document.getElementById('coConfText'), goBtn = document.getElementById('coConfGo');
  var removeId = null;
  document.querySelectorAll('.coDelete').forEach(function (b) {
    b.addEventListener('click', function () {
      removeId = parseInt(b.dataset.id, 10);
      confText.textContent = '“' + b.dataset.name + '” is taken off the home page and this list straight away. The record and logo stay in the database. To keep it and only hide it, use Hide instead.';
      goBtn.disabled = false;
      open(confBg);
      goBtn.focus();
    });
  });
  document.getElementById('coConfCancel').addEventListener('click', function () { close(confBg); });
  goBtn.addEventListener('click', async function () {
    if (removeId === null) return;
    goBtn.disabled = true;
    try {
      await send('../api/admin/collaborator_action.php', { collaborator_id: removeId, action: 'delete' });
      reload('collab_removed');
    } catch (err) { toast(err.message, 'error'); goBtn.disabled = false; }
  });
})();
