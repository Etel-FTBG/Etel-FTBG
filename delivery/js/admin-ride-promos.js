/* ETEL Admin - Promo codes page: RIDE promo codes card. Anyone can propose a code; it works only after approval here.
   The discount comes off the final fare and Etel pays for it (the driver still gets the full fare).
   Backend: api/lib/ride_promo.php, actions ride_promo_* and admin_ride_promo_* in api/controllers/RideController.php. */
(function () {
'use strict';
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function api(action, body) {
  var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  var apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  return fetch(apiUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
    body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
}
function num(n) { return String(parseFloat(n)); }
function rule(p) {
  var d = p.discount_type === 'percent' ? num(p.value) + '% off' : 'ETB ' + num(p.value) + ' off';
  if (p.max_discount && parseFloat(p.max_discount) > 0) d += ' (most ETB ' + num(p.max_discount) + ')';
  return d;
}
function limits(p) {
  var l = [];
  if (parseFloat(p.min_fare) > 0) l.push('fare from ETB ' + num(p.min_fare));
  l.push((p.max_uses ? p.max_uses + ' uses' : 'unlimited uses'));
  l.push(p.per_rider_limit + ' per rider');
  if (p.expires_at) l.push('until ' + String(p.expires_at).slice(0, 10));
  return l.join(' · ');
}
function who(p) {
  if (!p.created_by) return '-';
  return esc(p.creator_name || ('#' + p.created_by)) + '<div style="font-size:11px;opacity:.7">' + esc(p.created_by_role || '') + ' ' + esc(p.creator_phone || '') + '</div>';
}
var STATUS_COLOR = { pending: '#E0A800', approved: '#2E9E5B', disabled: '#888', rejected: '#C0392B' };
function badge(s) { return '<span style="font-size:11px;font-weight:800;color:' + (STATUS_COLOR[s] || '#888') + '">' + esc(s.toUpperCase()) + '</span>'; }

function card() {
  var panel = document.getElementById('panel-promos'); if (!panel) return null;
  var el = document.getElementById('rpromo-card');
  if (!el) {
    el = document.createElement('div'); el.id = 'rpromo-card';
    el.style.cssText = 'margin-bottom:22px;border:1px solid rgba(212,175,55,.3);border-radius:14px;padding:16px';
    panel.insertBefore(el, panel.firstChild);
  }
  return el;
}
function msg(t, bad) {
  var m = document.getElementById('rpromo-msg'); if (!m) return;
  m.textContent = t || ''; m.style.color = bad ? '#C0392B' : '#2E9E5B';
}

function render(rows) {
  var el = card(); if (!el) return;
  var pending = rows.filter(function (p) { return p.status === 'pending'; });
  var rest = rows.filter(function (p) { return p.status !== 'pending'; });
  var html = '<div style="font-weight:800;font-size:15px;margin-bottom:4px">Ride promo codes</div>' +
    '<div style="font-size:12px;opacity:.8;margin-bottom:12px;line-height:1.5">Anyone signed in can propose a code from the rider app. It does nothing until you approve it here. ' +
    'The discount comes off the <b>final fare</b> and <b>Etel pays for it</b>: the driver still gets the full fare (Etel credits the difference to the driver\'s Etel money balance). A trip never goes below ETB 10 or more than 80% off. A code is used up only when the trip is completed.</div>' +
    '<div style="font-weight:700;margin:10px 0 6px">Waiting for approval (' + pending.length + ')</div>';
  if (!pending.length) html += '<div style="opacity:.7;font-size:13px;margin-bottom:8px">Nothing waiting.</div>';
  else {
    html += '<div style="overflow-x:auto"><table class="data-table" style="width:100%"><thead><tr><th>Code</th><th>Proposed</th><th>By</th><th>You can adjust</th><th></th></tr></thead><tbody>' +
      pending.map(function (p) {
        return '<tr data-id="' + p.id + '"><td><b>' + esc(p.code) + '</b><div style="font-size:11px;opacity:.7">' + esc(p.description || '') + '</div></td>' +
          '<td>' + esc(rule(p)) + '<div style="font-size:11px;opacity:.7">' + esc(limits(p)) + '</div></td><td>' + who(p) + '</td>' +
          '<td style="font-size:12px"><label>Value <input class="filter-sel rp-v" type="number" step="any" min="1" value="' + esc(num(p.value)) + '" style="width:80px"></label> ' +
          '<label>Uses <input class="filter-sel rp-u" type="number" min="1" value="' + esc(p.max_uses || '') + '" style="width:70px"></label></td>' +
          '<td style="white-space:nowrap"><button class="btn btn-primary rp-ok" style="padding:6px 12px">Approve</button> <button class="btn btn-secondary rp-no" style="padding:6px 12px">Reject</button></td></tr>';
      }).join('') + '</tbody></table></div>';
  }
  html += '<div style="font-weight:700;margin:14px 0 6px">Approved, switched off and rejected</div>';
  if (!rest.length) html += '<div style="opacity:.7;font-size:13px">No codes yet.</div>';
  else {
    html += '<div style="overflow-x:auto"><table class="data-table" style="width:100%"><thead><tr><th>Code</th><th>Discount</th><th>By</th><th>Used</th><th>Given</th><th>Status</th><th></th></tr></thead><tbody>' +
      rest.map(function (p) {
        var act = '';
        if (p.status === 'approved') act = '<button class="btn btn-secondary rp-off" data-id="' + p.id + '" style="padding:6px 12px">Switch off</button>';
        if (p.status === 'disabled') act = '<button class="btn btn-secondary rp-on" data-id="' + p.id + '" style="padding:6px 12px">Switch on</button>';
        return '<tr><td><b>' + esc(p.code) + '</b><div style="font-size:11px;opacity:.7">' + esc(p.review_note || p.description || '') + '</div></td>' +
          '<td>' + esc(rule(p)) + '<div style="font-size:11px;opacity:.7">' + esc(limits(p)) + '</div></td><td>' + who(p) + '</td>' +
          '<td>' + (p.used_count || 0) + (p.max_uses ? ' / ' + p.max_uses : '') + '</td><td>ETB ' + (parseFloat(p.total_discount) || 0).toFixed(2) + '</td>' +
          '<td>' + badge(p.status) + '</td><td>' + act + '</td></tr>';
      }).join('') + '</tbody></table></div>';
  }
  html += '<div style="border-top:1px solid rgba(255,255,255,.1);margin-top:14px;padding-top:12px"><div style="font-weight:700;margin-bottom:6px">New ride promo code (works at once)</div>' +
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:10px">' +
    '<label style="font-size:12px">Code<input id="rp-code" class="filter-sel" placeholder="WELCOME20" style="width:100%;text-transform:uppercase"></label>' +
    '<label style="font-size:12px">Type<select id="rp-type" class="filter-sel" style="width:100%"><option value="percent">% off the fare</option><option value="fixed">ETB off the fare</option></select></label>' +
    '<label style="font-size:12px">Value<input id="rp-value" type="number" step="any" min="1" class="filter-sel" placeholder="20" style="width:100%"></label>' +
    '<label style="font-size:12px">Most ETB off (optional)<input id="rp-max" type="number" step="any" min="0" class="filter-sel" style="width:100%"></label>' +
    '<label style="font-size:12px">Fare from ETB (optional)<input id="rp-min" type="number" step="any" min="0" class="filter-sel" style="width:100%"></label>' +
    '<label style="font-size:12px">Total uses (blank = unlimited)<input id="rp-uses" type="number" min="1" class="filter-sel" style="width:100%"></label>' +
    '<label style="font-size:12px">Uses per rider<input id="rp-per" type="number" min="1" value="1" class="filter-sel" style="width:100%"></label>' +
    '<label style="font-size:12px">Expires<input id="rp-exp" type="datetime-local" class="filter-sel" style="width:100%"></label>' +
    '</div><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><button class="btn btn-primary" id="rp-save" style="padding:8px 16px">Create code</button><span id="rpromo-msg" style="font-size:12px"></span></div></div>';
  el.innerHTML = html;
  bind(el);
}

function bind(el) {
  var i, list;
  list = el.querySelectorAll('.rp-ok');
  for (i = 0; i < list.length; i++) list[i].onclick = function () {
    var tr = this.closest('tr'), id = tr.getAttribute('data-id');
    var body = { id: id, decision: 'approve', value: tr.querySelector('.rp-v').value, max_uses: tr.querySelector('.rp-u').value };
    act(body);
  };
  list = el.querySelectorAll('.rp-no');
  for (i = 0; i < list.length; i++) list[i].onclick = function () {
    var id = this.closest('tr').getAttribute('data-id');
    var note = window.prompt('Why are you rejecting it? (the proposer can see this)', '');
    if (note === null) return;
    act({ id: id, decision: 'reject', note: note });
  };
  list = el.querySelectorAll('.rp-off');
  for (i = 0; i < list.length; i++) list[i].onclick = function () { act({ id: this.getAttribute('data-id'), decision: 'disable' }); };
  list = el.querySelectorAll('.rp-on');
  for (i = 0; i < list.length; i++) list[i].onclick = function () { act({ id: this.getAttribute('data-id'), decision: 'enable' }); };
  var save = document.getElementById('rp-save');
  if (save) save.onclick = create;
}

function act(body) {
  api('admin_ride_promo_review', body).then(function (r) {
    if (r && r.success) load(); else { msg((r && r.message) || 'Could not save', true); window.alert((r && r.message) || 'Could not save'); }
  }).catch(function () { window.alert('Network error'); });
}

function create() {
  var g = function (id) { var e = document.getElementById(id); return e ? e.value : ''; };
  var body = { code: g('rp-code'), discount_type: g('rp-type'), value: g('rp-value'), max_discount: g('rp-max'), min_fare: g('rp-min'),
    max_uses: g('rp-uses'), per_rider_limit: g('rp-per'), expires_at: g('rp-exp') ? g('rp-exp').replace('T', ' ') : '' };
  api('admin_ride_promo_save', body).then(function (r) {
    if (r && r.success) load();
    else msg((r && r.message) || 'Could not create the code', true);
  }).catch(function () { msg('Network error', true); });
}

function load() {
  var el = card(); if (!el) return;
  api('admin_ride_promo_list', {}).then(function (r) {
    if (!r || !r.success) { el.innerHTML = '<div style="color:#C0392B">' + esc((r && r.message) || 'Could not load ride promo codes') + '</div>'; return; }
    render((r.data && r.data.promos) || []);
  }).catch(function () { el.innerHTML = '<div style="color:#C0392B">Network error</div>'; });
}

// the Promo codes page calls loadPromos() each time it is opened: load the ride codes with it
var prev = window.loadPromos;
window.loadPromos = function () {
  load();
  if (typeof prev === 'function') return prev.apply(this, arguments);
};
})();
