/* ETEL Courier - works through a dropped connection.
   What it does, and what it deliberately does not:
   - The list of the courier's orders is kept on the phone each time it loads. With no signal the app shows that saved list,
     marked "saved at HH:MM", instead of "Could not load".
   - Status changes (picked up / in transit / delivered) and fuel fill-ups made with no signal are saved on the phone and sent,
     in the order they were made, as soon as the connection is back. The real time of the action is sent with it, so a delivery
     made in a dead zone is not stamped with the time the signal returned.
   - A saved action the server refuses (order cancelled, reassigned...) is NOT dropped quietly: it stays in the waiting list
     with the server's reason until the courier discards it.
   - Anything that moves money or needs another person (accepting a job, PIN / QR confirmations, wallet, going online) stays online-only.
   Standalone: wraps window.fetch for the API address, so courier.js and the other courier scripts are not edited. */
(function () {
'use strict';
if (window.__courierOffline || typeof window.fetch !== 'function') return;
window.__courierOffline = true;

var API = (window.ETEL && window.ETEL.api) || 'boot.php';
var KEY_Q = 'etel_courier_outbox_v1', KEY_C = 'etel_courier_orders_v1';
var QUEUE_ACTIONS = { courier_update_order_status: 1, courier_add_fuel_log: 1 };
var origFetch = window.fetch.bind(window);
var memQ = null, memC = {};
var flushing = false, timer = null;

function store(get, k, v) {
  try { if (get) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; }
  return null;
}
function readQ() {
  if (memQ) return memQ;
  try { var a = JSON.parse(store(true, KEY_Q) || '[]'); memQ = Array.isArray(a) ? a : []; } catch (e) { memQ = []; }
  return memQ;
}
function saveQ() { store(false, KEY_Q, JSON.stringify(readQ())); paint(); }
function uid() { return 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); }
function isApi(u) { return typeof u === 'string' && u.indexOf('boot.php') !== -1 && u.split('?')[0].indexOf('boot.php') !== -1; }
function userKey() { try { var u = window.Auth && Auth.getUser && Auth.getUser(); return u && u.id ? String(u.id) : ''; } catch (e) { return ''; } }
function hhmm(t) { var d = new Date(t); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function jsonResponse(obj) { return new Response(JSON.stringify(obj), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
function mine() { var u = userKey(); return readQ().filter(function (e) { return !e.user || e.user === u; }); }

var LABEL = { picked_up: 'Picked up', in_transit: 'In transit', delivered: 'Delivered' };
function describe(e) {
  if (e.action === 'courier_add_fuel_log') return 'Fuel fill-up ' + (e.body.liters || '') + ' L';
  return (LABEL[e.body.status] || e.body.status) + ' · order #' + e.body.order_id;
}

/* ---- saved orders ---- */
function ckey(url) { return userKey() + '|' + url.replace(/^.*[?]/, ''); }
function saveOrders(url, data) {
  var entry = { at: Date.now(), data: data };
  memC[ckey(url)] = entry;
  try {
    var all = JSON.parse(store(true, KEY_C) || '{}'); if (!all || typeof all !== 'object') all = {};
    all[ckey(url)] = entry;
    var keys = Object.keys(all); if (keys.length > 6) keys.sort(function (a, b) { return all[a].at - all[b].at; }).slice(0, keys.length - 6).forEach(function (k) { delete all[k]; });
    store(false, KEY_C, JSON.stringify(all));
  } catch (e) {}
}
function loadOrders(url) {
  if (memC[ckey(url)]) return memC[ckey(url)];
  try { var all = JSON.parse(store(true, KEY_C) || '{}'); return all && all[ckey(url)] || null; } catch (e) { return null; }
}
/* what the saved list would look like once the waiting status changes are applied */
function overlay(data) {
  var pend = mine().filter(function (e) { return e.action === 'courier_update_order_status' && !e.failed; });
  if (!pend.length || !data || !data.orders) return data;
  var out = JSON.parse(JSON.stringify(data));
  out.orders.forEach(function (o) {
    pend.forEach(function (e) { if (String(o.id) === String(e.body.order_id)) o.status = e.body.status; });
  });
  return out;
}

/* ---- banner ---- */
var staleAt = 0;
function css() {
  if (document.getElementById('cof-css')) return;
  var s = document.createElement('style'); s.id = 'cof-css';
  s.textContent = '#cof-bar{position:fixed;left:10px;right:10px;bottom:calc(10px + env(safe-area-inset-bottom,0px));z-index:9998;background:#1b1b2a;color:#f4f1e6;border:1.5px solid #f2c14e;border-radius:14px;padding:10px 12px;font:600 13px/1.4 system-ui,sans-serif;box-shadow:0 8px 28px rgba(0,0,0,.45);display:none}' +
    '#cof-bar.on{display:block}#cof-bar b{color:#f2c14e}#cof-bar .r{display:flex;gap:8px;align-items:center;justify-content:space-between}' +
    '#cof-bar button{font:inherit;border:0;border-radius:9px;padding:7px 11px;cursor:pointer;background:#f2c14e;color:#1b1b2a}#cof-bar button.g{background:transparent;color:#f4f1e6;border:1px solid #6b6b80}' +
    '#cof-bar ul{list-style:none;margin:8px 0 0;padding:0;max-height:34vh;overflow:auto}#cof-bar li{display:flex;gap:8px;justify-content:space-between;align-items:center;padding:6px 0;border-top:1px solid #34344a;font-weight:500}' +
    '#cof-bar li.bad{color:#ff8a98}#cof-bar button:focus-visible{outline:2px solid #fff;outline-offset:2px}';
  document.head.appendChild(s);
}
function paint() {
  if (!document.body) return;
  css();
  var bar = document.getElementById('cof-bar');
  if (!bar) { bar = document.createElement('div'); bar.id = 'cof-bar'; bar.setAttribute('role', 'status'); bar.setAttribute('aria-live', 'polite'); document.body.appendChild(bar); }
  var q = mine(), off = navigator.onLine === false;
  if (!q.length && !off && !staleAt) { bar.className = ''; bar.innerHTML = ''; return; }
  var head = off ? '📡 <b>No connection.</b> ' : '';
  if (staleAt) head += 'Showing saved orders from <b>' + esc(hhmm(staleAt)) + '</b>. ';
  var html = '<div class="r"><span>' + head + (q.length ? q.length + ' waiting to send' : (off ? 'Your work is saved on this phone.' : '')) + '</span>' +
    (q.length ? '<button type="button" data-cof="send">Send now</button>' : '') + '</div>';
  if (q.length) {
    html += '<ul>' + q.map(function (e) {
      return '<li class="' + (e.failed ? 'bad' : '') + '"><span>' + esc(describe(e)) + (e.failed ? ' — ' + esc(e.failed) : ' · ' + esc(hhmm(e.at))) + '</span>' +
        '<button type="button" class="g" data-cof="drop" data-id="' + esc(e.id) + '" aria-label="Discard ' + esc(describe(e)) + '">Discard</button></li>';
    }).join('') + '</ul>';
  }
  bar.innerHTML = html; bar.className = 'on';
}
document.addEventListener('click', function (ev) {
  var b = ev.target && ev.target.closest && ev.target.closest('[data-cof]'); if (!b) return;
  if (b.getAttribute('data-cof') === 'send') flush(true);
  else {
    var id = b.getAttribute('data-id'); memQ = readQ().filter(function (e) { return e.id !== id; }); saveQ();
  }
});

/* ---- sending ---- */
function netDown(e) { return e && (e.name === 'TypeError' || /fetch|network|load failed/i.test(String(e.message))); }
function flush(manual) {
  if (flushing) return Promise.resolve();
  var list = mine().filter(function (e) { return !e.failed; });
  if (!list.length) return Promise.resolve();
  flushing = true;
  var chain = Promise.resolve(), stop = false, sent = 0;
  list.forEach(function (e) {
    chain = chain.then(function () {
      if (stop) return;
      var body = Object.assign({}, e.body, { action: e.action, client_req_id: e.id, occurred_at: new Date(e.at).toISOString() });
      return origFetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (window.Auth && Auth.getToken ? Auth.getToken() : '') }, body: JSON.stringify(body) })
        .then(function (r) { return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (x) {} return { status: r.status, json: j }; }); })
        .then(function (r) {
          if (r.json && r.json.success) { memQ = readQ().filter(function (x) { return x.id !== e.id; }); sent++; return; }
          if (r.status === 401) { stop = true; return; }
          if (r.status >= 500 || !r.json) { stop = true; return; }
          e.failed = (r.json && r.json.message) || 'The server did not accept this';
        }, function () { stop = true; });
    });
  });
  return chain.then(function () {
    flushing = false; saveQ();
    if (sent) {
      if (typeof window.showToast === 'function') window.showToast('✓ ' + sent + ' saved action' + (sent > 1 ? 's' : '') + ' sent');
      if (typeof window.loadActiveOrders === 'function') { try { window.loadActiveOrders(); } catch (x) {} }
    } else if (manual && typeof window.showToast === 'function' && mine().some(function (e) { return !e.failed; })) window.showToast('Still no connection', 'err');
  });
}

