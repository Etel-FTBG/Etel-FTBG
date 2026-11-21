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
    '.lo-live{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#F2CC6B}',
    '.lo-live i{width:9px;height:9px;border-radius:50%;background:#35d17c;box-shadow:0 0 0 0 rgba(53,209,124,.7);animation:lo-pulse 1.8s infinite}',
    '@keyframes lo-pulse{70%{box-shadow:0 0 0 9px rgba(53,209,124,0)}100%{box-shadow:0 0 0 0 rgba(53,209,124,0)}}',
    '.lo-hero-btn{position:relative;z-index:1;border:1px solid rgba(242,204,107,.55);background:rgba(255,255,255,.08);color:#F2CC6B;border-radius:12px;padding:8px 14px;font:700 13px inherit;cursor:pointer}',
    '.lo-hero-btn:hover{background:rgba(255,255,255,.16)}',
    '.lo-alert{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:16px;margin-bottom:8px;font-size:14px;border:1.5px solid var(--amber);background:rgba(240,180,41,.12);color:var(--ink)}',
    '.lo-alert i{font-style:normal;font-size:18px;line-height:1.2}',
    '.lo-alert.critical{border-color:var(--red);background:rgba(214,54,74,.10);font-weight:700;animation:lo-glow 2.2s infinite}',
    '@keyframes lo-glow{50%{box-shadow:0 0 0 4px rgba(214,54,74,.16)}}',
    '.lo-ok{display:flex;gap:10px;align-items:center;padding:12px 14px;border-radius:16px;margin-bottom:8px;font-size:14px;border:1.5px solid rgba(31,164,99,.45);background:rgba(31,164,99,.09);color:var(--ink)}',
    '.lo-sec{display:flex;align-items:center;gap:8px;margin:22px 0 10px;font-size:12px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--gold2)}',
    '.lo-sec::after{content:"";flex:1;height:1px;background:linear-gradient(90deg,var(--line),transparent)}',
    '.lo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(168px,1fr));gap:10px}',
    '.lo-tile.ak-kpi{min-width:0;white-space:normal}.lo-tile.ak-kpi span{white-space:normal}.lo-tile .n{display:block;font-size:26px;font-weight:800;margin-top:2px;line-height:1.1}',
    '.lo-tile.hot{border-color:var(--red);background:linear-gradient(160deg,rgba(214,54,74,.10),var(--card))}.lo-tile.hot .n{color:var(--red)}',
    '.lo-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:760px){.lo-two{grid-template-columns:1fr}}',
    '.lo-card{background:var(--card);border:1.5px solid var(--line);border-radius:18px;padding:14px;box-shadow:var(--shadow);animation:none}.lo-card h3{margin:0 0 8px;font-size:15px;display:flex;align-items:center;gap:8px}',
    '.lo-row{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 0;border-top:1px solid var(--line);font-size:14px}.lo-row:first-of-type{border-top:0}.lo-row span:last-child{font-weight:700;text-align:right}',
    '.lo-chip{display:inline-block;font-size:12px;font-weight:800;padding:2px 10px;border-radius:99px;background:rgba(31,164,99,.14);color:var(--green)}.lo-chip.amber{background:rgba(240,180,41,.2);color:var(--amber)}.lo-chip.red{background:rgba(214,54,74,.14);color:var(--red)}',
    '.lo-note{font-size:12.5px;color:var(--mute);margin:8px 0 0}',
    '.lo-speed{display:flex;gap:16px;align-items:center;margin-bottom:6px}',
    '.lo-ring{--p:0;flex:0 0 92px;width:92px;height:92px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:conic-gradient(var(--gold) calc(var(--p)*1%),var(--soft) 0);position:relative}',
    '.lo-ring::after{content:"";position:absolute;inset:9px;border-radius:50%;background:var(--card)}.lo-ring b{position:relative;z-index:1;font-size:18px;font-weight:800}.lo-ring small{display:block;position:relative;z-index:1;font-size:9.5px;color:var(--mute);text-align:center}',
    '.lo-ringwrap{display:flex;flex-direction:column;align-items:center;gap:4px;font-size:11px;color:var(--mute)}',
    '.lo-bars{flex:1;min-width:0}.lo-bar{display:grid;grid-template-columns:78px 1fr auto;gap:8px;align-items:center;margin:7px 0;font-size:12.5px}.lo-bar>span{color:var(--mute)}.lo-bar b{font-size:13px}',
    '.lo-bar>div{height:10px;border-radius:99px;background:var(--soft);overflow:hidden}.lo-bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--gold),#F2CC6B)}.lo-bar.slow i{background:linear-gradient(90deg,var(--amber),var(--red))}',
    '@media(prefers-reduced-motion:reduce){.lo-live i,.lo-alert.critical{animation:none}}'
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
function tile(icon, n, label, hot) { return '<div class="ak-kpi static lo-tile' + (hot ? ' hot' : '') + '"><i aria-hidden="true">' + icon + '</i><b class="n">' + esc(num(n)) + '</b><span>' + esc(label) + '</span></div>'; }
function row(a, b) { return '<div class="lo-row"><span>' + esc(a) + '</span><span>' + esc(b) + '</span></div>'; }
function chip(min, warnAt, redAt) { return '<span class="lo-chip' + (min >= redAt ? ' red' : min >= warnAt ? ' amber' : '') + '">' + esc(min + ' min') + '</span>'; }
function ring(p, label) {
  var v = p === null || p === undefined ? 0 : Math.max(0, Math.min(100, p));
  return '<div class="lo-ringwrap"><div class="lo-ring" style="--p:' + v + '" role="img" aria-label="' + esc(label + ' ' + pct(p)) + '"><b>' + esc(p === null || p === undefined ? '-' : p + '%') + '</b></div>' + esc(label) + '</div>';
}
function bars(items) {      /* items: [[label, seconds, slow?]] ; the longest bar is full width */
  var max = 0; items.forEach(function (i) { if (i[1] !== null && i[1] !== undefined && i[1] > max) max = i[1]; });
  return '<div class="lo-bars">' + items.map(function (i) {
    var w = (i[1] === null || i[1] === undefined || !max) ? 0 : Math.max(4, Math.round(100 * i[1] / max));
    return '<div class="lo-bar' + (i[2] ? ' slow' : '') + '"><span>' + esc(i[0]) + '</span><div><i style="width:' + w + '%"></i></div><b>' + esc(secs(i[1])) + '</b></div>';
  }).join('') + '</div>';
}
function view() {
  css();
  if (S.err) return '<div class="ak">' + K.empty('!', S.err, '') + '</div>';
  if (!S.d) return '<div class="ak">' + K.skeleton(4) + '</div>';
  var d = S.d, n = d.now, sp = d.speed[S.win], r = sp.rides, o = sp.orders;
  var waitingNow = (Number(n.rides.searching) || 0) + (Number(n.orders.waiting) || 0), onlineNow = (Number(n.drivers.online) || 0) + (Number(n.couriers.online) || 0), sosOpen = n.sos ? Number(n.sos.open) || 0 : 0;
  var alerts = d.alerts.length ? d.alerts.map(function (a) { return '<div class="lo-alert ' + esc(a.level) + '" role="alert"><i aria-hidden="true">' + (a.level === 'critical' ? '🚨' : '⚠️') + '</i><span>' + esc(a.text) + '</span></div>'; }).join('') : '<div class="lo-ok"><i aria-hidden="true">✅</i><span>Nothing is waiting too long right now.</span></div>';
  var waitR = n.rides.waiting.length ? n.rides.waiting.map(function (w) { return '<div class="lo-row"><span>' + esc('#' + w.id + ' - ' + (w.where || 'no address') + (w.who ? ' (' + w.who + ')' : '')) + '</span><span>' + chip(w.minutes, 3, 6) + '</span></div>'; }).join('') : '<p class="lo-note">No ride request is waiting.</p>';
  var waitO = n.orders.waiting_list.length ? n.orders.waiting_list.map(function (w) { return '<div class="lo-row"><span>' + esc(w.code + ' - ' + w.where) + '</span><span>' + chip(w.minutes, 8, 15) + '</span></div>'; }).join('') : '<p class="lo-note">No order is waiting for a courier.</p>';
  var hero = '<div class="ak-hero"><div class="ak-hero-row"><div><span class="lo-live"><i></i>Live</span><h2>Operations at a glance</h2><p>Updates by itself every 15 seconds. Updated ' + esc(S.at) + '.</p></div>' +
    '<button type="button" class="lo-hero-btn" data-lo-refresh>Refresh now</button></div>' +
    '<div class="ak-hero-big"><div><b>' + esc(waitingNow) + '</b><span>Waiting now</span></div><div><b>' + esc(onlineNow) + '</b><span>Online now</span></div><div><b>' + esc(sosOpen) + '</b><span>Open SOS</span></div></div></div>';
  return '<div class="ak">' + hero + alerts +
    '<div class="lo-sec">Right now</div><div class="lo-grid">' +
    tile('🚕', n.rides.searching, 'Ride requests waiting' + (n.rides.oldest_wait_min ? ' (longest ' + n.rides.oldest_wait_min + ' min)' : ''), n.rides.oldest_wait_min >= 6) +
    tile('🧭', n.rides.on_the_way, 'Drivers on the way to pick up') + tile('🛣️', n.rides.in_progress, 'Trips in progress') +
    tile('🟢', n.drivers.online, 'Drivers online (' + num(n.drivers.free) + ' free, ' + num(n.drivers.busy) + ' on a trip)') +
    tile('📦', n.orders.waiting, 'Orders waiting for a courier' + (n.orders.oldest_wait_min ? ' (longest ' + n.orders.oldest_wait_min + ' min)' : ''), n.orders.oldest_wait_min >= 15) +
    tile('🤝', n.orders.assigned, 'Orders given to a courier') + tile('🛵', n.orders.on_the_way, 'Orders on the way to the customer') +
    tile('🧑‍✈️', n.couriers.online, 'Couriers online (' + num(n.couriers.free) + ' free)') +
    tile('🆘', n.sos ? n.sos.open : null, 'Open SOS alerts', sosOpen > 0) + '</div>' +
    '<div class="lo-two" style="margin-top:12px"><div class="lo-card"><h3><span aria-hidden="true">🚕</span>Ride requests waiting</h3>' + waitR + '</div><div class="lo-card"><h3><span aria-hidden="true">📦</span>Orders waiting for a courier</h3>' + waitO + '</div></div>' +
    '<div class="lo-sec">Dispatch speed</div><div class="ak-seg" role="tablist" style="margin:0 0 12px">' +
    [['today', 'Today'], ['week', 'Last 7 days']].map(function (t) { return '<button type="button" role="tab" aria-selected="' + (S.win === t[0]) + '" class="' + (S.win === t[0] ? 'on' : '') + '" data-lo-win="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>' +
    '<div class="lo-two"><div class="lo-card"><h3><span aria-hidden="true">🚕</span>Rides</h3><div class="lo-speed">' + ring(r.taken_pct, 'taken') +
    bars([['Typical', r.secs_to_take.typical], ['Slowest 1 in 10', r.secs_to_take.slow, true]]) + '</div>' +
    row('Requests', num(r.requests)) + row('Taken by a driver', num(r.taken) + ' (' + pct(r.taken_pct) + ')') +
    row('Typical time until a driver takes it', secs(r.secs_to_take.typical)) + row('Slowest 1 in 10', secs(r.secs_to_take.slow)) + row('Cancelled before any driver took it', num(r.gave_up)) + row('Completed', num(r.completed)) + '</div>' +
    '<div class="lo-card"><h3><span aria-hidden="true">📦</span>Orders</h3><div class="lo-speed">' + ring(d.has_order_assign_time ? o.taken_pct : null, 'given to a courier') +
    bars([['Courier takes it', o.secs_to_take.typical], ['Slowest 1 in 10', o.secs_to_take.slow, true], ['Delivered', o.secs_to_deliver.typical], ['Delivery, slowest', o.secs_to_deliver.slow, true]]) + '</div>' +
    row('Orders placed', num(o.placed)) + row('Given to a courier', d.has_order_assign_time ? num(o.taken) + ' (' + pct(o.taken_pct) + ')' : 'no data on this server') +
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
