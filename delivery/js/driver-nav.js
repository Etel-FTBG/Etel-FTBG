/* ════════════════════════════════════════════════════════════════
   ETEL DRIVER — navigation overlay (ADD-ON)
   Load AFTER driver.js: <script src="js/driver-nav.js"></script>
   Auto-routes to pickup on ride accept, to dropoff on ride start.
   Shows a premium nav strip with live distance + ETA.
   ════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var navState = { dest: null, label: '', km: 0, eta: 0 };

  injectStyles();
  function whenReady(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  whenReady(function () { injectNavPanel(); wrapDriverHooks(); startTracking(); });

  /* ── Premium nav panel injected above driver panel ── */
  function injectNavPanel() {
    if (document.getElementById('dn-panel')) return;
    var p = document.createElement('div'); p.id = 'dn-panel'; p.className = 'dn-panel';
    p.innerHTML =
      '<div class="dn-icon" id="dn-icon" data-ei="route" data-size="22"></div>' +
      '<div class="dn-body">' +
        '<div class="dn-label" id="dn-label">Waiting for ride request</div>' +
        '<div class="dn-dist" id="dn-dist">Go online to receive requests</div>' +
      '</div>' +
      '<button class="dn-open" id="dn-open" onclick="dnOpenMaps()" style="display:none">Open Maps</button>' +
      '<div class="dn-bar"><div class="dn-prog" id="dn-prog"></div></div>';
    // .driver-panel never existed in driver.html -- that mismatch caused
    // this panel to fall through to document.body, landing outside the
    // normal-flow layout (everything else on this page is position:fixed)
    // and rendering as a stray overlay across the top of the screen.
    var panel = document.querySelector('.req-sheet');
    if (panel) panel.prepend(p); else document.body.appendChild(p);
  }

  function setNavPanel(icon, label, dist, showOpen) {
    var el = document.getElementById('dn-icon');
    if (el) {
      // Callers pass the legacy emoji; render the matching drawn icon instead.
      var iconName = { '\uD83D\uDCCD': 'pin', '\uD83C\uDFC1': 'flag', '\u2705': 'check', '\uD83E\uDDED': 'route' }[icon];
      if (window.EtelUI && iconName) el.innerHTML = EtelUI.icon(iconName, 22); else el.textContent = icon;
    }
    var ll = document.getElementById('dn-label');  if (ll) ll.textContent = label;
    var dl = document.getElementById('dn-dist');   if (dl) dl.textContent = dist;
    var op = document.getElementById('dn-open');   if (op) op.style.display = showOpen ? 'flex' : 'none';
    navState.label = label;
  }

  function setProgress(frac) {
    var el = document.getElementById('dn-prog');
    if (el) el.style.width = (Math.max(0, Math.min(1, frac)) * 100) + '%';
  }

  /* ── Wrap acceptRide → auto-route to pickup ── */
  function wrapDriverHooks() {
    var origAccept = window.acceptRide;
    window.acceptRide = function (id) {
      if (typeof origAccept === 'function') origAccept.call(this, id);
      /* Route to pickup after a tick (let DRV.activeRide be set first) */
      setTimeout(function () {
        if (!window.DRV || !DRV.activeRide) return;
        var ride = DRV.activeRide;
        var lat = parseFloat(ride.pickup_lat || ride.from_lat || 0);
        var lng = parseFloat(ride.pickup_lng || ride.from_lng || 0);
        if (!lat || !lng) return;
        navState.dest = { lat: lat, lng: lng };
        navState.stage = 'pickup';
        if (typeof drawRoute === 'function') drawRoute({ lat: lat, lng: lng });
        setNavPanel('📍', 'Navigate to Pickup', 'Calculating route…', true);
        dnOpenMaps();   // auto-open turn-by-turn
      }, 800);
    };

    var origStart = window.startRide;
    window.startRide = function (id) {
      if (typeof origStart === 'function') origStart.call(this, id);
      setTimeout(function () {
        if (!window.DRV || !DRV.activeRide) return;
        var ride = DRV.activeRide;
        var lat = parseFloat(ride.dropoff_lat || ride.to_lat || 0);
        var lng = parseFloat(ride.dropoff_lng || ride.to_lng || 0);
        if (!lat || !lng) return;
        navState.dest = { lat: lat, lng: lng };
        navState.stage = 'dropoff';
        if (typeof drawRoute === 'function') drawRoute({ lat: lat, lng: lng });
        setNavPanel('🏁', 'Navigate to Drop-off', 'Calculating route…', true);
        if (navigator.vibrate && !reduced) navigator.vibrate([15, 40, 25]);
      }, 600);
    };

    var origComplete = window.completeRide;
    window.completeRide = function (id) {
      if (typeof origComplete === 'function') origComplete.call(this, id);
      navState.dest = null; navState.stage = null;
      setNavPanel('✅', 'Ride Complete', 'Well done! Go online for next request', false);
      setProgress(1);
      setTimeout(function () { setNavPanel('🧭', 'Ready for requests', 'Go online to receive requests', false); setProgress(0); }, 4000);
    };
  }

  /* ── Open Google Maps turn-by-turn for the current destination ── */
  window.dnOpenMaps = function () {
    if (!navState.dest) return;
    var url = 'https://www.google.com/maps/dir/?api=1&destination=' + navState.dest.lat + ',' + navState.dest.lng + '&travelmode=driving';
    window.open(url, '_blank');
  };

  /* ── Live distance tracking loop ── */
  function haversine(a, b, c, d) {
    var R = 6371, r = Math.PI / 180, dA = (c - a) * r, dB = (d - b) * r;
    var x = Math.sin(dA / 2) * Math.sin(dA / 2) + Math.cos(a * r) * Math.cos(c * r) * Math.sin(dB / 2) * Math.sin(dB / 2);
    return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
  }

  function startTracking() {
    setInterval(function () {
      if (!navState.dest || !window.DRV) return;
      var lat = DRV.currentLat, lng = DRV.currentLng;
      if (!lat || !lng) return;
      var km = haversine(lat, lng, navState.dest.lat, navState.dest.lng);
      var eta = Math.max(1, Math.round(km * 3));
      navState.km = km; navState.eta = eta;
      var stage = navState.stage === 'dropoff' ? 'dropoff' : 'pickup';
      var frac = Math.max(0, Math.min(1, 1 - km / (stage === 'dropoff' ? 10 : 5)));
      var label = navState.stage === 'dropoff' ? 'Navigate to Drop-off' : 'Navigate to Pickup';
      setNavPanel(navState.stage === 'dropoff' ? '🏁' : '📍', label, km.toFixed(1) + ' km · ETA ' + eta + ' min', true);
      setProgress(frac);
    }, 4000);
  }

  /* ── Styles ── */
  function injectStyles() {
    var css = ''
      + '.dn-panel{display:flex;align-items:center;gap:12px;padding:13px 16px;margin:0 0 12px;border-radius:16px;'
      + 'background:linear-gradient(135deg,rgba(229,199,107,.16),rgba(229,199,107,.03));border:1px solid rgba(229,199,107,.28);position:relative;overflow:hidden}'
      + '.dn-icon{font-size:22px;flex-shrink:0}'
      + '.dn-body{flex:1;min-width:0}'
      + '.dn-label{font-family:Outfit,Poppins,sans-serif;font-size:13px;font-weight:700;color:#EDEDF5;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
      + '.dn-dist{font-family:Outfit,Poppins,sans-serif;font-size:11px;color:#9A9AAE;margin-top:2px}'
      + '.dn-open{flex-shrink:0;padding:7px 12px;border:none;border-radius:10px;cursor:pointer;font-family:Outfit,Poppins,sans-serif;font-size:11px;font-weight:700;'
      + 'color:#1a1206;background:linear-gradient(135deg,#FFE9A8,#D4AF37);transition:transform .15s}'
      + '.dn-open:active{transform:scale(.95)}'
      + '.dn-bar{position:absolute;bottom:0;left:0;right:0;height:2px;background:rgba(255,255,255,.08)}'
      + '.dn-prog{height:100%;background:linear-gradient(90deg,#D4AF37,#2ECC71);border-radius:2px;transition:width .6s ease}';
    var s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);
  }
})();
