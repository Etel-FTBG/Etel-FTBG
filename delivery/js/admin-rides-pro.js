/* ETEL Admin - Rides: the trip board.
   Replaces the plain trips table with a live board: today's numbers on top, a status filter, search (name, phone,
   plate), a card or table view, "load more" instead of page numbers, and a trip drawer with the passenger, driver,
   route, distance, payment, fare lines, timings and the controls (cancel a ride that has not started, manually complete
   a ride whose GPS died, copy the ride number). Everything else on the Rides tab (system health, live trip watch,
   growth chart, issue reports, driver checks) is untouched.
   Data: admin_get_rides, admin_get_ride_stats, admin_cancel_ride (RideController.php); manual completion reuses the
   existing ridesShowManualComplete() map form. Standalone: wraps ridesLoad() so admin-rides.js is not edited.
   Every server value goes through AdminKit.esc; no inline handlers. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var PAGE = 30;
var ST = {
  searching:   { label: 'Finding a driver', short: 'Searching', tone: 'amber',  icon: '🔍' },
  accepted:    { label: 'Driver on the way', short: 'Accepted', tone: 'blue',   icon: '🚕' },
  in_progress: { label: 'Trip in progress',  short: 'In progress', tone: 'violet', icon: '🛣️' },
  arrived:     { label: 'Arrived',           short: 'Arrived',  tone: 'violet', icon: '🏁' },
  completed:   { label: 'Completed',         short: 'Done',     tone: 'green',  icon: '✅' },
  cancelled:   { label: 'Cancelled',         short: 'Cancelled', tone: 'red',   icon: '✕' }
};
var VEH = { car: '🚗', sedan: '🚗', moto: '🏍️', motorcycle: '🏍️', bike: '🏍️', bajaj: '🛺', van: '🚐', minibus: '🚐' };
var V = { status: 'all', q: '', mode: 'cards', rows: [], total: 0, stats: null, loaded: false, busy: false, openId: null, timer: null };
try { var m = localStorage.getItem('rid-mode'); if (m === 'table' || m === 'cards') V.mode = m; } catch (e) { /* private window */ }

function root() { return document.getElementById('rides-pro'); }
function st(s) { return ST[s] || { label: String(s || '—').replace(/_/g, ' '), short: String(s || '—'), tone: 'grey', icon: '•' }; }
function fare(r) {
  var f = parseFloat(r.fare_paid) > 0 ? ['', r.fare_paid] : parseFloat(r.fare_final) > 0 ? ['~', r.fare_final] : parseFloat(r.fare_estimate) > 0 ? ['~', r.fare_estimate] : null;
  return f ? f[0] + K.money(f[1]) : '—';
}
function when(r) { return r.created_at ? String(r.created_at).replace('T', ' ') : ''; }
function api(offset) { return K.api('admin_get_rides', { status: V.status, search: V.q, limit: PAGE, offset: offset || 0 }); }

