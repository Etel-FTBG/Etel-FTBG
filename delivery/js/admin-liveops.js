/* ETEL Admin - Operations > Live operations. One screen for "what is happening right now and how fast do we pick requests up":
   ride requests and orders waiting (with the longest wait), trips and deliveries on the way, drivers and couriers online and free,
   alerts, and dispatch speed (how long until a driver or courier takes a request, how long a delivery takes) for today and the last 7 days.
   Data: admin_live_ops (api/controllers/LiveOpsController.php), read only. Refreshes itself every 15 seconds while this page is open. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.ETELLiveOps) return;
var esc = K.esc;
var S = { d: null, err: '', win: 'today', timer: null, at: '' };
function run(a, b) { return K.api(a, b || {}); }
function ok(r) { return K.okRes(r); }
function dat(r) { return K.data(r); }
function root() { return document.getElementById('panel-liveops'); }
function css() {
  if (document.getElementById('lo-css')) return;
  var s = document.createElement('style'); s.id = 'lo-css';
  s.textContent = [
    '.lo-hero{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}.lo-hero h2{margin:0}.lo-note{font-size:12.5px;color:var(--mute);margin:0}',
    '.lo-alert{padding:10px 12px;border-radius:12px;margin-bottom:8px;font-size:14px;border:1px solid #c05621;background:rgba(192,86,33,.08)}.lo-alert.critical{border-color:#c53030;background:rgba(197,48,48,.10);font-weight:600}',
    '.lo-ok{padding:10px 12px;border-radius:12px;margin-bottom:8px;font-size:14px;border:1px solid #2f855a;background:rgba(47,133,90,.08)}',
    '.lo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:6px}',
    '.lo-tile{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:12px}.lo-tile .n{font-size:28px;font-weight:800;line-height:1.1}.lo-tile .l{font-size:12.5px;color:var(--mute)}.lo-tile.hot{border-color:#c53030}',
    '.lo-sec{margin:20px 0 8px;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mute)}',
    '.lo-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:12px;margin-bottom:10px}.lo-card h3{margin:0 0 6px;font-size:15px}',
    '.lo-row{display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-top:1px solid var(--line);font-size:14px}.lo-row:first-of-type{border-top:0}.lo-row span:last-child{font-weight:600;text-align:right}',
    '.lo-two{display:grid;grid-template-columns:1fr 1fr;gap:10px}@media(max-width:700px){.lo-two{grid-template-columns:1fr}}'
  ].join('');
  document.head.appendChild(s);
}
function num(v) { return v === null || v === undefined ? '-' : String(v); }
function secs(v) {
  if (v === null || v === undefined) return 'no data';
  v = Math.round(v); if (v < 60) return v + ' s';
  var m = Math.floor(v / 60), r = v % 60;
  if (m < 60) return m + ' min' + (r ? ' ' + r + ' s' : '');
  var h = Math.floor(m / 60); return h + ' h ' + (m % 60) + ' min';
}
function pct(v) { return v === null || v === undefined ? 'no data' : v + '%'; }
function tile(n, label, hot) { return '<div class="lo-tile' + (hot ? ' hot' : '') + '"><div class="n">' + esc(num(n)) + '</div><div class="l">' + esc(label) + '</div></div>'; }
function row(a, b) { return '<div class="lo-row"><span>' + esc(a) + '</span><span>' + esc(b) + '</span></div>'; }
function view() {
  css();
  if (S.err) return '<div class="ak">' + K.empty('!', S.err, '') + '</div>';
  if (!S.d) return '<div class="ak">' + K.skeleton(4) + '</div>';
  var d = S.d, n = d.now, sp = d.speed[S.win], r = sp.rides, o = sp.orders;
  var alerts = d.alerts.length ? d.alerts.map(function (a) { return '<div class="lo-alert ' + esc(a.level) + '">' + esc(a.text) + '</div>'; }).join('') : '<div class="lo-ok">Nothing is waiting too long right now.</div>';
  var waitR = n.rides.waiting.length ? n.rides.waiting.map(function (w) { return row('#' + w.id + ' - ' + (w.where || 'no address') + (w.who ? ' (' + w.who + ')' : ''), w.minutes + ' min'); }).join('') : '<p class="lo-note">No ride request is waiting.</p>';
  var waitO = n.orders.waiting_list.length ? n.orders.waiting_list.map(function (w) { return row(w.code + ' - ' + w.where, w.minutes + ' min'); }).join('') : '<p class="lo-note">No order is waiting for a courier.</p>';
  return '<div class="ak"><div class="lo-hero"><div><p class="lo-note">Updates by itself every 15 seconds. Updated ' + esc(S.at) + '.</p></div><button type="button" class="ak-btn" data-lo-refresh>Refresh now</button></div>' +
    alerts +
    '<div class="lo-sec">Right now</div><div class="lo-grid">' +
    tile(n.rides.searching, 'Ride requests waiting' + (n.rides.oldest_wait_min ? ' (longest ' + n.rides.oldest_wait_min + ' min)' : ''), n.rides.oldest_wait_min >= 6) +
    tile(n.rides.on_the_way, 'Drivers on the way to pick up') + tile(n.rides.in_progress, 'Trips in progress') +
    tile(n.drivers.online, 'Drivers online (' + num(n.drivers.free) + ' free, ' + num(n.drivers.busy) + ' on a trip)') +
    tile(n.orders.waiting, 'Orders waiting for a courier' + (n.orders.oldest_wait_min ? ' (longest ' + n.orders.oldest_wait_min + ' min)' : ''), n.orders.oldest_wait_min >= 15) +
    tile(n.orders.assigned, 'Orders given to a courier') + tile(n.orders.on_the_way, 'Orders on the way to the customer') +
    tile(n.couriers.online, 'Couriers online (' + num(n.couriers.free) + ' free)') +
    tile(n.sos ? n.sos.open : null, 'Open SOS alerts', n.sos && n.sos.open > 0) + '</div>' +
    '<div class="lo-two"><div class="lo-card"><h3>Ride requests waiting</h3>' + waitR + '</div><div class="lo-card"><h3>Orders waiting for a courier</h3>' + waitO + '</div></div>' +
    '<div class="lo-sec">Dispatch speed</div><div class="ak-seg" role="tablist" style="margin:0 0 10px">' +
    [['today', 'Today'], ['week', 'Last 7 days']].map(function (t) { return '<button type="button" role="tab" aria-selected="' + (S.win === t[0]) + '" class="' + (S.win === t[0] ? 'on' : '') + '" data-lo-win="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>' +
    '<div class="lo-two"><div class="lo-card"><h3>Rides</h3>' + row('Requests', num(r.requests)) + row('Taken by a driver', num(r.taken) + ' (' + pct(r.taken_pct) + ')') +
    row('Typical time until a driver takes it', secs(r.secs_to_take.typical)) + row('Slowest 1 in 10', secs(r.secs_to_take.slow)) + row('Cancelled before any driver took it', num(r.gave_up)) + row('Completed', num(r.completed)) + '</div>' +
    '<div class="lo-card"><h3>Orders</h3>' + row('Orders placed', num(o.placed)) + row('Given to a courier', d.has_order_assign_time ? num(o.taken) + ' (' + pct(o.taken_pct) + ')' : 'no data on this server') +
    row('Typical time until a courier takes it', secs(o.secs_to_take.typical)) + row('Slowest 1 in 10', secs(o.secs_to_take.slow)) +
    row('Typical time until delivered', secs(o.secs_to_deliver.typical)) + row('Slowest 1 in 10', secs(o.secs_to_deliver.slow)) + row('Delivered / cancelled', num(o.delivered) + ' / ' + num(o.cancelled)) + '</div></div>' +
    '<p class="lo-note">"Typical" is the middle value: half of the requests were taken faster, half slower. A request that nobody has taken yet is not in the times (it is in the waiting lists above).</p></div>';
}
function paint() { var el = root(); if (el) el.innerHTML = view(); }
function load() {
  return run('admin_live_ops', {}).then(function (r) {
    if (!ok(r)) { S.err = K.failText(r, 'Could not load the live numbers.'); }
    else { S.err = ''; S.d = dat(r); var t = new Date(); S.at = ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2) + ':' + ('0' + t.getSeconds()).slice(-2); }
    paint();
  });
}
function active() { var el = root(); return !!(el && el.offsetParent !== null) && !document.hidden; }
function start() { stop(); load(); S.timer = setInterval(function () { if (active()) load(); }, 15000); }
function stop() { if (S.timer) { clearInterval(S.timer); S.timer = null; } }
function onClick(e) {
  if (e.target.closest('[data-lo-refresh]')) { load(); return; }
  var w = e.target.closest('[data-lo-win]'); if (w) { S.win = w.getAttribute('data-lo-win'); paint(); }
}
function hook() {
  if (window.__loHooked || typeof window.switchPage !== 'function') return;
  window.__loHooked = true;
  var orig = window.switchPage;
  window.switchPage = function (page) {
    var r = orig.apply(this, arguments);
    if (page === 'liveops') {
      var ttl = document.getElementById('page-title'); if (ttl) ttl.textContent = 'Live operations';
      var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
      var el = root(); if (el && !el._lo) { el._lo = true; el.addEventListener('click', onClick); }
      start();
    } else stop();
    return r;
  };
}
function install() {
  K.ensurePanel('liveops', 'liveops', '🛰️', 'Live operations', 'rides');
  hook();
  window.ETELLiveOps = { refresh: load };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
