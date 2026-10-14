/* ETEL Admin - Money page: refunds and courier cash.
   Refunds: cancelled orders the customer had already paid for, still to be refunded; record how the money was returned.
   Courier cash: cash couriers collected on delivered cash orders. They keep their own pay and tips from it, so what they owe the
   company = collected - own pay and tips - handed over.
   Data: api actions admin_refunds_report, admin_refund_mark, admin_courier_cash_report, admin_cash_remit (api/lib/money_ledger.php).
   Standalone on purpose: it adds its own menu item and page, so no other admin file changes. */
(function () {
'use strict';
var tab = 'refunds';
var METHODS = [['telebirr', 'Telebirr'], ['wallet', 'Etel Pay wallet'], ['chapa', 'Chapa'], ['abysinia', 'Abyssinia bank'], ['cbe', 'CBE bank'], ['cash', 'Cash'], ['other', 'Other']];
var METHOD_LABEL = {}; METHODS.forEach(function (m) { METHOD_LABEL[m[0]] = m[1]; });

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function api(action, body) {
  var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  var url = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token }, body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
}
function etb(n) { return 'ETB ' + (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function when(s) { s = String(s || ''); return s.length >= 16 ? s.slice(5, 10).replace('-', '/') + ' ' + s.slice(11, 16) : (s || '—'); }
function panel() { return document.getElementById('panel-money'); }
function note(msg, bad) { var n = document.getElementById('money-note'); if (n) { n.textContent = msg; n.style.color = bad ? '#e74c3c' : '#2ecc71'; } }

var FIELD = 'padding:7px 8px;border-radius:8px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;font-size:13px';
var BTN = 'padding:7px 14px;border-radius:8px;border:0;background:#D4AF37;color:#1A1200;font-weight:700;font-size:12.5px;cursor:pointer';
var BTN2 = 'padding:7px 12px;border-radius:8px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;font-size:12.5px;cursor:pointer';

function kpi(label, value, sub, bad) {
  return '<div style="flex:1;min-width:130px;border:1px solid rgba(128,128,128,.3);border-radius:12px;padding:10px 12px">' +
    '<div style="font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:.04em">' + esc(label) + '</div>' +
    '<div style="font-size:22px;font-weight:800;' + (bad ? 'color:#e74c3c' : '') + '">' + value + '</div>' +
    '<div style="font-size:11px;opacity:.65">' + esc(sub) + '</div></div>';
}
function tabs() {
  return '<div class="pp-range" style="margin-bottom:12px">' + [['refunds', 'Refunds'], ['cash', 'Courier cash']].map(function (t) {
    return '<button type="button" data-mtab="' + t[0] + '"' + (t[0] === tab ? ' class="active"' : '') + '>' + t[1] + '</button>';
  }).join('') + '</div><div id="money-note" role="status" style="font-size:12.5px;min-height:18px;margin-bottom:6px"></div>';
}
function head(title, sub) { return '<div style="font-size:17px;font-weight:800">' + esc(title) + '</div><div style="font-size:12px;opacity:.65;margin-bottom:10px">' + esc(sub) + '</div>'; }

/* ---------- refunds ---------- */
function refundForm(o) {
  var opts = METHODS.map(function (m) { return '<option value="' + m[0] + '"' + (m[0] === o.method ? ' selected' : '') + '>' + esc(m[1]) + '</option>'; }).join('');
  return '<form data-refund="' + o.order_id + '" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px">' +
    '<select name="method" aria-label="How was it returned" style="' + FIELD + '">' + opts + '</select>' +
    '<input name="reference" aria-label="Transaction reference" placeholder="Reference (optional)" maxlength="80" style="' + FIELD + ';width:150px">' +
    '<input name="amount" type="number" step="0.01" min="0.01" max="' + Number(o.amount) + '" aria-label="Amount returned" placeholder="' + Number(o.amount) + '" style="' + FIELD + ';width:110px">' +
    '<input name="note" aria-label="Note" placeholder="Note (optional)" maxlength="255" style="' + FIELD + ';flex:1;min-width:120px">' +
    '<button type="submit" style="' + BTN + '">Record refund</button><button type="button" data-cancelform style="' + BTN2 + '">Cancel</button></form>';
}
function renderRefunds(d) {
  var h = head('Refunds', 'Orders cancelled after the customer paid. Customers pay outside the platform, so return the money there, then record it here.');
  h += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">' +
    kpi('To refund', Number(d.owed_count), etb(d.owed_total), Number(d.owed_count) > 0) +
    kpi('Refunded', Number(d.refunded_count), etb(d.refunded_total) + ' in the last ' + d.days + ' days') + '</div>';
  h += '<div style="font-weight:700;margin-bottom:6px">Waiting for a refund</div>';
  if (!(d.owed || []).length) h += '<div style="padding:14px;border:1px dashed rgba(128,128,128,.4);border-radius:12px;opacity:.8;margin-bottom:14px">Nothing waiting. Every paid order that was cancelled has been refunded.</div>';
  (d.owed || []).forEach(function (o) {
    h += '<div style="border:1px solid #e74c3c;border-radius:10px;padding:8px 10px;margin-bottom:8px" data-owed="' + o.order_id + '">' +
      '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:13px"><span><b>' + esc(o.order_code) + '</b> · ' + esc(o.customer || 'Customer') + (o.customer_phone ? ' · ' + esc(o.customer_phone) : '') + '</span><b>' + etb(o.amount) + '</b></div>' +
      '<div style="font-size:12px;opacity:.7;margin-top:2px">Paid by ' + esc(METHOD_LABEL[o.method] || o.method) + ' · ordered ' + esc(when(o.placed_at)) + '</div>' +
      '<div data-formhost="' + o.order_id + '"><button type="button" data-open="' + o.order_id + '" style="' + BTN2 + ';margin-top:8px">Record refund…</button></div></div>';
  });
  h += '<div style="font-weight:700;margin:14px 0 6px">Refunded</div>';
  if (!(d.refunded || []).length) h += '<div style="font-size:12px;opacity:.65">No refunds recorded in the last ' + d.days + ' days.</div>';
  (d.refunded || []).forEach(function (r) {
    h += '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;padding:6px 0;border-bottom:1px solid rgba(128,128,128,.2);font-size:13px"><span><b>' + esc(r.order_code || ('#' + r.order_id)) + '</b> · ' + esc(METHOD_LABEL[r.method] || r.method) + (r.reference ? ' · ' + esc(r.reference) : '') + (r.note ? ' · <i>' + esc(r.note) + '</i>' : '') + '</span><span>' + etb(r.amount) + ' <span style="opacity:.6;font-size:11px">' + esc(when(r.created_at)) + '</span></span></div>';
  });
  return h;
}
function wireRefunds(el, d) {
  var byId = {}; (d.owed || []).forEach(function (o) { byId[o.order_id] = o; });
  Array.prototype.forEach.call(el.querySelectorAll('[data-open]'), function (b) {
    b.onclick = function () {
      var id = b.getAttribute('data-open'), host = el.querySelector('[data-formhost="' + id + '"]');
      host.innerHTML = refundForm(byId[id]);
      var f = host.querySelector('form');
      host.querySelector('[data-cancelform]').onclick = function () { load(); };
      f.onsubmit = function (e) {
        e.preventDefault();
        var fd = new FormData(f);
        api('admin_refund_mark', { order_id: Number(id), method: fd.get('method'), reference: fd.get('reference'), note: fd.get('note'), amount: fd.get('amount') || null }).then(function (r) {
          if (r && r.success) { load(function () { note('Refund recorded: ' + etb(r.data.amount), false); }); } else note((r && r.message) || 'Could not record the refund', true);
        }).catch(function (x) { note(x.message, true); });
      };
    };
  });
}

/* ---------- courier cash ---------- */
function renderCash(d) {
  var h = head('Courier cash', 'Couriers keep their own pay and tips from the cash they collect. They owe the company the rest.');
  h += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">' +
    kpi('Couriers owe the company', etb(d.holding_total), 'cash collected minus their own pay, not yet handed over', Number(d.holding_total) > 0) +
    kpi('Couriers who owe cash', Number(d.holding_couriers), 'with a balance above zero') + '</div>';
  if (!(d.couriers || []).length) return h + '<div style="padding:14px;border:1px dashed rgba(128,128,128,.4);border-radius:12px;opacity:.8">No delivered cash orders yet.</div>';
  (d.couriers || []).forEach(function (c) {
    var holding = Number(c.holding);
    h += '<div style="border:1px solid ' + (holding > 0 ? '#e74c3c' : 'rgba(128,128,128,.3)') + ';border-radius:10px;padding:8px 10px;margin-bottom:8px">' +
      '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:13px"><span><b>' + esc(c.name || 'Courier') + '</b> · ' + esc(c.courier_code || '') + (c.phone ? ' · ' + esc(c.phone) : '') + '</span><b style="' + (holding > 0 ? 'color:#e74c3c' : '') + '">' + (holding < 0 ? 'Company owes courier ' + etb(-holding) : 'Owes ' + etb(holding)) + '</b></div>' +
      '<div style="font-size:12px;opacity:.75;margin-top:3px">' + Number(c.cash_orders) + ' cash orders · collected ' + etb(c.collected) + ' − own pay and tips ' + etb(c.own_earnings) + ' − handed over ' + etb(c.handed_over) + '</div>' +
      '<div style="font-size:12px;opacity:.65;margin-top:2px">Last cash delivery ' + esc(when(c.last_cash_delivery)) + ' · last handover ' + esc(when(c.last_handover)) + '</div>' +
      (holding > 0 ? '<div data-cashhost="' + c.courier_id + '"><button type="button" data-cash="' + c.courier_id + '" style="' + BTN2 + ';margin-top:8px">Record handover…</button></div>' : '') + '</div>';
  });
  return h;
}
function wireCash(el, d) {
  var byId = {}; (d.couriers || []).forEach(function (c) { byId[c.courier_id] = c; });
  Array.prototype.forEach.call(el.querySelectorAll('[data-cash]'), function (b) {
    b.onclick = function () {
      var id = b.getAttribute('data-cash'), c = byId[id], host = el.querySelector('[data-cashhost="' + id + '"]');
      host.innerHTML = '<form style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px">' +
        '<input name="amount" type="number" step="0.01" min="0.01" max="' + Number(c.holding) + '" required aria-label="Cash received" value="' + Number(c.holding) + '" style="' + FIELD + ';width:130px">' +
        '<input name="note" aria-label="Note" placeholder="Note (optional)" maxlength="255" style="' + FIELD + ';flex:1;min-width:120px">' +
        '<button type="submit" style="' + BTN + '">Record handover</button><button type="button" data-cancelform style="' + BTN2 + '">Cancel</button></form>';
      host.querySelector('[data-cancelform]').onclick = function () { load(); };
      var f = host.querySelector('form');
      f.onsubmit = function (e) {
        e.preventDefault();
        var fd = new FormData(f);
        api('admin_cash_remit', { courier_id: Number(id), amount: fd.get('amount'), note: fd.get('note') }).then(function (r) {
          if (r && r.success) { load(function () { note('Handover recorded: ' + etb(r.data.amount) + ' · still owes ' + etb(r.data.holding_after), false); }); } else note((r && r.message) || 'Could not record the handover', true);
        }).catch(function (x) { note(x.message, true); });
      };
    };
  });
}

/* ---------- page ---------- */
function load(done) {
  var el = panel(); if (!el) return;
  el.innerHTML = tabs() + '<div style="padding:14px;opacity:.7">Loading…</div>'; wireTabs(el);
  var action = tab === 'refunds' ? 'admin_refunds_report' : 'admin_courier_cash_report';
  api(action, tab === 'refunds' ? { days: 30 } : {}).then(function (r) {
    if (!(r && r.success)) { el.innerHTML = tabs() + '<div style="padding:14px;color:#e74c3c">Money: ' + esc((r && r.message) || 'could not load') + '</div>'; wireTabs(el); return; }
    el.innerHTML = tabs() + (tab === 'refunds' ? renderRefunds(r.data) : renderCash(r.data));
    wireTabs(el); if (tab === 'refunds') wireRefunds(el, r.data); else wireCash(el, r.data);
    if (typeof done === 'function') done();
  }).catch(function (e) { el.innerHTML = tabs() + '<div style="padding:14px;color:#e74c3c">Money: ' + esc(e.message) + '</div>'; wireTabs(el); });
}
function wireTabs(el) {
  Array.prototype.forEach.call(el.querySelectorAll('[data-mtab]'), function (b) { b.onclick = function () { tab = b.getAttribute('data-mtab'); load(); }; });
}

function install() {
  if (document.querySelector('[data-page="money"]')) return;
  var after = document.querySelector('.nav-item[data-page="ratings"]') || document.querySelector('.nav-item[data-page="orderchats"]') || document.querySelector('.nav-item[data-page="support"]');
  if (after) {
    var nav = document.createElement('div');
    nav.className = 'nav-item'; nav.setAttribute('data-page', 'money');
    nav.setAttribute('onclick', "switchPage('money',this)");
    nav.innerHTML = '<span class="nav-icon">💰</span><span>Money</span>';
    after.parentNode.insertBefore(nav, after.nextSibling);
  }
  var host = document.getElementById('panel-ratings') || document.getElementById('panel-orderchats') || document.getElementById('panel-support');
  if (host && !panel()) {
    var p = document.createElement('div'); p.className = 'panel'; p.id = 'panel-money'; p.style.display = 'none';
    host.parentNode.appendChild(p);
  }
  var orig = window.switchPage;
  if (typeof orig === 'function') {
    window.switchPage = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'money') {
        var t = document.getElementById('page-title'); if (t) t.textContent = 'Money: Refunds & Courier Cash';
        var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
        load();
      }
      return r;
    };
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
