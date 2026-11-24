/* ETEL Courier - the courier's own pin on the map is the REAL picture of their vehicle (additive script, no bundle release).
   The app drew a plain green dot (or a small emoji when it knew the vehicle). This replaces that pin's picture with the matching real vehicle
   from img/vehicles/ (js/etel-vehicle-pic.js knows which picture belongs to which vehicle), on the home map and in full-screen navigation.
   The vehicle comes from the profile row the app already fills (#cp-vehicle) or, failing that, from the courier profile. A vehicle with no picture
   keeps what the app drew. Fails quietly. No inline handlers. */
(function () {
  'use strict';
  if (window.__etelVehMarker) return;
  window.__etelVehMarker = true;
  var type = '', el = null, built = '', asked = false;

  function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
  function apiUrl() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
  function fromProfileRow() { var v = document.getElementById('cp-vehicle'), t = v ? (v.textContent || '').trim() : ''; return (!t || t === '—' || /not set/i.test(t)) ? '' : t; }
  function ask() {
    if (asked || !token()) return; asked = true;
    fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify({ action: 'courier_get_profile' }) })
      .then(function (r) { return r.json(); })
      .then(function (j) { var d = j && j.success && j.data; var v = d && (d.vehicle_type || (d.courier && d.courier.vehicle_type) || (d.profile && d.profile.vehicle_type)); if (v && !type) type = String(v); })
      .catch(function () { /* quiet */ });
  }
  /* In dark mode the whole map is drawn through filter:invert(90%) hue-rotate(180deg) (js/core.js), which also turns the vehicle picture pale. The exact opposite
     filter on the picture gives it back its real colours: hue-rotate(180deg) invert(1) contrast(1.25) undoes invert(90%) then hue-rotate(180deg). */
  function css() {
    if (document.getElementById('cvm-css')) return;
    var s = document.createElement('style'); s.id = 'cvm-css';
    s.textContent = 'body.dark-theme .cvm-pic{filter:hue-rotate(180deg) invert(1) contrast(1.25)}';
    document.head.appendChild(s);
  }
  function build(t) {
    var url = window.EtelVehiclePic && EtelVehiclePic.has(t) ? EtelVehiclePic.url(t) : '';
    if (!url) return null;
    css();
    var d = document.createElement('div'); d.className = 'cvm-pic';
    d.style.cssText = 'position:relative;width:64px;height:52px;display:flex;align-items:flex-end;justify-content:center;pointer-events:none';
    var ring = document.createElement('div');
    ring.style.cssText = 'position:absolute;bottom:2px;left:50%;width:46px;height:14px;margin-left:-23px;border-radius:50%;background:rgba(46,204,113,.45);box-shadow:0 0 0 3px rgba(46,204,113,.3)';
    var im = document.createElement('img'); im.src = url; im.alt = ''; im.draggable = false;
    im.style.cssText = 'position:relative;width:60px;height:auto;max-height:50px;object-fit:contain;filter:drop-shadow(0 3px 3px rgba(0,0,0,.45))';
    d.appendChild(ring); d.appendChild(im);
    return d;
  }
  function tick() {
    var m; try { m = (typeof courierMarker !== 'undefined') ? courierMarker : null; } catch (e) { m = null; }
    if (!m) return;
    var t = type || fromProfileRow();
    if (!t) { ask(); return; }
    if (t !== built) { el = build(t); built = t; }
    if (el && m.content !== el) { try { m.content = el; } catch (e) { /* marker not ready */ } }
  }
  setInterval(tick, 1200);
})();
