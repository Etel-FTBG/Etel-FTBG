/* ETEL Admin - Customers: who orders, how often and how much. Read only.
   Data: api actions admin_customers_list, admin_customer_get (api/controllers/CustomerAdminController.php).
   Standalone on purpose: it adds its own menu item and page, so no other admin file changes. */
(function () {
'use strict';
var DATA = [], SORT = 'recent', QUERY = '', TIMER = null;
var BTN2 = 'padding:7px 12px;border-radius:8px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;font-size:12.5px;cursor:pointer';
var FIELD = 'padding:8px 10px;border-radius:8px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;font-size:13px;box-sizing:border-box';
var TILE = 'flex:1 1 150px;border:1px solid rgba(128,128,128,.3);border-radius:12px;padding:10px 14px';
var TH = 'text-align:left;padding:8px;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;opacity:.7;white-space:nowrap';
var TD = 'padding:8px;border-top:1px solid rgba(128,128,128,.2);font-size:13px;vertical-align:top';

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function money(n) { return 'ETB ' + (Number(n) || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
function day(s) { return s ? String(s).slice(0, 10) : '—'; }
function api(action, body) {
  var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  var url = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token }, body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
}
function panel() { return document.getElementById('panel-customers'); }
function failText(res, dflt) { return (res && (res.message || res.error)) || dflt; }

function rows() {
  if (!DATA.length) return '<tr><td colspan="6" style="padding:24px;text-align:center;opacity:.7">No customers found</td></tr>';
  return DATA.map(function (c) {
    return '<tr data-id="' + c.id + '" style="cursor:pointer"><td style="' + TD + '"><strong>' + esc(c.name) + '</strong>' + (c.suspended ? ' <span style="color:#e74c3c;font-size:11px;font-weight:700">SUSPENDED</span>' : '') + '</td><td style="' + TD + '">' + esc(c.phone) + '</td>' +
      '<td style="' + TD + '">' + c.orders_total + (c.cancelled ? ' <span style="opacity:.6">(' + c.cancelled + ' cancelled)</span>' : '') + '</td>' +
      '<td style="' + TD + '">' + money(c.spent) + '</td><td style="' + TD + '">' + day(c.last_order_at) + '</td><td style="' + TD + '">' + day(c.created_at) + '</td></tr>';
  }).join('');
}

function shell(summary) {
  var s = summary || {};
  return '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">' +
    '<div style="' + TILE + '"><div style="font-size:12px;opacity:.7">Customers</div><div style="font-size:22px;font-weight:800">' + (s.total || 0) + '</div></div>' +
    '<div style="' + TILE + '"><div style="font-size:12px;opacity:.7">Ordered in the last 30 days</div><div style="font-size:22px;font-weight:800">' + (s.active_30d || 0) + '</div></div>' +
    '<div style="' + TILE + '"><div style="font-size:12px;opacity:.7">New in the last 7 days</div><div style="font-size:22px;font-weight:800">' + (s.new_7d || 0) + '</div></div></div>' +
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">' +
    '<input data-q type="search" placeholder="Search name or phone…" value="' + esc(QUERY) + '" style="' + FIELD + ';width:240px" aria-label="Search customers">' +
    '<select data-sort style="' + FIELD + '" aria-label="Sort customers">' +
    [['recent', 'Latest order'], ['orders', 'Most orders'], ['spent', 'Spent most'], ['name', 'Name A–Z']].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === SORT ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') +
    '</select><button type="button" data-refresh style="' + BTN2 + '">🔄 Refresh</button></div>' +
    '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr>' +
    ['Name', 'Phone', 'Orders', 'Spent (delivered)', 'Last order', 'Joined'].map(function (h) { return '<th style="' + TH + '">' + h + '</th>'; }).join('') +
    '</tr></thead><tbody data-body>' + rows() + '</tbody></table></div><div data-detail></div>';
}

function wire(el) {
  var q = el.querySelector('[data-q]');
  q.addEventListener('input', function () { QUERY = q.value; clearTimeout(TIMER); TIMER = setTimeout(function () { load(true); }, 300); });
  el.querySelector('[data-sort]').addEventListener('change', function (e) { SORT = e.target.value; load(true); });
  el.querySelector('[data-refresh]').addEventListener('click', function () { load(true); });
  el.querySelector('[data-body]').addEventListener('click', function (e) {
    var tr = e.target.closest('tr[data-id]'); if (tr) openDetail(el, tr.getAttribute('data-id'));
  });
}

function openDetail(el, id) {
  var host = el.querySelector('[data-detail]'); if (!host) return;
  host.innerHTML = '<div style="padding:14px;opacity:.7">Loading…</div>';
  api('admin_customer_get', { id: id }).then(function (res) {
    if (!res || res.success === false || !res.data) { host.innerHTML = '<div style="padding:14px;color:#e74c3c">' + esc(failText(res, 'Could not load this customer.')) + '</div>'; return; }
    var c = res.data.customer, o = res.data.orders || [];
    var h = '<div style="border:1px solid #D4AF37;border-radius:12px;padding:14px;margin-top:16px"><div style="display:flex;justify-content:space-between;gap:8px;align-items:center">' +
      '<div style="font-size:16px;font-weight:800">' + esc(c.name) + ' <span style="font-weight:400;opacity:.75">· ' + esc(c.phone) + '</span>' + (c.suspended ? ' <span style="color:#e74c3c;font-size:12px">· suspended from ordering</span>' : '') + '</div>' +
      '<div style="display:flex;gap:8px"><button type="button" data-suspend style="' + BTN2 + '">' + (c.suspended ? 'Restore ordering' : 'Suspend ordering') + '</button><button type="button" data-close style="' + BTN2 + '">Close</button></div></div>' +
      '<div style="font-size:13px;margin:6px 0 10px;opacity:.85">' + c.orders_total + ' orders · ' + c.delivered + ' delivered · ' + c.cancelled + ' cancelled · ' + money(c.spent) + ' spent · joined ' + day(c.created_at) + '</div>';
    h += o.length ? '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr>' +
      ['Code', 'Type', 'Status', 'Payment', 'Total', 'Placed'].map(function (x) { return '<th style="' + TH + '">' + x + '</th>'; }).join('') + '</tr></thead><tbody>' +
      o.map(function (r) {
        return '<tr><td style="' + TD + '">' + esc(r.order_code) + '</td><td style="' + TD + '">' + esc(r.kind === 'package' ? 'Package' : 'Order') + '</td><td style="' + TD + '">' + esc(r.status) + '</td>' +
          '<td style="' + TD + '">' + esc(r.payment_method) + '</td><td style="' + TD + '">' + money(r.fare_total) + '</td><td style="' + TD + '">' + esc(String(r.placed_at || '').slice(0, 16)) + '</td></tr>';
      }).join('') + '</tbody></table></div>' : '<div style="opacity:.7">No orders yet.</div>';
    host.innerHTML = h + '</div>';
    host.querySelector('[data-suspend]').addEventListener('click', function () {
      var on = !c.suspended, reason = '';
      if (on) { reason = window.prompt('Suspend ' + c.name + ' from ordering? They can still sign in. Reason (optional):'); if (reason === null) return; }
      else if (!window.confirm('Let ' + c.name + ' order again?')) return;
      api('admin_customer_set_active', { id: c.id, active: on ? 0 : 1, reason: reason }).then(function (r) {
        if (!r || r.success === false) { window.alert(failText(r, 'Could not update.')); return; }
        load(true); openDetail(el, c.id);
      });
    });
    host.querySelector('[data-close]').addEventListener('click', function () { host.innerHTML = ''; });
    host.scrollIntoView({ block: 'nearest' });
  }).catch(function () { host.innerHTML = '<div style="padding:14px;color:#e74c3c">Could not reach the server. Try again.</div>'; });
}

function load(keep) {
  var el = panel(); if (!el) return;
  if (!keep) el.innerHTML = '<div style="padding:14px;opacity:.7">Loading…</div>';
  api('admin_customers_list', { q: QUERY, sort: SORT }).then(function (res) {
    if (!res || res.success === false || !res.data) { el.innerHTML = '<div style="padding:14px;color:#e74c3c">' + esc(failText(res, 'Could not load.')) + '</div>'; return; }
    DATA = res.data.customers || [];
    if (keep && el.querySelector('[data-body]')) { el.querySelector('[data-body]').innerHTML = rows(); return; }
    el.innerHTML = shell(res.data.summary); wire(el);
  }).catch(function (e) { el.innerHTML = '<div style="padding:14px;color:#e74c3c">Customers: ' + esc(e.message) + '</div>'; });
}

function install() {
  if (document.querySelector('[data-page="customers"]')) return;
  var after = document.querySelector('.nav-item[data-page="users"]') || document.querySelector('.nav-item[data-page="orders"]');
  if (after) {
    var nav = document.createElement('div');
    nav.className = 'nav-item'; nav.setAttribute('data-page', 'customers');
    nav.setAttribute('onclick', "switchPage('customers',this)");
    nav.innerHTML = '<span class="nav-icon">🛍️</span><span>Customers</span>';
    after.parentNode.insertBefore(nav, after.nextSibling);
  }
  var host = document.getElementById('panel-users') || document.getElementById('panel-orders');
  if (host && !panel()) {
    var p = document.createElement('div'); p.className = 'panel'; p.id = 'panel-customers'; p.style.display = 'none';
    host.parentNode.appendChild(p);
  }
  var orig = window.switchPage;
  if (typeof orig === 'function') {
    window.switchPage = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'customers') {
        var t = document.getElementById('page-title'); if (t) t.textContent = 'Customers';
        var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
        load();
      }
      return r;
    };
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
