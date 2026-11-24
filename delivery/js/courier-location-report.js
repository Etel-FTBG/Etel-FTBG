/* ETEL Courier - tell the server where the courier is, so the customer's live map moves (additive script, no bundle release).
   Until now the courier app never sent its position after going online, so the customer's tracking map had no courier to show ("Waiting for the courier
   location"). This sends the position the app already has (courierLat / courierLng, from the app's own GPS watch: no second GPS watcher, no extra battery for
   that) to api action courier_update_location:
     - while the courier has a job on the Active tab: about every 8 seconds (and at least every 25 s even when standing still);
     - while online with no job: about every 30 seconds (so dispatch knows who is near);
     - never while the courier is offline with no job, never while the screen is hidden; at once when the app comes back to the screen.
   A failed send is quietly tried again later. The position is only sent when it looks real (inside latitude / longitude limits). */
(function () {
  'use strict';
  if (window.__etelLocReport) return;
  window.__etelLocReport = true;
  var last = { at: 0, lat: null, lng: null }, busy = false;

  function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
  function apiUrl() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
  function pos() {
    try { if (typeof courierLat !== 'undefined' && typeof courierLng !== 'undefined' && courierLat && courierLng) return { lat: +courierLat, lng: +courierLng }; } catch (e) {}
    return null;
  }
  function online() { try { return typeof COURIER_IS_ONLINE !== 'undefined' && !!COURIER_IS_ONLINE; } catch (e) { return false; } }
  function hasJob() { return !!document.querySelector('#active-content .job-card, #active .job-card'); }
  function metres(a, b) {
    var k = Math.PI / 180, x = (b.lng - a.lng) * k * Math.cos(a.lat * k), y = (b.lat - a.lat) * k;
    return Math.sqrt(x * x + y * y) * 6371000;
  }
  function send(p) {
    busy = true; last = { at: Date.now(), lat: p.lat, lng: p.lng };
    fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify({ action: 'courier_update_location', lat: p.lat, lng: p.lng }), keepalive: true })
      .catch(function () { last.at = Date.now() - 4000; })   // try again soon
      .then(function () { busy = false; });
  }
  function tick(force) {
    if (busy || !token() || document.visibilityState === 'hidden') return;
    var job = hasJob(), on = online();
    if (!job && !on) return;
    var p = pos(); if (!p || Math.abs(p.lat) > 90 || Math.abs(p.lng) > 180) return;
    var every = job ? 8000 : 30000, age = Date.now() - last.at;
    if (force || age >= every) {
      var still = last.lat != null && metres(last, p) < 10;
      if (!force && still && age < (job ? 25000 : 60000)) return;
      send(p);
    }
  }
  setInterval(function () { tick(false); }, 4000);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') tick(true); });
  window.__etelLocTick = tick;   // for tests
})();