/* ---- the fetch wrapper ---- */
window.fetch = function (input, init) {
  var url = typeof input === 'string' ? input : (input && input.url) || '';
  if (!isApi(url)) return origFetch(input, init);
  var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();

  if (method === 'GET') {
    var m = /[?&]action=courier_get_my_orders(&|$)/.exec(url);
    return origFetch(input, init).then(function (r) {
      if (m && r.ok) { staleAt = 0; paint(); r.clone().json().then(function (j) { if (j && j.success) saveOrders(url, j.data); }, function () {}); }
      return r;
    }, function (err) {
      if (!m || !netDown(err)) throw err;
      var c = loadOrders(url); if (!c) throw err;
      staleAt = c.at; paint();
      return jsonResponse({ success: true, offline: true, saved_at: c.at, data: overlay(c.data) });
    });
  }

  var body = null; try { body = JSON.parse((init && init.body) || 'null'); } catch (e) {}
  var action = body && body.action;
  if (!QUEUE_ACTIONS[action]) return origFetch(input, init);
  return origFetch(input, init).catch(function (err) {
    if (!netDown(err)) throw err;
    var clean = Object.assign({}, body); delete clean.action;
    readQ().push({ id: uid(), user: userKey(), action: action, body: clean, at: Date.now() });
    saveQ();
    return jsonResponse({ success: true, queued: true, message: 'Saved on your phone - it will be sent when you are back online', data: { queued: true, status: clean.status } });
  });
};

window.CourierOffline = { flush: function () { return flush(true); }, pending: function () { return mine().length; } };
window.addEventListener('online', function () {
  paint();
  flush(false).then(function () { if (staleAt && typeof window.loadActiveOrders === 'function') { try { window.loadActiveOrders(); } catch (x) {} } });
});
window.addEventListener('offline', paint);
timer = setInterval(function () { if (navigator.onLine !== false && mine().some(function (e) { return !e.failed; })) flush(false); }, 20000);
function start() { paint(); flush(false); }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
