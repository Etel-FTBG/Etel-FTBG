/* ETEL Admin - Rides page: "Browser errors" card under System health. Shows what the rider and driver apps reported
   (api/client-error.php): uncaught errors and server-error answers, each once with a counter. The request id (rid) of a
   failed API call is the key to its server log line. Backend: actions admin_client_errors / admin_client_errors_clear. */
(function () {
'use strict';
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function api(action, body) {
  var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  var apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  return fetch(apiUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
    body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
}
var KIND = { js: 'Script error', promise: 'Unhandled', api: 'Server error' };

function card() {
  var anchor = document.getElementById('sys-health'); if (!anchor || !anchor.parentNode) return null;
  var el = document.getElementById('cerr-card');
  if (!el) {
    el = document.createElement('div'); el.id = 'cerr-card';
    el.style.cssText = 'margin-bottom:16px;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:14px 16px;background:rgba(255,255,255,.03)';
    anchor.parentNode.insertBefore(el, anchor.nextSibling);
    el.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-cerr]') : null; if (!b) return;
      if (b.getAttribute('data-cerr') === 'refresh') { load(); return; }
      if (b.getAttribute('data-cerr') === 'clear' && window.confirm('Clear the error list?')) api('admin_client_errors_clear', {}).then(load);
    });
  }
  return el;
}
function render(rows, days) {
  var el = card(); if (!el) return;
  var html = '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><h3 style="color:#f0cf5a;font-size:15px;margin:0;flex:1">Browser errors <span style="font-weight:400;opacity:.7;font-size:12px">last ' + esc(days) + ' days</span></h3>' +
    '<button class="rides-filter-btn" data-cerr="refresh">Refresh</button>' + (rows.length ? '<button class="rides-filter-btn" data-cerr="clear">Clear</button>' : '') + '</div>';
  if (!rows.length) html += '<div style="margin-top:8px;font-size:13px;opacity:.75">Nothing reported. The apps send a note here when a screen breaks.</div>';
  else {
    html += '<div style="overflow-x:auto;margin-top:8px"><table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Last seen</th><th>App</th><th>What</th><th>Times</th><th>Request id</th></tr></thead><tbody>' +
      rows.slice(0, 30).map(function (r) {
        return '<tr><td style="white-space:nowrap">' + esc(String(r.last_seen || '').slice(5, 16)) + '</td><td>' + esc(r.app) + '</td>' +
          '<td><b>' + esc(KIND[r.kind] || r.kind) + '</b>: ' + esc(r.message) + '<div style="opacity:.65">' + esc(r.page) + (r.source ? ' &middot; ' + esc(String(r.source).split('/').pop()) + ':' + esc(r.line_no) : '') + '</div></td>' +
          '<td>' + esc(r.hits) + '</td><td style="font-family:monospace">' + esc(r.rid || '') + '</td></tr>';
      }).join('') + '</tbody></table></div>';
  }
  el.innerHTML = html;
}
function load() {
  if (!card()) return;
  api('admin_client_errors', {}).then(function (r) {
    if (r && r.success) render((r.data && r.data.errors) || [], (r.data && r.data.days) || 7);
  }).catch(function () {});
}
function hook() {
  if (typeof window.systemHealthLoad !== 'function') return false;
  var base = window.systemHealthLoad;
  if (base.__cerr) return true;
  window.systemHealthLoad = function () { load(); return base.apply(this, arguments); };
  window.systemHealthLoad.__cerr = true;
  return true;
}
if (!hook()) { var t = setInterval(function () { if (hook()) clearInterval(t); }, 500); setTimeout(function () { clearInterval(t); }, 15000); }
})();
