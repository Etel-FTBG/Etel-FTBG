/* ETEL Driver - trip meter and waiting clock fixes (release add-on).
   1. The waiting charge (ETB) now counts up while the waiting clock runs. It used to stay at "ETB 0" until the clock was stopped,
      because the meter answer never carried the waiting charge into the driver screen.
   2. The phone's own GPS speed is sent with every position report, so the server can tell a parked vehicle that drifts a few
      metres from a vehicle that is really driving (the server decides; see driver_update_location in RideController.php).
   The server sends wait_etb, wait_rate_per_min and wait_cap_minutes with every meter answer; this file only displays and forwards them.
   Loaded as the last file of the driver bundle (see tools/build_driver_release.py), so every function it replaces is already defined. */
(function () {
'use strict';
if (window.__etelMeterFix) return;
window.__etelMeterFix = true;

/* ---- GPS speed: one extra position watch, only while the driver is online (the same GPS chip, so no extra battery to speak of) ---- */
var geo = { speed: null, at: 0 }, watchId = null;
function startWatch() {
  if (watchId !== null || !navigator.geolocation || !navigator.geolocation.watchPosition) return;
  try {
    watchId = navigator.geolocation.watchPosition(function (p) {
      var s = p && p.coords ? p.coords.speed : null;
      geo.speed = (typeof s === 'number' && isFinite(s) && s >= 0) ? s : null;
      geo.at = Date.now();
    }, function () { /* no fix right now: the speed simply stays unknown */ }, { enableHighAccuracy: true, maximumAge: 2000, timeout: 20000 });
  } catch (e) { watchId = null; }
}
function stopWatch() {
  if (watchId === null) return;
  try { navigator.geolocation.clearWatch(watchId); } catch (e) { /* already gone */ }
  watchId = null; geo.speed = null;
}
setInterval(function () {
  var online = typeof DRV !== 'undefined' && DRV && DRV.isOnline;
  if (online) startWatch(); else stopWatch();
}, 3000);

/* ---- meter answers: carry the waiting charge, rate and cap into the driver state ---- */
function absorb(action, r) {
  if (!r || typeof DRV === 'undefined') return;
  var src = action === 'driver_update_location' ? r.meter : r;
  if (!src) return;
  if (action === 'driver_update_location' && src.wait_etb == null && src.fare_wait != null) src.wait_etb = src.fare_wait;
  var rate = parseFloat(src.wait_rate_per_min);
  if (rate > 0) DRV.waitRate = rate;
  var cap = parseFloat(src.wait_cap_minutes);
  if (cap >= 0) DRV.waitCapMin = cap;
}
var origApi = window.drvApi;
if (typeof origApi === 'function') {
  window.drvApi = function (action, params, ok) {
    var args = Array.prototype.slice.call(arguments);
    if (action === 'driver_update_location' && params && geo.speed !== null && Date.now() - geo.at < 8000) params.speed = Math.round(geo.speed * 100) / 100;
    if ((action === 'driver_update_location' || action === 'driver_toggle_wait') && typeof ok === 'function') {
      args[2] = function (r) { try { absorb(action, r); } catch (e) { /* display only */ } return ok.apply(this, arguments); };
    }
    return origApi.apply(this, args);
  };
}

/* ---- the waiting box: the charge ticks with the clock ---- */
window._updateWaitBox = function () {
  var ride = DRV.activeRide;
  var box = document.getElementById('wait-toggle-box');
  var statusEl = document.getElementById('wait-status-text');
  var btn = document.getElementById('wait-toggle-btn');
  if (!ride || !box || !statusEl || !btn) return;
  var active = !!ride.wait_active;
  var elapsedSec = (DRV.waitBaselineMinutes || 0) * 60;
  if (active && DRV.waitBaselineMs) elapsedSec += (Date.now() - DRV.waitBaselineMs) / 1000;
  box.style.background = active ? 'rgba(255,152,0,.12)' : 'rgba(255,255,255,.03)';
  box.style.borderColor = active ? 'rgba(255,152,0,.4)' : 'rgba(212,175,55,.15)';
  statusEl.style.color = active ? '#FFB74D' : 'var(--text-dim)';
  var server = parseFloat(ride.wait_etb) || 0;
  var rate = parseFloat(DRV.waitRate) || 0;
  var cap = DRV.waitCapMin > 0 ? DRV.waitCapMin : 30;
  var live = rate > 0 ? Math.min(elapsedSec / 60, cap) * rate : 0;
  var etb = (active ? Math.max(live, server) : (server > 0 ? server : live)).toFixed(0);
  statusEl.textContent = active
    ? ('⏱ ' + _fmtWaitClock(elapsedSec) + ' · ETB ' + etb + ' so far')
    : ((ride.wait_minutes > 0) ? ('Stopped · ETB ' + etb + ' total') : 'Not waiting');
  btn.textContent = active ? '⏹ Stop Waiting' : '⏱ Start Waiting';
  btn.style.background = active ? 'linear-gradient(135deg,#E74C3C,#C0392B)' : 'linear-gradient(135deg,#FFD700,#D4AF37)';
  btn.style.color = active ? '#fff' : '#1a1206';
};
})();