function loadStats() {
  return K.api('admin_get_ride_stats', {}).then(function (res) { if (K.okRes(res)) { V.stats = K.data(res); if (V.loaded) paintHero(); } });
}
function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  K.css(); V.busy = true; loadStats();
  if (!quiet && !V.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(6) + '</div>';
  return api(0).then(function (res) {
    V.busy = false;
    if (!K.okRes(res)) { if (!V.loaded) el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load rides', K.failText(res)) + '</div>'; else K.toast(K.failText(res), true); return; }
    var d = K.data(res); V.rows = d.rides || []; V.total = d.total || 0; V.loaded = true; paint();
  });
}
function loadMore() {
  if (V.busy) return; V.busy = true;
  api(V.rows.length).then(function (res) {
    V.busy = false;
    if (!K.okRes(res)) { K.toast(K.failText(res), true); return; }
    var d = K.data(res); V.rows = V.rows.concat(d.rides || []); V.total = d.total || V.total; paint();
  });
}

function heroHtml() {
  var s = V.stats || {};
  return K.hero('Rides', 'Every trip on ETEL Ride. Search a rider, driver or plate, open a trip for the full story, and step in when a trip gets stuck.', [
    ['Active now', K.num(s.active_now)], ['Drivers online', K.num(s.online_drivers)], ['Completed today', K.num(s.today_completed)], ['Earned today', K.money(s.today_revenue)]]);
}
function paintHero() { var h = document.getElementById('rid-hero'); if (h) h.innerHTML = heroHtml(); }

function paint() {
  var el = root(); if (!el) return;
  var focus = document.activeElement && document.activeElement.id === 'rid-q';
  var chips = [['all', 'All']].concat(Object.keys(ST).map(function (k) { return [k, ST[k].icon + ' ' + ST[k].short]; }));
  var h = '<div class="ak"><div id="rid-hero">' + heroHtml() + '</div>' + K.seg('status', chips, V.status) +
    '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="rid-q" type="search" placeholder="Rider, driver, phone or plate…" value="' + esc(V.q) + '" autocomplete="off"></label>' +
    K.seg('mode', [['cards', '🎴 Cards'], ['table', '☰ Table']], V.mode) + '<button type="button" class="ak-btn" data-act="refresh" aria-label="Refresh">🔄</button><button type="button" class="ak-btn" data-act="export">📥 Export CSV</button></div>' +
    '<div class="ak-count"><span class="ak-live"></span>' + K.num(V.rows.length) + ' of ' + K.num(V.total) + ' ride' + (V.total === 1 ? '' : 's') + '</div>';
  if (!V.rows.length) h += K.empty('🚕', 'No rides found', V.q ? 'Nothing matches your search.' : 'Trips will appear as riders book them.');
  else h += (V.mode === 'table' ? table() : '<div class="ak-grid">' + V.rows.map(card).join('') + '</div>') +
    (V.rows.length < V.total ? '<div style="text-align:center;margin-top:14px"><button type="button" class="ak-btn" data-act="more">Load more (' + K.num(V.total - V.rows.length) + ' left)</button></div>' : '');
  el.innerHTML = h + '</div>';
  if (focus) { var i = document.getElementById('rid-q'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }
}

function card(r) {
  var s = st(r.status), v = VEH[String(r.vehicle_type || '').toLowerCase()] || '🚕', live = r.status === 'searching' || r.status === 'accepted' || r.status === 'in_progress' || r.status === 'arrived';
  return '<div class="ak-card' + (r.status === 'cancelled' ? ' off' : '') + '" tabindex="0" role="button" data-open="' + esc(r.id) + '">' +
    '<div class="ak-top"><div class="ak-av" style="--a:#E8C14A;--b:#9a7a1c">' + esc(s.icon) + '</div><div class="ak-nm"><b>Ride #' + esc(r.id) + '</b><small>' + esc(K.ago(r.created_at)) + (r.open_trip == 1 ? ' · open trip' : '') + '</small></div>' + K.pill(s.short, s.tone) + '</div>' +
    '<div class="ak-route"><i>📍</i><span>' + esc(r.pickup_address || '—') + '</span></div><div class="ak-route"><i>🏁</i><span>' + esc(r.dropoff_address || '—') + '</span></div>' +
    '<div class="ak-chips"><span class="ak-chip">🧍 ' + esc(r.passenger_name || '—') + '</span><span class="ak-chip">' + esc(v) + ' ' + esc(r.driver_name || 'No driver yet') + (r.vehicle_plate ? ' · ' + esc(r.vehicle_plate) : '') + '</span></div>' +
    '<div class="ak-row"><span>' + (live ? '<span class="ak-live"></span>Live' : esc(r.payment_method || 'cash')) + '</span><b style="font-size:15px">' + esc(fare(r)) + '</b></div></div>';
}
function table() {
  return '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th>Ride</th><th>Status</th><th>Rider</th><th>Driver</th><th>Route</th><th>Fare</th><th>When</th></tr></thead><tbody>' + V.rows.map(function (r) {
    var s = st(r.status);
    return '<tr data-open="' + esc(r.id) + '"><td><b>#' + esc(r.id) + '</b></td><td>' + K.pill(s.short, s.tone) + '</td><td>' + esc(r.passenger_name || '—') + '<br><small>' + esc(r.passenger_phone || '') + '</small></td><td>' + esc(r.driver_name || '—') + '<br><small>' + esc(r.vehicle_plate || r.driver_phone || '') + '</small></td>' +
      '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis">' + esc(r.pickup_address || '—') + ' → ' + esc(r.dropoff_address || '—') + '</td><td>' + esc(fare(r)) + '</td><td>' + esc(K.ago(r.created_at)) + '</td></tr>';
  }).join('') + '</tbody></table></div>';
}

/* ---------- drawer ---------- */
function find(id) { var f = null; V.rows.forEach(function (r) { if (String(r.id) === String(id)) f = r; }); return f; }
function openRide(id) {
  var r = find(id); if (!r) return;
  V.openId = r.id;
  var s = st(r.status), canCancel = r.status === 'searching' || r.status === 'accepted', canComplete = r.status === 'in_progress' || r.status === 'arrived';
  var head = '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">' + esc(s.icon) + '</div><div class="ak-nm"><b>Ride #' + esc(r.id) + '</b><small>' + esc(when(r)) + '</small></div>' + K.pill(s.label, s.tone);
  var tel = function (p) { return p ? ' · <a href="tel:' + esc(p) + '">' + esc(p) + '</a>' : ''; };
  var money = [['Estimate', r.fare_estimate], ['Final', r.fare_final], ['Paid', r.fare_paid]].filter(function (x) { return parseFloat(x[1]) > 0; }).map(function (x) { return [x[0], K.money(x[1])]; });
  var km = parseFloat(r.distance_km || r.traveled_km || 0);
  var h = '<div class="ak-sec"><h4>People</h4>' + K.kv([['Rider', '<b>' + esc(r.passenger_name || '—') + '</b>' + tel(r.passenger_phone), true],
      ['Driver', r.driver_name ? '<b>' + esc(r.driver_name) + '</b>' + tel(r.driver_phone) : 'No driver yet', !!r.driver_name || true], ['Vehicle', [r.vehicle_type, r.vehicle_plate].filter(Boolean).join(' · ')],
      ['Driver rating', parseFloat(r.driver_rating) > 0 ? '★ ' + r.driver_rating + (r.driver_total_rides ? ' · ' + K.num(r.driver_total_rides) + ' rides' : '') : '']]) + '</div>' +
    '<div class="ak-sec"><h4>Route</h4><div class="ak-route"><i>📍</i><span>' + esc(r.pickup_address || '—') + '</span></div><div class="ak-route"><i>🏁</i><span>' + esc(r.dropoff_address || '—') + '</span></div>' +
    '<div style="margin-top:10px">' + K.kv([['Distance', km > 0 ? km.toFixed(2) + ' km' : ''], ['Trip type', r.open_trip == 1 ? 'Open trip (GPS meter)' : 'Fixed route']]) + '</div></div>' +
    '<div class="ak-sec"><h4>Money</h4>' + K.kv(money.concat([['Payment', String(r.payment_method || 'cash').replace(/_/g, ' ')]])) + '</div>' +
    '<div class="ak-sec"><h4>Timeline</h4><ul class="ak-tl"><li><b>Booked</b><small>' + esc(K.fullDate(r.created_at)) + '</small></li>' + (r.started_at ? '<li><b>Started</b><small>' + esc(K.fullDate(r.started_at)) + '</small></li>' : '') + (r.completed_at ? '<li><b>' + (r.status === 'cancelled' ? 'Ended' : 'Completed') + '</b><small>' + esc(K.fullDate(r.completed_at)) + '</small></li>' : '') + '</ul></div>' +
    '<div class="ak-sec"><h4>Controls</h4><div class="ak-acts">' + (canComplete ? '<button type="button" class="ak-btn gold" data-complete>Manually complete…</button>' : '') + (canCancel ? '<button type="button" class="ak-btn danger" data-cancel>Cancel ride…</button>' : '') +
    '<button type="button" class="ak-btn" data-copy>Copy ride number</button></div>' + (!canComplete && !canCancel ? '<div class="ak-fh" style="margin-top:8px">This ride is ' + esc(s.short.toLowerCase()) + ', so there is nothing to change.</div>' : '') + '</div>';
  var dr = K.openDrawer(head, h);
  dr._r = r;
}
function onDrawerClick(e) {
  var dr = K.drawerEl(); if (!dr || !dr._r || !dr.contains(e.target)) return;
  var r = dr._r, t = e.target;
  if (t.closest('[data-copy]')) { try { navigator.clipboard.writeText('#' + r.id).then(function () { K.toast('Copied #' + r.id); }, function () { K.toast('Could not copy', true); }); } catch (x) { K.toast('Could not copy', true); } }
  else if (t.closest('[data-cancel]')) {
    K.confirm({ title: 'Cancel ride #' + r.id + '?', text: 'The rider and the driver are told it is cancelled.', label: 'Cancel ride', danger: true }).then(function (ok) {
      if (ok) K.api('admin_cancel_ride', { ride_id: parseInt(r.id, 10) }).then(function (res) { if (K.okRes(res)) { K.closeDrawer(); K.toast('Ride cancelled'); V.busy = false; load(true); } else K.toast(K.failText(res, 'Cannot cancel this ride'), true); });
    });
  } else if (t.closest('[data-complete]')) {
    K.closeDrawer();
    if (typeof ridesShowManualComplete === 'function') {
      ridesShowManualComplete(r.id);
      var n = 0, iv = setInterval(function () { n++; if (!document.getElementById('mc-overlay') || n > 400) { clearInterval(iv); V.busy = false; load(true); } }, 1500);
    } else K.toast('The manual completion form is not available here.', true);
  }
}

function csv() {
  var q = function (v) { return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; };
  var rows = V.rows.map(function (r) { return [r.id, r.status, r.passenger_name, r.passenger_phone, r.driver_name, r.driver_phone, r.vehicle_plate, r.pickup_address, r.dropoff_address, r.distance_km, r.fare_final || r.fare_estimate, r.fare_paid, r.payment_method, r.created_at].map(q).join(','); });
  var a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(['ID,Status,Passenger,Phone,Driver,Driver Phone,Plate,Pickup,Dropoff,Distance,Fare,Paid,Payment,Created'].concat(rows).join('\n'));
  a.download = 'etel-rides-' + new Date().toISOString().slice(0, 10) + '.csv'; a.click();
}

var search = K.debounce(function () { load(true); }, 400);
function wire(el) {
  if (el._ridWired) return; el._ridWired = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-seg]'))) {
      var n = b.getAttribute('data-seg'), v = b.getAttribute('data-val');
      if (n === 'status') { V.status = v; load(true); } else { V.mode = v; try { localStorage.setItem('rid-mode', v); } catch (x) { /* ignore */ } paint(); }
      return;
    }
    if (t.closest('[data-act="refresh"]')) { load(true); return; }
    if (t.closest('[data-act="more"]')) { loadMore(); return; }
    if (t.closest('[data-act="export"]')) { csv(); return; }
    if ((b = t.closest('[data-open]'))) openRide(b.getAttribute('data-open'));
  });
  el.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.ak-card[data-open]')) { e.preventDefault(); openRide(e.target.getAttribute('data-open')); } });
  el.addEventListener('input', function (e) { if (e.target.id === 'rid-q') { V.q = e.target.value.trim(); search(); } });
}
document.addEventListener('click', onDrawerClick);

function visible() { var p = document.getElementById('panel-rides'); return p && p.style.display !== 'none' && !document.hidden; }
function install() {
  var el = root(); if (!el) return;
  wire(el);
  /* The trip board replaces the old table; the sections below it (health, live watch, issues, vehicle / identity /
     document review) are rebuilt in admin-rides-desk.js, so the old loader is no longer called (no double requests). */
  window.ridesLoad = function () { load(V.loaded); };
  window.ridesFilter = window.ridesGoPage = window.ridesSearchDebounce = function () { load(V.loaded); };
  if (!V.timer) V.timer = setInterval(function () { if (visible() && !K.drawerEl()) load(true); }, 30000);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
