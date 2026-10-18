/* ETEL Admin - Rides page: Ride tips card. A rider tips a driver by sending the money to an Etel bank account and
   entering the transfer reference. Check that the money arrived, then approve: the driver gets ALL of it (no commission)
   in their earnings. Reject if you cannot find the transfer: the driver gets nothing.
   Backend: api/lib/ride_tip.php, actions ride_tip_* / admin_ride_tip_* in api/controllers/RideController.php. */
(function () {
'use strict';
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function api(action, body) {
  var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  var apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  return fetch(apiUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
    body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
}
function person(name, phone) { return esc(name || '-') + '<div style="font-size:11px;opacity:.7">' + esc(phone || '') + '</div>'; }
var COLOR = { approved: '#2E9E5B', rejected: '#C0392B', pending: '#E0A800' };

function card() {
  var panel = document.getElementById('panel-rides'); if (!panel) return null;
  var el = document.getElementById('rtip-card');
  if (!el) {
    el = document.createElement('div'); el.id = 'rtip-card';
    el.style.cssText = 'margin-bottom:22px;border:1px solid rgba(212,175,55,.3);border-radius:14px;padding:16px';
    panel.insertBefore(el, panel.firstChild);
  }
  return el;
}

function render(rows, pendingCount) {
  var el = card(); if (!el) return;
  var pending = rows.filter(function (t) { return t.status === 'pending'; });
  var done = rows.filter(function (t) { return t.status !== 'pending'; }).slice(0, 20);
  var html = '<div style="font-weight:800;font-size:15px;margin-bottom:4px">Ride tips' + (pendingCount ? ' <span style="color:#E0A800">(' + pendingCount + ' waiting)</span>' : '') + '</div>' +
    '<div style="font-size:12px;opacity:.8;margin-bottom:12px;line-height:1.5">Riders send a tip to one of your bank accounts and enter the transfer reference. <b>Check that the money arrived</b>, then approve: the driver gets all of it, no commission, in the earnings they can cash out. Reject if you cannot find the transfer. Each transfer reference can be used once.</div>';
  if (!pending.length) html += '<div style="opacity:.7;font-size:13px;margin-bottom:8px">No tips waiting.</div>';
  else {
    html += '<div style="overflow-x:auto"><table class="data-table" style="width:100%"><thead><tr><th>Trip</th><th>Rider</th><th>Driver</th><th>Amount</th><th>Paid by</th><th></th></tr></thead><tbody>' +
      pending.map(function (t) {
        return '<tr data-id="' + t.id + '" data-amt="' + esc(parseFloat(t.amount)) + '"><td>#' + esc(t.ride_id) + '<div style="font-size:11px;opacity:.7">' + esc(String(t.created_at || '').slice(0, 16)) + '</div></td>' +
          '<td>' + person(t.rider_name, t.rider_phone) + '</td><td>' + person(t.driver_name, t.driver_phone) + '</td>' +
          '<td><b>ETB ' + esc(parseFloat(t.amount).toFixed(2)) + '</b></td>' +
          '<td style="font-size:12px"><span style="font-family:monospace">' + esc(t.payment_ref) + '</span><div style="opacity:.7">' + esc(t.bank_key || '') + '</div>' + (t.note ? '<div style="opacity:.7">' + esc(t.note) + '</div>' : '') + '</td>' +
          '<td style="white-space:nowrap"><button class="btn btn-primary rt-ok" style="padding:6px 12px">Approve</button> <button class="btn btn-secondary rt-no" style="padding:6px 12px">Reject</button></td></tr>';
      }).join('') + '</tbody></table></div>';
  }
  if (done.length) {
    html += '<div style="font-weight:700;margin:14px 0 6px">Recently decided</div><div style="overflow-x:auto"><table class="data-table" style="width:100%"><thead><tr><th>Trip</th><th>Driver</th><th>Amount</th><th>Status</th><th>Note</th></tr></thead><tbody>' +
      done.map(function (t) {
        return '<tr><td>#' + esc(t.ride_id) + '</td><td>' + person(t.driver_name, t.driver_phone) + '</td><td>ETB ' + esc(parseFloat(t.amount).toFixed(2)) + '</td>' +
          '<td><span style="font-size:11px;font-weight:800;color:' + (COLOR[t.status] || '#888') + '">' + esc(String(t.status).toUpperCase()) + '</span></td><td style="font-size:12px">' + esc(t.review_note || '') + '</td></tr>';
      }).join('') + '</tbody></table></div>';
  }
  el.innerHTML = html;
  var i, list = el.querySelectorAll('.rt-ok');
  for (i = 0; i < list.length; i++) list[i].onclick = function () {
    var tr = this.closest('tr');
    if (!window.confirm('Did the transfer of ETB ' + tr.getAttribute('data-amt') + ' arrive? The driver will get all of it.')) return;
    act({ id: tr.getAttribute('data-id'), decision: 'approve' });
  };
  list = el.querySelectorAll('.rt-no');
  for (i = 0; i < list.length; i++) list[i].onclick = function () {
    var note = window.prompt('Why are you rejecting it? (for your records)', '');
    if (note === null) return;
    act({ id: this.closest('tr').getAttribute('data-id'), decision: 'reject', note: note });
  };
}

function act(body) {
  api('admin_ride_tip_review', body).then(function (r) {
    if (r && r.success) load(); else window.alert((r && r.message) || 'Could not save');
  }).catch(function () { window.alert('Network error'); });
}

function load() {
  var el = card(); if (!el) return;
  api('admin_ride_tip_list', {}).then(function (r) {
    if (!r || !r.success) { el.innerHTML = '<div style="color:#C0392B">' + esc((r && r.message) || 'Could not load ride tips') + '</div>'; return; }
    render((r.data && r.data.tips) || [], (r.data && r.data.pending_count) || 0);
  }).catch(function () { el.innerHTML = '<div style="color:#C0392B">Network error</div>'; });
}

// load when the Rides page is opened (it is shown by switching its display style)
function watch() {
  var panel = document.getElementById('panel-rides'); if (!panel) return;
  var was = panel.style.display !== 'none';
  if (was) load();
  var mo = new MutationObserver(function () {
    var vis = panel.style.display !== 'none';
    if (vis && !was) load();
    was = vis;
  });
  mo.observe(panel, { attributes: true, attributeFilter: ['style'] });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(watch, 600); }); else setTimeout(watch, 600);
})();
