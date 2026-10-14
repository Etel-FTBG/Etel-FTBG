/* ETEL Admin - Dispatch page: orders that need staff, and assigning a courier by hand.
   Shows live orders (last 2 days) that need attention first: no courier, courier has not accepted, accepted but not picked up, slow.
   Staff can assign or change the courier, or take the order back and offer it to the next free courier.
   Data: api actions admin_dispatch_board, admin_assign_courier, admin_take_back_order (api/lib/manual_dispatch.php).
   Standalone on purpose: it adds its own menu item and page, so no other admin file changes. */
(function () {
'use strict';
var timer = null;
var NEED = {
  no_courier: ['No courier yet', '#e74c3c'],
  waiting_accept: ['Courier has not accepted', '#e67e22'],
  not_picked_up: ['Accepted but not picked up', '#e67e22'],
  slow: ['On the way for a long time', '#e67e22']
};
var REASONS = [['customer_request', 'Customer asked us to'], ['shop_problem', 'Problem at the shop'], ['no_courier', 'No courier available'], ['fraud', 'Suspected fraud'], ['test_order', 'Test order'], ['other', 'Another reason']];
var STATUS = { pending: 'Waiting', assigned: 'Courier assigned', picked_up: 'Picked up', in_transit: 'On the way' };

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function api(action, body) {
  var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  var url = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token }, body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
}
function panel() { return document.getElementById('panel-dispatch'); }
function age(m) { m = Number(m) || 0; return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min'; }
function note(msg, bad) { var n = document.getElementById('dispatch-note'); if (n) { n.textContent = msg; n.style.color = bad ? '#e74c3c' : '#2ecc71'; } }
var BTN = 'padding:7px 14px;border-radius:8px;border:0;background:#D4AF37;color:#1A1200;font-weight:700;font-size:12.5px;cursor:pointer';
var BTN_DANGER = 'padding:7px 14px;border-radius:8px;border:0;background:#c0392b;color:#fff;font-weight:700;font-size:12.5px;cursor:pointer';
var BTN2 = 'padding:7px 12px;border-radius:8px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;font-size:12.5px;cursor:pointer';
var FIELD = 'padding:7px 8px;border-radius:8px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;font-size:13px;min-width:200px;max-width:100%';

function courierOptions(d, current) {
  return (d.couriers || []).filter(function (c) { return c.courier_id !== current; }).map(function (c) {
    var seen = c.seen_min == null ? '' : (c.seen_min < 2 ? ', live' : ', seen ' + age(c.seen_min) + ' ago');
    return '<option value="' + c.courier_id + '">' + esc(c.name || 'Courier') + ' (' + esc(c.courier_code || '') + ') · ' + esc(c.status) + ' · ' + c.live_jobs + (c.live_jobs === 1 ? ' job' : ' jobs') + esc(seen) + '</option>';
  }).join('');
}

function card(o, d) {
  var need = NEED[o.need];
  var h = '<div style="border:1px solid ' + (need ? need[1] : 'rgba(128,128,128,.3)') + ';border-radius:10px;padding:8px 10px;margin-bottom:8px" data-order="' + o.order_id + '">' +
    '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:13px"><span><b>' + esc(o.order_code) + '</b> · ' + esc(STATUS[o.status] || o.status) + (o.shop ? ' · ' + esc(o.shop) : '') + '</span><span style="opacity:.75">' + age(o.age_min) + ' ago · ETB ' + Number(o.fare_total).toFixed(0) + '</span></div>' +
    '<div style="font-size:12px;opacity:.75;margin-top:2px">' + esc(o.from_zone || '?') + ' → ' + esc(o.to_zone || '?') + ' · ' + (o.courier ? 'Courier: <b>' + esc(o.courier) + '</b> ' + esc(o.courier_code || '') + (o.accepted ? ' (accepted)' : ' (not accepted)') : 'No courier') + '</div>' +
    (need ? '<div style="font-size:12px;font-weight:700;margin-top:3px;color:' + need[1] + '">' + esc(need[0]) + '</div>' : '') +
    (o.awaiting_payment ? '<div style="font-size:12px;opacity:.7;margin-top:3px">Waiting for the customer to pay</div>' : '');
  h += '<div data-host="' + o.order_id + '" style="margin-top:8px">' +
    (o.can_assign ? '<button type="button" data-assign="' + o.order_id + '" style="' + BTN2 + '">' + (o.courier_id ? 'Change courier…' : 'Assign courier…') + '</button>' : '') +
    (o.can_assign && o.status === 'assigned' && o.courier_id ? ' <button type="button" data-takeback="' + o.order_id + '" style="' + BTN2 + '">Take back</button>' : '') +
    ' <button type="button" data-cancel="' + o.order_id + '" style="' + BTN2 + ';color:#c0392b">Cancel order…</button></div>';
  return h + '</div>';
}

function render(d) {
  var el = panel(); if (!el) return;
  var needs = (d.orders || []).filter(function (o) { return o.need; });
  var rest = (d.orders || []).filter(function (o) { return !o.need; });
  var h = '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:6px"><div><div style="font-size:17px;font-weight:800">Dispatch</div>' +
    '<div style="font-size:12px;opacity:.65">Live orders from the last 2 days. Auto-dispatch offers each order to the nearest free courier; use this when nobody takes it.</div></div>' +
    '<button type="button" data-refresh style="' + BTN2 + '">Refresh</button></div><div id="dispatch-note" role="status" style="font-size:12.5px;min-height:18px;margin-bottom:6px"></div>';
  h += '<div style="font-weight:700;margin:6px 0">Needs you (' + needs.length + ')</div>';
  if (!needs.length) h += '<div style="padding:14px;border:1px dashed rgba(128,128,128,.4);border-radius:12px;opacity:.8;margin-bottom:14px">Nothing needs you right now. Every live order has a courier who is moving it.</div>';
  needs.forEach(function (o) { h += card(o, d); });
  h += '<div style="font-weight:700;margin:14px 0 6px">Moving normally (' + rest.length + ')</div>';
  if (!rest.length) h += '<div style="font-size:12px;opacity:.65">No other live orders.</div>';
  rest.forEach(function (o) { h += card(o, d); });
  el.innerHTML = h; wire(el, d);
}

function wire(el, d) {
  var refresh = el.querySelector('[data-refresh]'); if (refresh) refresh.onclick = function () { load(); };
  var byId = {}; (d.orders || []).forEach(function (o) { byId[o.order_id] = o; });
  Array.prototype.forEach.call(el.querySelectorAll('[data-assign]'), function (b) {
    b.onclick = function () {
      var id = Number(b.getAttribute('data-assign')), o = byId[id], host = el.querySelector('[data-host="' + id + '"]');
      var opts = courierOptions(d, o.courier_id);
      if (!opts) { note('No other active courier to choose from.', true); return; }
      host.innerHTML = '<form style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><select name="courier" aria-label="Courier" style="' + FIELD + '">' + opts + '</select>' +
        '<button type="submit" style="' + BTN + '">Assign</button><button type="button" data-cancelform style="' + BTN2 + '">Cancel</button></form>';
      host.querySelector('[data-cancelform]').onclick = function () { load(); };
      host.querySelector('form').onsubmit = function (e) {
        e.preventDefault();
        var cid = Number(host.querySelector('select').value);
        api('admin_assign_courier', { order_id: id, courier_id: cid }).then(function (r) {
          if (r && r.success) load(function () { note(r.data.message + ' (' + o.order_code + ')', false); }); else note((r && r.message) || 'Could not assign', true);
        }).catch(function (x) { note(x.message, true); });
      };
    };
  });
  Array.prototype.forEach.call(el.querySelectorAll('[data-cancel]'), function (b) {
    b.onclick = function () {
      var id = Number(b.getAttribute('data-cancel')), o = byId[id], host = el.querySelector('[data-host="' + id + '"]');
      host.innerHTML = '<form style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><select name="reason" required aria-label="Reason for cancelling" style="' + FIELD + '"><option value="">Choose a reason…</option>' +
        REASONS.map(function (r) { return '<option value="' + r[0] + '">' + esc(r[1]) + '</option>'; }).join('') + '</select>' +
        '<input name="note" maxlength="200" aria-label="Note (optional)" placeholder="Note (optional)" style="' + FIELD + ';flex:1;min-width:140px">' +
        '<button type="submit" style="' + BTN_DANGER + '">Cancel this order</button><button type="button" data-cancelform style="' + BTN2 + '">Keep it</button></form>';
      host.querySelector('[data-cancelform]').onclick = function () { load(); };
      host.querySelector('form').onsubmit = function (e) {
        e.preventDefault();
        var fd = new FormData(host.querySelector('form'));
        if (!fd.get('reason')) { note('Choose a reason first.', true); return; }
        if ((o.status === 'picked_up' || o.status === 'in_transit') && !window.confirm(o.order_code + ' is already on its way. Cancel it anyway?')) return;
        api('admin_cancel_order', { order_id: id, reason: fd.get('reason'), note: fd.get('note') }).then(function (r) {
          if (r && r.success) load(function () { note(r.data.message + ' (' + o.order_code + ')', false); }); else note((r && r.message) || 'Could not cancel', true);
        }).catch(function (x) { note(x.message, true); });
      };
    };
  });
  Array.prototype.forEach.call(el.querySelectorAll('[data-takeback]'), function (b) {
    b.onclick = function () {
      var id = Number(b.getAttribute('data-takeback')), o = byId[id];
      if (!window.confirm('Take ' + o.order_code + ' back from ' + (o.courier || 'the courier') + ' and offer it to another courier?')) return;
      api('admin_take_back_order', { order_id: id }).then(function (r) {
        if (r && r.success) load(function () { note(r.data.message + ' (' + o.order_code + ')', false); }); else note((r && r.message) || 'Could not take it back', true);
      }).catch(function (x) { note(x.message, true); });
    };
  });
}

function load(done) {
  var el = panel(); if (!el) return;
  if (!el.firstChild) el.innerHTML = '<div style="padding:14px;opacity:.7">Loading…</div>';
  api('admin_dispatch_board', {}).then(function (r) {
    if (r && r.success) { render(r.data); if (typeof done === 'function') done(); }
    else el.innerHTML = '<div style="padding:14px;color:#e74c3c">Dispatch: ' + esc((r && r.message) || 'could not load') + '</div>';
  }).catch(function (e) { el.innerHTML = '<div style="padding:14px;color:#e74c3c">Dispatch: ' + esc(e.message) + '</div>'; });
}
function visible() { var p = panel(); return !!(p && p.offsetParent !== null); }

function install() {
  if (document.querySelector('[data-page="dispatch"]')) return;
  var after = document.querySelector('.nav-item[data-page="orders"]') || document.querySelector('.nav-item[data-page="dashboard"]');
  if (after) {
    var nav = document.createElement('div');
    nav.className = 'nav-item'; nav.setAttribute('data-page', 'dispatch');
    nav.setAttribute('onclick', "switchPage('dispatch',this)");
    nav.innerHTML = '<span class="nav-icon">🚦</span><span>Dispatch</span>';
    after.parentNode.insertBefore(nav, after.nextSibling);
  }
  var host = document.getElementById('panel-orders') || document.getElementById('panel-orderchats') || document.getElementById('panel-support');
  if (host && !panel()) {
    var p = document.createElement('div'); p.className = 'panel'; p.id = 'panel-dispatch'; p.style.display = 'none';
    host.parentNode.appendChild(p);
  }
  var orig = window.switchPage;
  if (typeof orig === 'function') {
    window.switchPage = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'dispatch') {
        var t = document.getElementById('page-title'); if (t) t.textContent = 'Dispatch';
        var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
        load();
        if (!timer) timer = setInterval(function () { if (visible() && document.visibilityState === 'visible' && !document.querySelector('#panel-dispatch form')) load(); }, 30000);
      }
      return r;
    };
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
