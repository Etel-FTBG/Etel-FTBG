/* ============================================================
   ETEL Ride — Real Hex-Grid "Searching for Driver" Visualization
   ------------------------------------------------------------
   Replaces the purely decorative 4-static-emoji "nearby cars"
   placeholder with a genuine, data-driven visualization: real hex
   cells drawn on the map (same axial math as api/lib/hexgrid.php,
   same origin/cell size, so what's drawn actually corresponds to how
   the backend groups nearby drivers - not just decoration), with
   real nearby driver positions plotted inside them via
   passenger_get_nearby_drivers (already hex-optimized).

   Hooks into switchRideTab (confirmed globally accessible - assigned
   without var/let/const in the original bundle) rather than editing
   the minified bundle directly: wraps the original, preserving all
   existing behavior, and starts/stops this overlay based on whether
   the "searching" view is being shown.
   ============================================================ */

(function () {
  // Same constants as api/lib/hexgrid.php - kept numerically identical
  // so a cell drawn here actually corresponds to the same cell the
  // backend groups drivers into, not just a similar-looking decoration.
  const HEX_ORIGIN_LAT = 7.0621;
  const HEX_ORIGIN_LNG = 38.4761;
  const HEX_CELL_SIZE_M = 350.0;
  const EARTH_R = 6371000.0;

  function latLngToXY(lat, lng) {
    const lat0 = HEX_ORIGIN_LAT * Math.PI / 180;
    const x = (lng - HEX_ORIGIN_LNG) * Math.PI / 180 * Math.cos(lat0) * EARTH_R;
    const y = (lat - HEX_ORIGIN_LAT) * Math.PI / 180 * EARTH_R;
    return [x, y];
  }

  function axialRound(q, r) {
    let x = q, z = r, y = -x - z;
    let rx = Math.round(x), ry = Math.round(y), rz = Math.round(z);
    const xDiff = Math.abs(rx - x), yDiff = Math.abs(ry - y), zDiff = Math.abs(rz - z);
    if (xDiff > yDiff && xDiff > zDiff) rx = -ry - rz;
    else if (yDiff > zDiff) ry = -rx - rz;
    else rz = -rx - ry;
    return [rx, rz];
  }

  function cellFor(lat, lng, size) {
    size = size || HEX_CELL_SIZE_M;
    const [x, y] = latLngToXY(lat, lng);
    const q = (2.0 / 3.0 * x) / size;
    const r = (-1.0 / 3.0 * x + Math.sqrt(3) / 3.0 * y) / size;
    return axialRound(q, r);
  }

  // Center point (in local x/y meters) of a given axial hex cell -
  // the inverse of cellFor's projection, needed here (unlike the PHP
  // backend, which never draws anything) so we can actually render
  // the hexagon's boundary on the map.
  function hexCenterXY(q, r, size) {
    size = size || HEX_CELL_SIZE_M;
    const x = size * (3.0 / 2.0 * q);
    const y = size * (Math.sqrt(3) / 2.0 * q + Math.sqrt(3) * r);
    return [x, y];
  }

  function xyToLatLng(x, y) {
    const lat0 = HEX_ORIGIN_LAT * Math.PI / 180;
    const lng = HEX_ORIGIN_LNG + (x / (Math.cos(lat0) * EARTH_R)) * 180 / Math.PI;
    const lat = HEX_ORIGIN_LAT + (y / EARTH_R) * 180 / Math.PI;
    return { lat, lng };
  }

  // The 6 corner points of a flat-top hexagon at cell (q, r), as
  // google.maps.LatLng-compatible {lat, lng} objects, for drawing a
  // real Polygon outline.
  function hexCornersLatLng(q, r, size) {
    size = size || HEX_CELL_SIZE_M;
    const [cx, cy] = hexCenterXY(q, r, size);
    const corners = [];
    for (let i = 0; i < 6; i++) {
      const angleDeg = 60 * i;
      const angleRad = Math.PI / 180 * angleDeg;
      const px = cx + size * Math.cos(angleRad);
      const py = cy + size * Math.sin(angleRad);
      corners.push(xyToLatLng(px, py));
    }
    return corners;
  }

  function neighborsWithin(q, r, k) {
    const out = [];
    for (let dq = -k; dq <= k; dq++) {
      const rMin = Math.max(-k, -dq - k);
      const rMax = Math.min(k, -dq + k);
      for (let dr = rMin; dr <= rMax; dr++) out.push([q + dq, r + dr]);
    }
    return out;
  }

  function apiBase() { return (window.ETEL && ETEL.api) ? ETEL.api : '/delivery/api/boot.php'; }
  function authToken() {
    try { return (typeof Auth !== 'undefined' && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); }
    catch (e) { return ''; }
  }

  let hexPolygons = [];
  let driverMarkers = [];
  let pulsePhase = 0;
  let pulseTimer = null;
  let refreshTimer = null;
  let overlayActive = false;

  function clearOverlay() {
    hexPolygons.forEach(p => { try { p.setMap(null); } catch (e) {} });
    hexPolygons = [];
    driverMarkers.forEach(m => { try { m.setMap(null); } catch (e) {} });
    driverMarkers = [];
    if (pulseTimer) { clearInterval(pulseTimer); pulseTimer = null; }
    if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
  }

  function drawHexGrid(centerLat, centerLng) {
    if (!window.RIDE || !RIDE.map || typeof google === 'undefined') return;
    const [q0, r0] = cellFor(centerLat, centerLng);
    // 4 rings (~2.1km radius) instead of the original 2 (~1.4km) - closer
    // to the actual 3km radius passenger_get_nearby_drivers searches
    // (refreshNearbyDrivers below requests radius_km=3), so a genuinely
    // matched nearby driver doesn't visually appear to be "outside the
    // search area" the way a driver ~1.9km away could with the old,
    // noticeably smaller decorative patch. Still intentionally short of
    // the full 3km - this is about giving an honest-*feeling* sense of
    // the search neighborhood without drawing ~127 polygons (what a
    // true 6-ring/~3km grid would need) and the render/pulse-animation
    // cost that comes with it on lower-end phones.
    const cells = neighborsWithin(q0, r0, 4);
    cells.forEach(([q, r]) => {
      const corners = hexCornersLatLng(q, r);
      const poly = new google.maps.Polygon({
        paths: corners,
        strokeColor: '#D4AF37',
        strokeOpacity: 0.55,
        strokeWeight: 1.5,
        fillColor: '#D4AF37',
        fillOpacity: 0.06,
        map: RIDE.map,
        clickable: false,
        zIndex: 1,
      });
      poly._isCenter = (q === q0 && r === r0);
      hexPolygons.push(poly);
    });
    // Gentle pulsing glow, staggered per-cell by distance from center
    // so the effect ripples outward rather than blinking uniformly -
    // matches the "actively searching" feel without being distracting.
    pulsePhase = 0;
    pulseTimer = setInterval(() => {
      pulsePhase += 0.12;
      hexPolygons.forEach((poly, i) => {
        const offset = i * 0.35;
        const wave = (Math.sin(pulsePhase + offset) + 1) / 2; // 0..1
        poly.setOptions({
          fillOpacity: 0.04 + wave * 0.10,
          strokeOpacity: 0.35 + wave * 0.35,
        });
      });
    }, 90);
  }

  function refreshNearbyDrivers(centerLat, centerLng) {
    const token = authToken();
    const url = apiBase() + '?action=passenger_get_nearby_drivers&lat=' + encodeURIComponent(centerLat)
      + '&lng=' + encodeURIComponent(centerLng) + '&radius_km=3';
    fetch(url, { headers: { Authorization: 'Bearer ' + token } })
      .then(r => r.json())
      .then(res => {
        if (!overlayActive || !res || !res.success) return;
        const drivers = (res.data && res.data.drivers) || [];
        // Rebuild markers each refresh - simplest correct approach
        // given this overlay only runs for the few seconds a rider is
        // actively watching the searching screen, not worth the extra
        // complexity of diffing/animating individual marker moves here.
        driverMarkers.forEach(m => { try { m.setMap(null); } catch (e) {} });
        driverMarkers = [];
        drivers.slice(0, 24).forEach(d => {
          if (!window.google || !google.maps || !RIDE.map) return;
          const el = document.createElement('div');
          el.style.cssText = 'font-size:32px;opacity:0;transition:opacity .5s ease;filter:drop-shadow(0 3px 6px rgba(0,0,0,.5))';
          /* Was rendering a plain emoji character here via vehicleEmoji() -
             a completely separate, cruder icon system from the one ride.js
             already uses for the pre-booking nearby-driver markers
             (driverIconForVehicleType(), the real branded vehicle photos in
             RIDE.vtypeImages). That mismatch is exactly why the marker
             looked right before booking and switched to a generic icon
             the moment "Finding your driver" took over - two disconnected
             renderers for what should look like the same driver marker.
             vehicleEmoji() also had its own bug: the 'qute' branch
             returned the identical tuk-tuk emoji as the bajaj default,
             so a Qute driver's marker was indistinguishable from a Bajaj
             one here even before this fix. Use the same real vehicle
             image RIDE.vtypeImages already provides instead - one
             consistent, correctly-typed icon everywhere on the map. */
          const imgUrl = vehicleImageUrl(d.vehicle_type);
          if (imgUrl) {
            const img = document.createElement('img');
            img.src = imgUrl;
            img.style.cssText = 'width:56px;height:56px;object-fit:contain';
            img.onerror = function () { el.textContent = vehicleEmoji(d.vehicle_type); };
            el.appendChild(img);
          } else {
            el.textContent = vehicleEmoji(d.vehicle_type);
          }
          // Classic google.maps.Marker only. The ride map is created WITHOUT a Cloud mapId on
          // purpose (a mapId makes Google ignore our dark/light `styles`), and an
          // AdvancedMarkerElement on a map with no valid mapId logs "initialized without a valid
          // Map ID" on every refresh and may not draw at all.
          const marker = new google.maps.Marker({
            position: { lat: d.lat, lng: d.lng }, map: RIDE.map,
            icon: imgUrl ? { url: imgUrl, scaledSize: new google.maps.Size(56, 56), anchor: new google.maps.Point(28, 28) }
                         : { path: google.maps.SymbolPath.CIRCLE, scale: 0.01, },
            label: imgUrl ? undefined : { text: vehicleEmoji(d.vehicle_type), fontSize: '28px' },
            zIndex: 5,
          });
          driverMarkers.push(marker);
          // Fade in - purely cosmetic polish, the marker/data itself
          // is already real by this point.
          setTimeout(() => { try { el.style.opacity = '1'; } catch (e) {} }, 30);
        });
      })
      .catch(() => { /* silent - this is a decorative-but-real overlay, not the actual matching request itself */ });
  }

  function vehicleImageUrl(vtype) {
    // Reuse the exact same real vehicle photos ride.js's own
    // driverIconForVehicleType() uses for pre-booking markers, so the
    // driver's marker doesn't visually change the moment this overlay
    // takes over on the searching screen.
    if (!window.RIDE || !RIDE.vtypeImages) return null;
    var t = (vtype || '').toLowerCase();
    if (t.indexOf('moto') !== -1 || t.indexOf('bike') !== -1 || t.indexOf('motor') !== -1) return RIDE.vtypeImages.moto;
    if (t.indexOf('mini') !== -1 || t.indexOf('van') !== -1 || t.indexOf('bus') !== -1) return RIDE.vtypeImages.minibus;
    if (t.indexOf('car') !== -1 || t.indexOf('sedan') !== -1) return RIDE.vtypeImages.car;
    if (t.indexOf('qute') !== -1) return RIDE.vtypeImages.qute;
    return RIDE.vtypeImages.bajaj;
  }

  function vehicleEmoji(vtype) {
    // Fallback only, used if RIDE.vtypeImages is unavailable or an image
    // 404s - not the primary rendering path anymore (see vehicleImageUrl).
    const v = (vtype || '').toLowerCase();
    if (v.indexOf('moto') >= 0) return '🛵';
    if (v.indexOf('car') >= 0) return '🚗';
    if (v.indexOf('minibus') >= 0) return '🚐';
    // BUG FIX: this used to also return '🛺' for qute, identical to the
    // bajaj default below - a Qute driver's marker was indistinguishable
    // from a Bajaj one on this fallback path. Qute's real branded icon
    // (RIDE.vtypeImages.qute) is a car, so '🚙' is the closer emoji stand-in.
    if (v.indexOf('qute') >= 0) return '🚙';
    return '🛺'; // bajaj default, matches the app's existing placeholder icon
  }

  function fetchActiveRidePickup(callback) {
    const token = authToken();
    fetch(apiBase() + '?action=my_active_ride', { headers: { Authorization: 'Bearer ' + token } })
      .then(r => r.json())
      .then(res => {
        if (res && res.success && res.data && res.data.pickup_lat && res.data.pickup_lng) {
          callback({ lat: parseFloat(res.data.pickup_lat), lng: parseFloat(res.data.pickup_lng) });
        } else {
          callback(null);
        }
      })
      .catch(() => callback(null));
  }

  function startHexSearchOverlay() {
    if (overlayActive) return;
    if (!window.RIDE || !RIDE.map) return;
    // RIDE.pickupLatLng reflects the Book screen's current form input -
    // many apps (this one included) clear that right after a successful
    // booking as part of resetting the form for next time. That's
    // exactly the moment this overlay is supposed to activate, so
    // relying on it here was the actual bug: the guard correctly (but
    // unhelpfully) refused to start once the very state it needed had
    // just been wiped. The active ride's own pickup_lat/pickup_lng -
    // real, persisted data tied to the ride that was actually created -
    // is a reliable fallback that survives the form reset.
    if (RIDE.pickupLatLng) {
      overlayActive = true;
      const { lat, lng } = RIDE.pickupLatLng;
      drawHexGrid(lat, lng);
      refreshNearbyDrivers(lat, lng);
      refreshTimer = setInterval(() => refreshNearbyDrivers(lat, lng), 4000);
    } else {
      fetchActiveRidePickup(coords => {
        if (!coords || overlayActive) return;
        overlayActive = true;
        drawHexGrid(coords.lat, coords.lng);
        refreshNearbyDrivers(coords.lat, coords.lng);
        refreshTimer = setInterval(() => refreshNearbyDrivers(coords.lat, coords.lng), 4000);
      });
    }
  }

  function stopHexSearchOverlay() {
    overlayActive = false;
    clearOverlay();
  }

  // ride-bundle.js (which defines switchRideTab) now loads
  // asynchronously via release-loader.js - wrapping window.switchRideTab
  // immediately here would risk capturing undefined if this script
  // runs before the bundle finishes. Wait for the same
  // etel:bundle-ready event the app already uses elsewhere for this
  // exact problem (see the loadGoogleMaps call in ride/index.html)
  // rather than assuming synchronous load order.
  function installHooks() {
    const _originalSwitchRideTab = window.switchRideTab;
    window.switchRideTab = function (tab) {
      if (typeof _originalSwitchRideTab === 'function') _originalSwitchRideTab(tab);
      if (tab === 'searching') startHexSearchOverlay();
      else stopHexSearchOverlay();
    };
  }
  if (typeof window.switchRideTab === 'function') {
    installHooks(); // bundle already loaded (e.g. this script re-runs after a hot reload)
  } else {
    window.addEventListener('etel:bundle-ready', installHooks, { once: true });
  }

  // The searching view can also be entered without going through
  // switchRideTab directly (e.g. K('searching') called internally
  // after a successful booking, not via the tab-click path) - poll
  // briefly for the view actually becoming visible so the overlay
  // still activates in that case, without needing to touch the
  // minified bundle's internal booking-success code path at all.
  let lastKnownVisible = false;
  setInterval(() => {
    const el = document.getElementById('view-searching');
    const isVisible = !!(el && el.style.display !== 'none' && el.offsetParent !== null);
    if (isVisible && !lastKnownVisible) startHexSearchOverlay();
    else if (!isVisible && lastKnownVisible) stopHexSearchOverlay();
    lastKnownVisible = isVisible;
  }, 800);
})();
