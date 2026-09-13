/* ════════════════════════════════════════════════════════════════
   ETEL DRIVER — premium map motion (ADD-ON, optional)
   Load AFTER driver.js:  <script src="js/driver-premium.js"></script>
   Adds CSS-3D map markers (3D "you" car with smooth glide, 3D request
   pins), animated route draw, earnings count-up and haptics.
   No edits to driver.js, the map config, or the API key.
   ════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  injectStyles();
  earningsCountUp();
  wireHaptics();

  var waits = 0;
  (function waitForMap() {
    if (window.DRV && DRV.map && window.google && google.maps) return boot();
    if (waits++ < 80) setTimeout(waitForMap, 200);
  })();

  var Marker3D, self3d = null, reqPins = [], live = [], routeAnim = null;

  function boot() {
    Marker3D = defineMarker3D();
    startTickLoop();
    syncMarkers();
    setInterval(syncMarkers, 220);
    hookRouteDraw();
  }

  /* ── CSS-3D OverlayView marker ── */
  function defineMarker3D() {
    function M(opts) {
      this._cur = { lat: opts.position.lat, lng: opts.position.lng };
      this._target = { lat: opts.position.lat, lng: opts.position.lng };
      this._html = opts.html; this._cls = opts.className || ''; this._smooth = !!opts.smooth; this._heading = 0; this._div = null;
      this.setMap(DRV.map);
    }
    M.prototype = Object.create(google.maps.OverlayView.prototype);
    M.prototype.onAdd = function () {
      var d = document.createElement('div'); d.className = 'm3d ' + this._cls; d.innerHTML = this._html;
      d.style.position = 'absolute'; d.style.willChange = 'transform';
      this._div = d; this.getPanes().floatPane.appendChild(d);
    };
    M.prototype.draw = function () {
      if (!this._div) return; var proj = this.getProjection(); if (!proj) return;
      var p = proj.fromLatLngToDivPixel(new google.maps.LatLng(this._cur.lat, this._cur.lng)); if (!p) return;
      this._div.style.left = p.x + 'px'; this._div.style.top = p.y + 'px';
    };
    M.prototype.onRemove = function () { if (this._div && this._div.parentNode) this._div.parentNode.removeChild(this._div); this._div = null; };
    M.prototype.moveTo = function (lat, lng) { this._target.lat = lat; this._target.lng = lng; if (!this._smooth || reduced) { this._cur.lat = lat; this._cur.lng = lng; this.draw(); } };
    // Updates the vehicle image on an already-created marker in place,
    // without tearing down and recreating the whole 3D overlay - lets
    // the self-marker reflect a vehicle-type change (Update Vehicle
    // Info -> Save) on the very next sync tick instead of only ever
    // being set once at marker-creation time.
    M.prototype.setVehicleImage = function (url) {
        if (!this._div) return;
        var img = this._div.querySelector('.car-img');
        if (img && img.src !== url) img.src = url;
    };
    M.prototype.tick = function () {
      if (!this._smooth || reduced) return;
      var dx = this._target.lng - this._cur.lng, dy = this._target.lat - this._cur.lat;
      if (Math.abs(dx) > 1e-7 || Math.abs(dy) > 1e-7) {
        if (Math.abs(dx) + Math.abs(dy) > 5e-7) {
          this._heading = Math.atan2(dx, dy) * 180 / Math.PI;
          var rot = this._div && this._div.querySelector('.m3d-rot');
          if (rot) rot.style.transform = 'rotateX(52deg) rotate(' + this._heading + 'deg)';
        }
        this._cur.lat += dy * 0.14; this._cur.lng += dx * 0.14; this.draw();
      }
    };
    return M;
  }

  function startTickLoop() {
    function loop() { if (!document.hidden && !reduced) for (var i = 0; i < live.length; i++) live[i].tick(); requestAnimationFrame(loop); }
    requestAnimationFrame(loop);
  }
  function register(m) { live.push(m); }
  function unregister(m) { var i = live.indexOf(m); if (i >= 0) live.splice(i, 1); }

  function getPos(marker) {
    try { var p = marker.getPosition && marker.getPosition(); return p ? { lat: p.lat(), lng: p.lng() } : null; } catch (e) { return null; }
  }

  /* ── mirror DRV markers → 3D overlays ── */
  function syncMarkers() {
    if (!Marker3D) return;
    // self (the driver)
    if (DRV.selfMarker && getPos(DRV.selfMarker)) {
      var sp = getPos(DRV.selfMarker);
      try { DRV.selfMarker.setMap(null); } catch (e) {}
      // Real root cause of the self-marker never reflecting the driver's
      // actual vehicle type: this loop runs every ~220ms and unconditionally
      // hides DRV.selfMarker (the one updateSelfMarker()/refreshSelfMarkerIcon()
      // in driver.js correctly set the vehicle image on), replacing it with
      // this separate 3D overlay - which had a hardcoded car emoji baked into
      // its HTML template regardless of vehicle type. Fixing driver.js alone
      // could never have worked; THIS is what was actually on screen the
      // whole time.
      var vImg = (typeof _driverVtypeImage === 'function') ? _driverVtypeImage() : null;
      if (!self3d) { self3d = new Marker3D({ position: sp, html: carHTML(vImg), className: 'm3d-car', smooth: true }); register(self3d); }
      else { self3d.moveTo(sp.lat, sp.lng); if (vImg) self3d.setVehicleImage(vImg); }
    } else if (self3d) { unregister(self3d); self3d.setMap(null); self3d = null; }

    // ride request pins (array)
    var markers = (DRV.rideMarkers || []).filter(function (m) { return getPos(m); });
    // remove stale 3D pins
    while (reqPins.length > markers.length) { var old = reqPins.pop(); if (old) old.setMap(null); }
    markers.forEach(function (m, i) {
      var pos = getPos(m); if (!pos) return;
      try { m.setMap(null); } catch (e) {}
      if (!reqPins[i]) reqPins[i] = new Marker3D({ position: pos, html: pinHTML(), className: 'm3d-pin pin-pickup', smooth: false });
      else reqPins[i].moveTo(pos.lat, pos.lng);
    });
  }

  function pinHTML() { return '<div class="pin-shadow"></div><div class="pin-body"><span class="pin-glyph">🧑</span></div><div class="pin-ring"></div>'; }
  function carHTML(vehicleImgUrl) {
    var body = vehicleImgUrl
      ? '<img class="car-img" src="' + vehicleImgUrl + '" alt="">'
      : '🚗'; // no vehicle type resolved yet (profile still loading) - emoji fallback only, never the steady-state
    return '<div class="car-shadow"></div><div class="car-scan"></div><div class="m3d-rot"><div class="car-body">' + body + '</div></div>';
  }

  /* ── animated route draw over directions polyline ── */
  function hookRouteDraw() {
    if (!DRV.directionsRenderer || !DRV.directionsRenderer.addListener) return;
    DRV.directionsRenderer.addListener('directions_changed', function () {
      var dirs; try { dirs = DRV.directionsRenderer.getDirections(); } catch (e) { return; }
      if (!dirs || !dirs.routes || !dirs.routes[0]) { if (routeAnim) clearRoute(); return; }
      drawAnimatedRoute(dirs.routes[0].overview_path || []);
    });
  }
  function clearRoute() { if (routeAnim) { if (routeAnim.line) routeAnim.line.setMap(null); if (routeAnim.comet) routeAnim.comet.setMap(null); routeAnim = null; } }
  function drawAnimatedRoute(path) {
    if (!path.length) return;
    clearRoute();
    var line = new google.maps.Polyline({ map: DRV.map, path: [], strokeColor: '#FFE9A8', strokeOpacity: .95, strokeWeight: 5, zIndex: 5 });
    var comet = new google.maps.Marker({ map: DRV.map, position: path[0], zIndex: 6,
      icon: { path: google.maps.SymbolPath.CIRCLE, scale: 5, fillColor: '#fff', fillOpacity: 1, strokeColor: '#E5C76B', strokeWeight: 3 } });
    routeAnim = { line: line, comet: comet };
    if (reduced) { line.setPath(path); comet.setMap(null); return; }
    var i = 0, acc = [], step = Math.max(1, Math.round(path.length / 36));
    (function grow() {
      for (var k = 0; k < step && i < path.length; k++, i++) acc.push(path[i]);
      line.setPath(acc); comet.setPosition(path[Math.min(i, path.length - 1)]);
      if (i < path.length) requestAnimationFrame(grow); else cometRun(path, comet);
    })();
  }
  function cometRun(path, comet) {
    if (reduced) { comet.setMap(null); return; }
    var i = 0;
    (function run() { if (!routeAnim || routeAnim.comet !== comet) return; comet.setPosition(path[i]); i = (i + 1) % path.length; setTimeout(function () { requestAnimationFrame(run); }, 40); })();
  }

  /* ── earnings count-up (driver.js sets these) ── */
  function earningsCountUp() {
    [['earn-today', 'ETB '], ['earn-rides', ''], ['earn-rating', '']].forEach(function (pair) {
      var el = document.getElementById(pair[0]); if (!el) return;
      var prefix = pair[1];
      var mo = new MutationObserver(function () {
        if (el.dataset.anim === '1') return;
        var raw = (el.textContent || ''); var num = parseFloat(raw.replace(/[^0-9.]/g, '')); if (isNaN(num)) return;
        var suffix = raw.indexOf('⭐') >= 0 ? ' ⭐' : '';
        var dec = (pair[0] === 'earn-rating') ? 1 : 0;
        var from = parseFloat(el.dataset.shown || '0');
        if (Math.abs(num - from) < (dec ? 0.05 : 1)) return;
        count(el, from, num, prefix, suffix, dec);
      });
      mo.observe(el, { childList: true, characterData: true, subtree: true });
    });
  }
  function count(el, from, to, prefix, suffix, dec) {
    if (reduced) { el.dataset.shown = to; return; }
    el.dataset.anim = '1';
    var start = performance.now(), dur = 600;
    function tick(now) {
      var p = Math.min((now - start) / dur, 1), e = 1 - Math.pow(1 - p, 3), v = from + (to - from) * e;
      el.textContent = prefix + (dec ? v.toFixed(1) : Math.round(v)) + suffix;
      if (p < 1) requestAnimationFrame(tick);
      else { el.textContent = prefix + (dec ? to.toFixed(1) : Math.round(to)) + suffix; el.dataset.shown = to; el.dataset.anim = '0'; }
    }
    requestAnimationFrame(tick);
  }

  /* ── haptics on accept / action / online buttons ── */
  function wireHaptics() {
    if (!navigator.vibrate || reduced) return;
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('.req-accept-btn, .action-btn, .online-toggle');
      if (b) navigator.vibrate(15);
    }, true);
  }

  /* ── 3D marker styles ── */
  function injectStyles() {
    var css = ''
      + '.m3d{pointer-events:none}'
      + '.m3d-pin{width:0;height:0}'
      + '.pin-body{position:absolute;left:-17px;top:-46px;width:34px;height:34px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#FFE9A8,#D4AF37);border:2px solid #fff;box-shadow:0 6px 14px -3px rgba(0,0,0,.6)}'
      + '.pin-glyph{transform:rotate(45deg);font-size:15px;line-height:1}'
      + '.pin-shadow{position:absolute;left:-7px;top:-7px;width:14px;height:6px;border-radius:50%;background:rgba(0,0,0,.45);filter:blur(2px)}'
      + '.pin-ring{position:absolute;left:-16px;top:-16px;width:32px;height:32px;border-radius:50%;border:2px solid #E5C76B;animation:m3dPing 2s ease-out infinite}'
      + '@keyframes m3dPing{0%{transform:scale(.4);opacity:.7}100%{transform:scale(1.9);opacity:0}}'
      + '.m3d-car{width:0;height:0}'
      // Scaled up ~1.43x from the original 42px (shadow, scan-ring, and
      // rotation container all use pixel offsets tuned to center around
      // the marker's own size, so all four had to move together - just
      // enlarging car-img/car-body alone would have overflowed the
      // rotation container and thrown off the shadow/ring positioning).
      + '.car-shadow{position:absolute;left:-21px;top:-7px;width:43px;height:16px;border-radius:50%;background:rgba(0,0,0,.4);filter:blur(3px)}'
      + '.car-scan{position:absolute;left:-40px;top:-40px;width:80px;height:80px;border-radius:50%;border:2px solid rgba(46,204,113,.7);animation:m3dPing 2.2s ease-out infinite}'
      + '.m3d-rot{position:absolute;left:-30px;top:-48px;width:60px;height:60px;transform:rotateX(52deg);transform-style:preserve-3d}'
      // Now hosts a real vehicle-image <img> (car-img) most of the time -
      // the gold circle background here was designed for the emoji
      // fallback and looks wrong (a mismatched colored disc) behind an
      // actual vehicle photo, so drop the fill/border and just keep a
      // drop-shadow for depth.
      + '.car-body{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;filter:drop-shadow(0 10px 12px rgba(0,0,0,.45))}'
      + '.car-img{width:60px;height:60px;object-fit:contain}';
    var s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);
  }

  window.DriverPremium = { redraw: function () { try { syncMarkers(); } catch (e) {} } };
})();
