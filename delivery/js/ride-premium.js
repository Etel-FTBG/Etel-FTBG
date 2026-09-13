/* ════════════════════════════════════════════════════════════════
   ETEL RIDE — premium map motion (ADD-ON, optional)
   Load AFTER ride.js:  <script src="js/ride-premium.js"></script>
   Adds CSS-3D map markers, smooth driver glide, animated route draw,
   fare count-up, and a driver-found flourish. No edits to ride.js,
   the map config, or the API key. Works on the existing raster map.
   ════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Inject the CSS-3D marker styling once.
  injectStyles();

  // Wait until ride.js has built the Google map.
  var waits = 0;
  (function waitForMap() {
    if (window.RIDE && RIDE.map && window.google && google.maps) return boot();
    if (waits++ < 80) setTimeout(waitForMap, 200);
  })();

  var Marker3D, pins = {}, car = null, carShadow = null, routeAnim = null, prevActiveSearching = false;

  function boot() {
    Marker3D = defineMarker3D();
    startTickLoop();
    syncMarkers();           // mirror RIDE markers → 3D overlays
    setInterval(syncMarkers, 220);
    hookRouteDraw();
    hookFareCountUp();
    hookDriverFound();
  }

  /* ── Custom CSS-3D overlay marker ── */
  function defineMarker3D() {
    function M(opts) {
      this._cur = { lat: opts.position.lat, lng: opts.position.lng };
      this._target = { lat: opts.position.lat, lng: opts.position.lng };
      this._html = opts.html; this._cls = opts.className || ''; this._heading = 0;
      this._div = null; this._smooth = !!opts.smooth;
      this.setMap(RIDE.map);
    }
    M.prototype = Object.create(google.maps.OverlayView.prototype);
    M.prototype.onAdd = function () {
      var d = document.createElement('div');
      d.className = 'm3d ' + this._cls;
      d.innerHTML = this._html;
      d.style.position = 'absolute';
      d.style.willChange = 'transform';
      d.style.transform = 'translate(-50%,-100%)';
      this._div = d;
      this.getPanes().floatPane.appendChild(d);
    };
    M.prototype.draw = function () {
      if (!this._div) return;
      var proj = this.getProjection(); if (!proj) return;
      var p = proj.fromLatLngToDivPixel(new google.maps.LatLng(this._cur.lat, this._cur.lng));
      if (!p) return;
      this._div.style.left = p.x + 'px';
      this._div.style.top = p.y + 'px';
    };
    M.prototype.onRemove = function () {
      if (this._div && this._div.parentNode) this._div.parentNode.removeChild(this._div);
      this._div = null;
    };
    M.prototype.moveTo = function (lat, lng) {
      this._target.lat = lat; this._target.lng = lng;
      if (!this._smooth || reduced) { this._cur.lat = lat; this._cur.lng = lng; this.draw(); }
    };
    // Updates the vehicle image on an already-created marker in place -
    // lets the driver's marker on the CUSTOMER's map reflect a vehicle-
    // type change on the next sync tick, same as the equivalent fix on
    // the driver's own app (driver-premium.js).
    M.prototype.setVehicleImage = function (url) {
      if (!this._div) return;
      var img = this._div.querySelector('.car-img');
      if (img && img.src !== url) img.src = url;
    };
    M.prototype.tick = function () {
      if (!this._smooth || reduced) return;
      var dx = this._target.lng - this._cur.lng, dy = this._target.lat - this._cur.lat;
      if (Math.abs(dx) > 1e-7 || Math.abs(dy) > 1e-7) {
        if (Math.abs(dx) + Math.abs(dy) > 5e-7) {       // update heading when actually travelling
          this._heading = Math.atan2(dx, dy) * 180 / Math.PI;
          var rot = this._div && this._div.querySelector('.m3d-rot');
          if (rot) rot.style.transform = 'rotateX(52deg) rotate(' + this._heading + 'deg)';
        }
        this._cur.lat += dy * 0.14; this._cur.lng += dx * 0.14;
        this.draw();
      }
    };
    return M;
  }

  /* ── single shared animation loop ── */
  var live = [];
  function register(m) { live.push(m); }
  function unregister(m) { var i = live.indexOf(m); if (i >= 0) live.splice(i, 1); }
  function startTickLoop() {
    function loop() {
      if (!document.hidden && !reduced) for (var i = 0; i < live.length; i++) live[i].tick();
      requestAnimationFrame(loop);
    }
    requestAnimationFrame(loop);
  }

  /* ── mirror RIDE.* markers into 3D overlays, hide the flat ones ── */
  function syncMarkers() {
    if (!Marker3D) return;
    // pickup
    syncPin('pickup', RIDE.pickupMarker, pinHTML('pickup'), 'm3d-pin pin-pickup');
    // dropoff
    syncPin('dropoff', RIDE.dropoffMarker, pinHTML('dropoff'), 'm3d-pin pin-dropoff');
    // driver car
    if (RIDE.driverMarker && getPos(RIDE.driverMarker)) {
      var dp = getPos(RIDE.driverMarker);
      try { RIDE.driverMarker.setMap(null); } catch (e) {}
      // Same root cause as the driver app's own self-marker bug: this
      // loop hides RIDE.driverMarker (the one driverIconForVehicleType()
      // in ride.js correctly sets the real vehicle image on) and replaces
      // it with this separate 3D overlay, which had a hardcoded car emoji
      // baked into its HTML template regardless of vehicle type. Fixing
      // ride.js alone could never have worked - THIS is what was actually
      // on screen the whole time.
      var vImg = (typeof RIDE !== 'undefined' && RIDE.vtypeImages)
        ? (RIDE.vtypeImages[(RIDE._driverMarkerVtype || 'bajaj').toLowerCase()] || RIDE.vtypeImages.bajaj)
        : null;
      if (!car) {
        car = new Marker3D({ position: dp, html: carHTML(vImg), className: 'm3d-car', smooth: true });
        register(car);
      } else { car.moveTo(dp.lat, dp.lng); if (vImg) car.setVehicleImage(vImg); }
    } else if (car) { unregister(car); car.setMap(null); car = null; }
  }

  function syncPin(key, nativeMarker, html, cls) {
    var pos = nativeMarker && getPos(nativeMarker);
    if (pos) {
      try { nativeMarker.setMap(null); } catch (e) {}
      if (!pins[key]) { pins[key] = new Marker3D({ position: pos, html: html, className: cls, smooth: false }); }
      else { pins[key].moveTo(pos.lat, pos.lng); }
    } else if (pins[key]) { pins[key].setMap(null); pins[key] = null; }
  }

  function getPos(marker) {
    try { var p = marker.getPosition && marker.getPosition(); return p ? { lat: p.lat(), lng: p.lng() } : null; }
    catch (e) { return null; }
  }

  /* ── marker HTML ── */
  function pinHTML(type) {
    var glyph = type === 'pickup' ? '📍' : '🏁';
    return '<div class="pin-shadow"></div><div class="pin-body"><span class="pin-glyph">' + glyph + '</span></div><div class="pin-ring"></div>';
  }
  function carHTML(vehicleImgUrl) {
    var body = vehicleImgUrl
      ? '<img class="car-img" src="' + vehicleImgUrl + '" alt="">'
      : '🚗'; // no vehicle type resolved yet - emoji fallback only, never the steady-state
    return '<div class="car-shadow"></div><div class="car-scan"></div><div class="m3d-rot"><div class="car-body">' + body + '</div></div>';
  }

  /* ── animated route draw on top of the directions polyline ── */
  function hookRouteDraw() {
    if (!RIDE.directionsRenderer || !RIDE.directionsRenderer.addListener) return;
    RIDE.directionsRenderer.addListener('directions_changed', function () {
      var dirs; try { dirs = RIDE.directionsRenderer.getDirections(); } catch (e) { return; }
      if (!dirs || !dirs.routes || !dirs.routes[0]) return;
      drawAnimatedRoute(dirs.routes[0].overview_path || []);
    });
  }
  function drawAnimatedRoute(path) {
    if (!path.length) return;
    if (routeAnim && routeAnim.line) routeAnim.line.setMap(null);
    if (routeAnim && routeAnim.comet) routeAnim.comet.setMap(null);
    var line = new google.maps.Polyline({
      map: RIDE.map, path: [], strokeColor: '#FFE9A8', strokeOpacity: .95, strokeWeight: 5,
      zIndex: 5
    });
    var comet = new google.maps.Marker({
      map: RIDE.map, position: path[0], zIndex: 6,
      icon: { path: google.maps.SymbolPath.CIRCLE, scale: 5, fillColor: '#fff', fillOpacity: 1, strokeColor: '#E5C76B', strokeWeight: 3 }
    });
    routeAnim = { line: line, comet: comet };
    if (reduced) { line.setPath(path); comet.setMap(null); return; }
    var i = 0, acc = [];
    var step = Math.max(1, Math.round(path.length / 36));
    (function grow() {
      for (var k = 0; k < step && i < path.length; k++, i++) acc.push(path[i]);
      line.setPath(acc);
      comet.setPosition(path[Math.min(i, path.length - 1)]);
      if (i < path.length) requestAnimationFrame(grow);
      else cometRun(path, comet);
    })();
  }
  function cometRun(path, comet) {
    if (reduced) { comet.setMap(null); return; }
    var i = 0;
    (function run() {
      if (!routeAnim || routeAnim.comet !== comet) return;        // route changed → stop
      comet.setPosition(path[i]);
      i = (i + 1) % path.length;
      setTimeout(function () { requestAnimationFrame(run); }, 40);
    })();
  }

  /* ── fare count-up (book + searching) ── */
  function hookFareCountUp() {
    ['fare-amount', 'searching-fare'].forEach(function (id) {
      var el = document.getElementById(id); if (!el) return;
      var mo = new MutationObserver(function () {
        if (el.dataset.animating === '1') return;
        var target = parseFloat((el.textContent || '').replace(/[^0-9.]/g, ''));
        if (isNaN(target)) return;
        var from = parseFloat(el.dataset.shown || '0');
        if (Math.abs(target - from) < 0.01) return;
        countUp(el, from, target);
      });
      mo.observe(el, { childList: true, characterData: true, subtree: true });
    });
  }
  function countUp(el, from, target) {
    if (reduced) { el.dataset.shown = target; return; }
    el.dataset.animating = '1';
    var start = performance.now(), dur = 600;
    function tick(now) {
      var p = Math.min((now - start) / dur, 1), e = 1 - Math.pow(1 - p, 3);
      var v = (from + (target - from) * e);
      el.textContent = 'ETB ' + v.toFixed(2);
      if (p < 1) requestAnimationFrame(tick);
      else { el.textContent = 'ETB ' + target.toFixed(2); el.dataset.shown = target; el.dataset.animating = '0'; }
    }
    requestAnimationFrame(tick);
  }

  /* ── driver-found flourish (searching → active) ── */
  function hookDriverFound() {
    var searching = document.getElementById('view-searching');
    var active = document.getElementById('view-active');
    var sheet = document.querySelector('.ride-sheet');
    if (!searching || !active) return;
    var mo = new MutationObserver(function () {
      if (searching.classList.contains('active')) prevActiveSearching = true;
      if (active.classList.contains('active') && prevActiveSearching) {
        prevActiveSearching = false;
        if (navigator.vibrate && !reduced) navigator.vibrate([18, 40, 22]);
        if (sheet && !reduced) { sheet.classList.remove('found'); void sheet.offsetWidth; sheet.classList.add('found'); }
      }
    });
    mo.observe(searching, { attributes: true, attributeFilter: ['class'] });
    mo.observe(active, { attributes: true, attributeFilter: ['class'] });
  }

  /* ── styles for the 3D markers ── */
  function injectStyles() {
    var css = ''
      + '.m3d{pointer-events:none}'
      + '.m3d-pin{width:0;height:0}'
      + '.pin-body{position:absolute;left:-17px;top:-46px;width:34px;height:34px;border-radius:50% 50% 50% 0;'
      + 'transform:rotate(-45deg);display:flex;align-items:center;justify-content:center;'
      + 'box-shadow:0 6px 14px -3px rgba(0,0,0,.6)}'
      + '.pin-pickup .pin-body{background:linear-gradient(135deg,#FFE9A8,#D4AF37);border:2px solid #fff}'
      + '.pin-dropoff .pin-body{background:linear-gradient(135deg,#9ad2ff,#60B4FF);border:2px solid #fff}'
      + '.pin-glyph{transform:rotate(45deg);font-size:15px;line-height:1}'
      + '.pin-shadow{position:absolute;left:-7px;top:-7px;width:14px;height:6px;border-radius:50%;background:rgba(0,0,0,.45);filter:blur(2px)}'
      + '.pin-ring{position:absolute;left:-16px;top:-16px;width:32px;height:32px;border-radius:50%;border:2px solid #E5C76B;animation:m3dPing 2s ease-out infinite}'
      + '.pin-dropoff .pin-ring{border-color:#60B4FF}'
      + '@keyframes m3dPing{0%{transform:scale(.4);opacity:.7}100%{transform:scale(1.9);opacity:0}}'
      + '.m3d-car{width:0;height:0}'
      // Scaled up ~1.43x from the original 42px to match the size the
      // driver's own app now uses, and all four elements (shadow, scan
      // ring, rotation container, body) moved together since they're
      // positioned with pixel offsets tuned to center around the
      // marker's own size.
      + '.car-shadow{position:absolute;left:-21px;top:-7px;width:43px;height:16px;border-radius:50%;background:rgba(0,0,0,.4);filter:blur(3px)}'
      + '.car-scan{position:absolute;left:-40px;top:-40px;width:80px;height:80px;border-radius:50%;'
      + 'border:2px solid rgba(229,199,107,.7);animation:m3dPing 2.2s ease-out infinite}'
      + '.m3d-rot{position:absolute;left:-30px;top:-48px;width:60px;height:60px;transform:rotateX(52deg);transform-style:preserve-3d}'
      // Now hosts a real vehicle-image <img> (car-img) most of the time -
      // the gold circle background here was designed for the emoji
      // fallback and looks wrong (a mismatched colored disc) behind an
      // actual vehicle photo, so drop the fill/border and just keep a
      // drop-shadow for depth.
      + '.car-body{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;'
      + 'filter:drop-shadow(0 10px 12px rgba(0,0,0,.45))}'
      + '.car-img{width:60px;height:60px;object-fit:contain}';
    var s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);
  }

  // expose for debugging / manual control
  window.RidePremium = { redrawMarkers: function () { try { syncMarkers(); } catch (e) {} } };
})();
