/* Service giver app - "Papers for going live" card in Settings: the owner sends a photo of the business licence and their ID,
   sees whether ETEL approved each one or sent it back (and why), and can send it again.
   API: sg_docs_list, sg_doc_submit (api/controllers/SgDocsController.php); photos go to api/upload.php with type "sg_document" (private folder).
   Additive: no inline handlers; every server value is escaped. */
(function () {
'use strict';
var box = null, loaded = false;
function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
var STATE = { pending: ['Waiting for ETEL to check', '#d98e04'], approved: ['Approved', '#2ecc71'], rejected: ['Sent back - please send again', '#e74c3c'] };

function paint(d) {
  var types = d.types || {}, have = {};
  (d.documents || []).forEach(function (x) { have[x.doc_type] = x; });
  box.innerHTML = Object.keys(types).map(function (k) {
    var t = types[k], x = have[k], st = x ? STATE[x.status] : [t.required ? 'Not sent yet' : 'Optional', '#9A9AAE'];
    var locked = x && x.status === 'approved';
    return '<div style="padding:10px 0;border-top:1px solid rgba(128,128,128,.2)">' +
      '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><b style="flex:1;min-width:140px">' + esc(t.label) + (t.required ? '' : ' <small style="opacity:.7">(optional)</small>') + '</b>' +
      '<span style="font-size:12px;font-weight:700;color:' + st[1] + '">' + esc(st[0]) + '</span>' +
      (locked ? '' : '<label class="btn btn-secondary btn-small" style="cursor:pointer;margin:0">' + (x ? '📷 Send a new photo' : '📷 Choose photo') + '<input type="file" accept="image/*" hidden data-paper="' + esc(k) + '"></label>') + '</div>' +
      (x && x.status === 'rejected' && x.reject_reason ? '<div style="font-size:12.5px;color:#e74c3c;margin-top:4px">ETEL says: ' + esc(x.reject_reason) + '</div>' : '') + '</div>';
  }).join('');
}

function load() {
  if (!box || typeof apiCall !== 'function') return;
  apiCall('sg_docs_list').then(function (d) { if (d) { loaded = true; paint(d); } else if (!loaded) box.textContent = 'Could not load your papers.'; });
}

function send(file, type) {
  var fd = new FormData(); fd.append('image', file); fd.append('type', 'sg_document');
  box.insertAdjacentHTML('afterbegin', '<div id="sg-paper-busy" style="font-size:12.5px;color:#0a6">Sending…</div>');
  fetch(ETEL.api.replace('boot.php', 'upload.php'), { method: 'POST', headers: { 'Authorization': 'Bearer ' + Auth.getToken() }, body: fd })
    .then(function (r) { return r.json(); })
    .then(function (u) {
      if (!u || !u.success) throw new Error((u && u.message) || 'Upload failed');
      return apiCall('sg_doc_submit', { doc_type: type, file_url: u.data.url });
    })
    .then(function (d) { if (typeof showNotification === 'function' && d) showNotification('Sent. ETEL will check it.', 'success'); load(); })
    .catch(function (e) { if (typeof showNotification === 'function') showNotification(e.message, 'error'); load(); });
}

function init() {
  box = document.getElementById('sg-papers');
  if (!box) return;
  box.addEventListener('change', function (e) {
    var t = e.target, type = t && t.getAttribute && t.getAttribute('data-paper');
    if (type && t.files && t.files[0]) send(t.files[0], type);
  });
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('[data-page="settings"]')) setTimeout(load, 50);
  });
  setTimeout(load, 1500);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
