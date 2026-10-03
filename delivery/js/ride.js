/* ════════════════════════════════════════════════════════════════
   ETEL RIDE — core application logic
   ride.js  ·  loaded before ride-fix.js
   Provides: initRideMap, switchRideTab, doLogout, bookRide,
             cancelRide, checkActiveRide, selectPayment,
             setStar, submitRating, skipRating,
             setPickup, setDropoff, calcRouteIfReady
   ════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── Shared state ── */
  window.RIDE = {
    map: null,
    directionsService: null, directionsRenderer: null,
    pickupMarker: null, dropoffMarker: null, driverMarker: null,
    pickupLatLng: null, dropoffLatLng: null,
    currentRequestId: null, currentRideId: null,
    pollInterval: null, searchTimer: null, searchSecs: 0, sseSource: null,
    selectedPayment: 'cash', selectedRating: 0,
    selectedVtype: 'bajaj', activeRideData: null,
    geocoder: null,
    autocompletePickup: null, autocompleteDropoff: null,
    // Last computed route distance/duration, kept so switching vehicle
    // type (Bajaj/Moto/Car) can recompute the fare without re-running
    // the whole route/directions lookup - see refreshFareDisplay().
    lastRouteKm: null, lastRouteDurLabel: null, lastRouteIsManual: false
  };

  // Same absolute fallback numbers api/lib/ride_fare.php uses if the
  // ride_fare_rules table is ever missing a row - used here only for an
  // instant, optimistic estimate while the authoritative server number
  // (which respects admin-edited rates and peak-hour surge) loads just
  // behind it. If an admin changes these in the panel, only the server
  // number will reflect it until this fallback table is updated too -
  // that's an acceptable, brief inconsistency for a same-second replace,
  // not a live pricing source.
  var FARE_FALLBACK_RATES = {
    bajaj:   { base: 80.00,  perKm: 30.00, perKmOpen: 30.00, minFare: 120.00, minFareShort: 150.00 },
    moto:    { base: 100.00, perKm: 30.00, perKmOpen: 34.50, minFare: 120.00, minFareShort: 150.00 },
    car:     { base: 150.00, perKm: 35.00, perKmOpen: 45.00, minFare: 200.00, minFareShort: 200.00 },
    minibus: { base: 156.00, perKm: 41.60, perKmOpen: 39.00, minFare: 120.00, minFareShort: 150.00 },
    qute:    { base: 120.00, perKm: 30.00, perKmOpen: 30.00, minFare: 120.00, minFareShort: 150.00 }
    // Synced 2026-08-24 against the live admin Pricing panel (Rides ->
    // Pricing -> "Ride fare rules (per vehicle type)"). Bajaj/Moto/Car had
    // drifted from real DB rates after an earlier pricing change in the
    // panel wasn't mirrored here; Qute was missing entirely. Minibus was
    // already correct. Re-sync this table any time rates are edited in
    // that panel - there's no automatic link between the two.
  };
  function clientFareEstimate(km, vtype, isOpenTrip) {
    var r = FARE_FALLBACK_RATES[vtype] || FARE_FALLBACK_RATES.bajaj;
    var perKm = isOpenTrip ? r.perKmOpen : r.perKm;
    var f = r.base + perKm * km;
    var floor = isOpenTrip ? r.minFare : (km <= 3 ? r.minFareShort : r.minFare);
    return Math.max(floor, Math.round(f * 100) / 100);
  }
  function isPeakHourNow() {
    // Mirrors the peak-hour window in api/lib/ride_fare.php's
    // rides_calc_fare() exactly, so the optimistic client-side estimate
    // (shown instantly, before the server round-trip) already gets the
    // surge badge right most of the time. JS Date.getDay() and PHP's
    // date('N') happen to agree on Friday=5/Saturday=6, so no remapping
    // needed.
    var d = new Date(); var h = d.getHours(); var dow = d.getDay();
    return (h >= 6 && h < 9) || (h >= 16 && h < 20) || ((dow === 5 || dow === 6) && h >= 19);
  }
  function renderFareAmount(fare, km, distLabel, surge) {
    var f = parseFloat(fare).toFixed(2);
    var fa = document.getElementById('fare-amount'); if (fa) fa.textContent = 'ETB ' + f;
    var fd = document.getElementById('fare-dist');   if (fd) fd.textContent = km.toFixed(1) + ' km' + (distLabel ? ' · ' + distLabel : '');
    var fc = document.getElementById('fare-card');   if (fc) fc.style.display = 'flex';
    var sf = document.getElementById('searching-fare'); if (sf) sf.textContent = 'ETB ' + f;
    var sd = document.getElementById('searching-dist'); if (sd) sd.textContent = km.toFixed(1) + ' km';
    var sb = document.getElementById('fare-surge-badge');
    if (sb) sb.style.display = surge ? 'inline-flex' : 'none';
  }
  // Recomputes and displays the fare for whatever route/km is currently
  // known, for the currently-selected vehicle type. Called after a route
  // resolves, after a manual km is set, AND after switching vehicle type
  // (selectVtype below) - previously that last case never happened, so
  // Bajaj/Moto/Car all showed the exact same number.
  window.refreshFareDisplay = function () {
    var km = RIDE.lastRouteKm;
    if (km === null || km === undefined) return;
    var vtype = RIDE.selectedVtype || 'bajaj';
    var isOpenTrip = !!RIDE.lastRouteIsManual;
    var distLabel = isOpenTrip ? 'by distance' : RIDE.lastRouteDurLabel;

    // Instant optimistic estimate - zero network lag, so the fare card
    // always feels responsive even on a slow connection.
    renderFareAmount(clientFareEstimate(km, vtype, isOpenTrip), km, distLabel, isPeakHourNow());

    fetch(apiBase() + '?action=ride_estimate_fare&distance_km=' + encodeURIComponent(km) +
          '&vehicle_type=' + encodeURIComponent(vtype) + (isOpenTrip ? '&open_trip=1' : ''),
          { headers: authHeader() })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        // Guard against a slow response landing after the user already
        // changed vtype/km again - never overwrite newer state with a
        // stale one.
        if (res && res.success && RIDE.lastRouteKm === km && RIDE.selectedVtype === vtype) {
          renderFareAmount(res.data.fare, km, distLabel, !!res.data.surge);
          // PRICE-LOCK: remember the signed quote token so bookRide() can
          // send it back and get the exact fare the customer was shown,
          // instead of book_ride recomputing live (which can differ if a
          // surge window starts in the gap between quote and confirm).
          RIDE.quoteToken = res.data.quote_token || null;
          RIDE.quoteTokenKm = res.data.distance_km;
          RIDE.quoteTokenVtype = res.data.vehicle_type;
        }
      })
      .catch(function () { /* optimistic estimate above already shown - fine offline */ });
  };

  /* ── API base ── */
  function apiBase() {
    return (window.ETEL && ETEL.api) ? ETEL.api : '/delivery/api/boot.php';
  }
  function authHeader() {
    var t = '';
    try { t = Auth.getToken ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) {}
    return t ? { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + t } : { 'Content-Type': 'application/json' };
  }

  /* ════════════ VEHICLE TYPE ════════════ */
  window.selectVtype = function (el, vtype) {
    RIDE.selectedVtype = vtype;
    document.querySelectorAll('.vtype-chip').forEach(function (c) { c.classList.remove('active'); });
    if (el) el.classList.add('active');
    var badges = { bajaj: 'Bajaj', moto: 'Moto', car: 'Car', minibus: 'Minibus', qute: 'Qute' };
    var fb = document.getElementById('fare-type-badge');
    if (fb) fb.textContent = badges[vtype] || 'Ride';
    refreshFareDisplay();
    if (RIDE.driverMapFilterMode !== 'all') loadNearbyDrivers();
  };

  /* ════════════ MAP INIT ════════════ */
  window.initRideMap = function () {
    var _rm = document.getElementById('ride-map');
    /* iOS Safari: vh inside fixed containers is unreliable.
       Force explicit pixel dimensions from window.innerHeight. */
    var vh = window.innerHeight || 600;
    _rm.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;' +
        'width:100%;height:' + vh + 'px;z-index:0;background:#10101c;';
    RIDE.map = new google.maps.Map(_rm, {
      center: { lat: 7.0621, lng: 38.4761 }, zoom: 14,
      /* No mapId: a Cloud mapId makes Google ignore `styles`, which left this
         map in default light colours under the dark gold UI. Markers here are
         classic google.maps.Marker and do not need one. */
      styles: window.EtelUI ? EtelUI.mapStyles(false) : undefined,
      disableDefaultUI: false, mapTypeId: 'roadmap',
      backgroundColor: '#10101c',
      mapTypeControl: true, zoomControl: true, fullscreenControl: true,
      mapTypeControlOptions: {
        position: google.maps.ControlPosition.TOP_RIGHT,
        mapTypeIds: ['roadmap', 'hybrid']
      }
    });
    RIDE.directionsService  = new google.maps.DirectionsService();
    RIDE.directionsRenderer = new google.maps.DirectionsRenderer({
      suppressMarkers: true,
      polylineOptions: { strokeColor: '#E5C76B', strokeWeight: 5, strokeOpacity: .95 }
    });
    RIDE.directionsRenderer.setMap(RIDE.map);
    RIDE.geocoder = new google.maps.Geocoder();
    _probeVehicleImages();

    _setupAutocomplete();
    _setupMapClick();
    _geolocateUser();
    checkActiveRide();
    // Book is the default visible view (no explicit switchRideTab('book')
    // call happens at startup - it's just what the markup shows first),
    // so start the nearby-drivers overlay here rather than only from tab
    // switches, or it would never appear until the user tapped away and
    // back.
    startNearbyDriversPolling();
  };

  function _setupAutocomplete() {
    var pe = document.getElementById('pickup-input');
    var de = document.getElementById('dropoff-input');
    if (!pe || typeof google.maps.places === 'undefined') return;
    try {
      RIDE.autocompletePickup = new google.maps.places.Autocomplete(pe, { componentRestrictions: { country: 'ET' } });
      RIDE.autocompletePickup.addListener('place_changed', function () {
        var p = RIDE.autocompletePickup.getPlace();
        if (p.geometry) setPickup(p.geometry.location.lat(), p.geometry.location.lng(), p.formatted_address || p.name);
      });
      RIDE.autocompleteDropoff = new google.maps.places.Autocomplete(de, { componentRestrictions: { country: 'ET' } });
      RIDE.autocompleteDropoff.addListener('place_changed', function () {
        var p = RIDE.autocompleteDropoff.getPlace();
        if (p.geometry) setDropoff(p.geometry.location.lat(), p.geometry.location.lng(), p.formatted_address || p.name);
      });
    } catch (e) {}
  }

  function _setupMapClick() {
    if (!RIDE.map) return;
    RIDE.map.addListener('click', function (e) {
      var lat = e.latLng.lat(), lng = e.latLng.lng();
      RIDE.geocoder.geocode({ location: e.latLng }, function (res, st) {
        var addr = (st === 'OK' && res[0]) ? res[0].formatted_address : lat.toFixed(5) + ',' + lng.toFixed(5);
        if (!RIDE.pickupLatLng) {
          setPickup(lat, lng, addr);
          document.getElementById('pickup-input').value = addr;
        } else if (!RIDE.dropoffLatLng) {
          setDropoff(lat, lng, addr);
          document.getElementById('dropoff-input').value = addr;
        }
      });
    });
  }

  function _geolocateUser() {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(function (pos) {
      try {
        RIDE.map.setCenter({ lat: pos.coords.latitude, lng: pos.coords.longitude });
        if (!RIDE.pickupLatLng) {
          setPickup(pos.coords.latitude, pos.coords.longitude, 'Your location');
          document.getElementById('pickup-input').value = 'Your location';
        }
        if (RIDE._me) RIDE._me.setMap(null);
        RIDE._me = new google.maps.Marker({
          position: { lat: pos.coords.latitude, lng: pos.coords.longitude },
          map: RIDE.map, title: 'You', zIndex: 999,
          icon: { path: google.maps.SymbolPath.CIRCLE, scale: 7, fillColor: '#2ECC71', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 3 }
        });
      } catch (e) {}
    }, function () {}, { enableHighAccuracy: true, timeout: 8000 });
  }

  /* ════════════ TABS ════════════ */
  window.switchRideTab = function (tab) {
    document.querySelectorAll('.ride-view').forEach(function (v) { v.style.display = 'none'; });
    document.querySelectorAll('.ride-tab').forEach(function (b) { b.classList.remove('active'); });
    var view = document.getElementById('view-' + tab);
    if (view) view.style.display = 'block';
    var btn = document.querySelector('.ride-tab[data-tab="' + tab + '"]');
    if (btn) btn.classList.add('active');
    if (tab === 'history') loadHistory();
    if (tab === 'active')  loadActiveTab();
    if (tab === 'book') startNearbyDriversPolling(); else stopNearbyDriversPolling();
  };

  function loadActiveTab() {
    var hdr = document.getElementById('arriving-header');
    var sub = document.getElementById('arriving-sub');
    if (hdr) hdr.textContent = 'Checking your ride…';
    if (sub) sub.textContent = '';
    var cancelledLocally = [];
    try { cancelledLocally = JSON.parse(localStorage.getItem('etel_cancelled_rides') || '[]'); } catch (e) {}
    fetch(apiBase() + '?action=my_active_ride', { headers: authHeader() })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if ((d.success || d.ok) && d.data && d.data.id) {
          var rid = d.data.id;
          if (cancelledLocally.indexOf(rid) >= 0) {
            fetch(apiBase(), { method: 'POST', headers: authHeader(),
              body: JSON.stringify({ action: 'cancel_ride', id: rid, reason: 'Customer cancelled' }) })
              .catch(function () {});
            _showNoActiveRide();
            return;
          }
          RIDE.currentRideId = rid;
          var st = d.data.status || '';
          if (st === 'searching' || st === 'pending') {
            showView('searching'); startPolling();
          } else if (st === 'accepted' || st === 'active' || st === 'in_progress') {
            showView('active'); renderActiveRide(d.data); startPolling();
          } else if (st === 'arrived') {
            showView('arrived'); renderArrivedCard(d.data);
          } else if (st === 'completed') {
            showView('rating');
          } else {
            _showNoActiveRide();
          }
        } else {
          _showNoActiveRide();
        }
      })
      .catch(function () {
        if (hdr) hdr.textContent = 'Could not load ride';
        if (sub) sub.textContent = 'Check your connection and try again';
      });
  }

  /* ════════════ AUTH ════════════ */
  window.doLogout = function () {
    if (confirm('Sign out of ETEL?')) {
      try { Auth.logout(); } catch (e) { localStorage.removeItem('etel_token'); window.location.href = ETEL.api.replace('api/boot.php', '') + 'login.html'; }
    }
  };

  /* ════════════ MY PROFILE (passenger's own profile) ════════════ */
  function mpInitials(name) {
    var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
  }

  window.openMyProfile = function () {
    var bg = document.getElementById('my-profile-modal-bg');
    if (!bg) return;
    var u = {};
    try {
      u = Auth.getUser ? (Auth.getUser() || {}) : {};
      if (!u.name && localStorage.getItem('etel_user')) u = JSON.parse(localStorage.getItem('etel_user'));
    } catch (e) {}
    var av = document.getElementById('mp-avatar');
    var nm = document.getElementById('mp-name');
    var ph = document.getElementById('mp-phone');
    if (av) av.textContent = mpInitials(u.name);
    if (nm) nm.textContent = u.name || 'Rider';
    if (ph) ph.textContent = u.phone || '';
    bg.classList.add('open');

    fetch(apiBase() + '?action=passenger_get_ride_history', { headers: authHeader() })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!(d.success || d.ok) || !d.data) return;
        var rides = (d.data.rides || []).filter(function (r) { return !r.as_driver; });
        var spent = rides.reduce(function (s, r) { return s + (parseFloat(r.fare_paid || r.fare_final || 0) || 0); }, 0);
        var rated = rides.filter(function (r) { return r.rating > 0; });
        var avgRating = rated.length ? (rated.reduce(function (s, r) { return s + parseFloat(r.rating); }, 0) / rated.length) : 0;
        var rEl = document.getElementById('mp-stat-rides');
        var sEl = document.getElementById('mp-stat-spent');
        var gEl = document.getElementById('mp-stat-rating');
        if (rEl) rEl.textContent = rides.length;
        if (sEl) sEl.textContent = 'ETB ' + Math.round(spent).toLocaleString();
        if (gEl) gEl.textContent = avgRating > 0 ? avgRating.toFixed(1) + ' ⭐' : 'New';
      }).catch(function () {});
  };

  window.closeMyProfile = function () {
    var bg = document.getElementById('my-profile-modal-bg');
    if (bg) bg.classList.remove('open');
  };

  /* ════════════ PAYMENT ════════════ */
  window.selectPayment = function (method) {
    RIDE.selectedPayment = method;
    document.querySelectorAll('.pay-opt').forEach(function (el) {
      el.classList.toggle('active', el.getAttribute('data-method') === method);
    });
  };

  /* ════════════ PICKUP / DROPOFF ════════════ */
  window.setPickup = function (lat, lng, addr) {
    RIDE.pickupLatLng = { lat: lat, lng: lng };
    var inp = document.getElementById('pickup-input'); if (inp) inp.value = addr || (lat.toFixed(5) + ',' + lng.toFixed(5));
    _placeMarker('pickupMarker', lat, lng, '#E5C76B', 'pickup');
    showZoneCircle({ lat: lat, lng: lng });
    hideNearbyCarMarkers();
    calcRouteIfReady();
  };

  window.setDropoff = function (lat, lng, addr) {
    RIDE.dropoffLatLng = { lat: lat, lng: lng };
    var inp = document.getElementById('dropoff-input'); if (inp) inp.value = addr || (lat.toFixed(5) + ',' + lng.toFixed(5));
    _placeMarker('dropoffMarker', lat, lng, '#60B4FF', 'dropoff');
    calcRouteIfReady();
  };

  function _placeMarker(key, lat, lng, color, title) {
    try {
      if (!RIDE.map || !window.google || !google.maps) return;
      if (RIDE[key]) RIDE[key].setMap(null);
      RIDE[key] = new google.maps.Marker({
        position: { lat: lat, lng: lng }, map: RIDE.map,
        icon: { path: google.maps.SymbolPath.CIRCLE, fillColor: color, fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2, scale: 10 },
        title: title
      });
    } catch (e) {}
  }

  /* ════════════ ROUTE CALC ════════════ */
  window.calcRouteIfReady = function () {
    var p = RIDE.pickupLatLng, d = RIDE.dropoffLatLng;
    if (!p || !d) return;
    RIDE.manualKm = null;
    var ki = document.getElementById('bykm-input'); if (ki) ki.value = '';
    var done = false;

    function showFare(km, dur) {
      RIDE.lastRouteKm = km;
      RIDE.lastRouteDurLabel = dur || null;
      RIDE.lastRouteIsManual = false;
      refreshFareDisplay();
    }

    function haversine(a, b, c, d2) {
      var R = 6371, r = Math.PI / 180, dA = (c - a) * r, dB = (d2 - b) * r;
      var x = Math.sin(dA / 2) * Math.sin(dA / 2) + Math.cos(a * r) * Math.cos(c * r) * Math.sin(dB / 2) * Math.sin(dB / 2);
      return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
    }

    function fallback() {
      var km = haversine(p.lat, p.lng, d.lat, d.lng);
      showFare(km, '~' + Math.max(3, Math.round(km * 3)) + ' min');
      try {
        if (RIDE.map && google.maps.Polyline) {
          if (RIDE._fbLine) RIDE._fbLine.setMap(null);
          RIDE._fbLine = new google.maps.Polyline({ map: RIDE.map, path: [p, d], strokeColor: '#E5C76B', strokeWeight: 4, strokeOpacity: .85 });
          var b = new google.maps.LatLngBounds(); b.extend(p); b.extend(d);
          RIDE.map.fitBounds(b, { bottom: 340, top: 80, left: 24, right: 24 });
        }
      } catch (e) {}
    }

    try {
      if (RIDE.directionsService && RIDE.map) {
        RIDE.directionsService.route({ origin: p, destination: d, travelMode: google.maps.TravelMode.DRIVING }, function (res, st) {
          if (done) return; done = true;
          if (st === 'OK' && res && res.routes[0]) {
            try { RIDE.directionsRenderer.setDirections(res); } catch (e) {}
            var leg = res.routes[0].legs[0]; showFare(leg.distance.value / 1000, leg.duration.text);
            try { var b = new google.maps.LatLngBounds(); b.extend(p); b.extend(d); RIDE.map.fitBounds(b, { bottom: 340, top: 80, left: 24, right: 24 }); } catch (e) {}
          } else fallback();
        });
        setTimeout(function () { if (!done) { done = true; fallback(); } }, 4000);
      } else fallback();
    } catch (e) { fallback(); }
  };

  /* ════════════ RIDE BY DISTANCE (no destination) ════════════ */
  window.setManualKm = function (km) {
    km = parseFloat(km);
    if (!km || km <= 0) { RIDE.manualKm = null; return; }
    if (km > 100) km = 100;
    RIDE.manualKm = km;
    RIDE.dropoffLatLng = null;
    var di = document.getElementById('dropoff-input');
    if (di) di.value = 'By distance: ' + km + ' km';
    RIDE.lastRouteKm = km;
    RIDE.lastRouteDurLabel = null;
    RIDE.lastRouteIsManual = true;
    refreshFareDisplay();
  };

  /* ════════════ BOOK RIDE ════════════ */
  function cancelExistingAndRetry(rideId, payload) {
    fetch(apiBase(), {
      method: 'POST',
      headers: authHeader(),
      body: JSON.stringify({ action: 'cancel_ride', id: rideId, reason: 'Customer rebooking' })
    })
    .then(function (r) { return r.json(); })
    .then(function (cd) {
      if (cd.success || cd.ok) {
        /* Remember locally so loadActiveTab doesn't re-trigger */
        try {
          var list = JSON.parse(localStorage.getItem('etel_cancelled_rides') || '[]');
          if (list.indexOf(rideId) < 0) list.push(rideId);
          localStorage.setItem('etel_cancelled_rides', JSON.stringify(list));
        } catch (e) {}
        RIDE.currentRideId = null;
        RIDE.currentRequestId = null;
        showToast('Old ride cancelled — booking new one…', 'ok');
        setTimeout(function () { bookRide(); }, 600);
      } else {
        showToast('Could not cancel old ride: ' + (cd.message || 'unknown error'), 'error');
      }
    })
    .catch(function () { showToast('Network error while cancelling old ride', 'error'); });
  }

  window.bookRide = function () {
    if (!RIDE.pickupLatLng)  { showToast('Please set your pickup location', 'warn'); _resetRideSlide(); return; }
    if (!RIDE.dropoffLatLng && !RIDE.manualKm) { showToast('Set a drop-off place, or choose a distance in km', 'warn'); _resetRideSlide(); return; }

    var btn = document.getElementById('book-btn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Booking…'; }

    var payload = {
      action: 'book_ride',
      pickup_lat:    RIDE.pickupLatLng.lat,
      pickup_lng:    RIDE.pickupLatLng.lng,
      dropoff_lat:   RIDE.dropoffLatLng ? RIDE.dropoffLatLng.lat : '',
      dropoff_lng:   RIDE.dropoffLatLng ? RIDE.dropoffLatLng.lng : '',
      manual_km:     RIDE.manualKm || '',
      pickup_addr:   document.getElementById('pickup-input').value  || '',
      dropoff_addr:  document.getElementById('dropoff-input').value || '',
      payment_method: RIDE.selectedPayment,
      vehicle_type:  RIDE.selectedVtype || 'bajaj',
      fare_estimate:  (document.getElementById('fare-amount') ? document.getElementById('fare-amount').textContent : ''),
      quote_token: (RIDE.quoteTokenVtype === RIDE.selectedVtype && RIDE.quoteToken
        && Math.abs((RIDE.quoteTokenKm || 0) - (RIDE.lastRouteKm || 0)) < 0.1) ? RIDE.quoteToken : '',
      route_km: RIDE.lastRouteKm || ''
    };

    fetch(apiBase(), { method: 'POST', headers: authHeader(), body: JSON.stringify(payload) })
      .then(function (r) {
        var status = r.status;
        return r.text().then(function (txt) {
          try { return JSON.parse(txt); }
          catch (e) { throw new Error('Server error (' + status + '): ' + txt.substring(0, 120)); }
        });
      })
      .then(function (d) {
        if (d.success || d.ok) {
          RIDE.currentRequestId = d.data && d.data.request_id ? d.data.request_id : (d.request_id || null);
          var code = document.getElementById('searching-req-code');
          if (code && RIDE.currentRequestId) code.textContent = '#' + RIDE.currentRequestId;
          // FARE-DISPLAY FIX: book_ride returns the true, final locked-in
          // fare (d.data.fare) - this is the authoritative number the
          // driver will see and the passenger will be charged. Previously
          // #searching-fare was never touched here, so it kept showing
          // whatever the last pre-confirm estimate happened to be. If a
          // surge window started (or the quote token had expired/didn't
          // match) between viewing the estimate and sliding to confirm,
          // the passenger saw a stale, lower number while the driver's
          // notification correctly showed the real, higher fare.
          if (d.data && d.data.fare != null) {
            var bookedFare = parseFloat(d.data.fare).toFixed(2);
            var priorFareEl = document.getElementById('fare-amount');
            var priorFareText = priorFareEl ? priorFareEl.textContent.replace(/[^0-9.]/g, '') : '';
            var priorFare = priorFareText ? parseFloat(priorFareText) : null;
            var sf = document.getElementById('searching-fare');
            if (sf) sf.textContent = 'ETB ' + bookedFare;
            if (priorFare !== null && Math.abs(priorFare - parseFloat(bookedFare)) > 0.01) {
              showToast('Price updated to ETB ' + bookedFare + (d.data.surge ? ' (peak-hour pricing)' : ''), 'warn', 6000);
            }
          }
          showView('searching');
          startPolling();
          var sp = document.getElementById('s-pickup-addr');
          var sd = document.getElementById('s-drop-addr');
          if (sp) sp.textContent = document.getElementById('pickup-input').value || 'Your location';
          if (sd) sd.textContent = document.getElementById('dropoff-input').value || 'Destination';
        } else {
          var msg = d.message || 'Booking failed — please try again';
          /* If the server tells us about an existing active ride, offer a one-tap cancel */
          var existingId = d.data && d.data.existing_ride_id ? d.data.existing_ride_id : null;
          if (existingId && /already have an active ride/i.test(msg)) {
            var toastEl = showToast('You have ride #' + existingId + ' in progress — tap to cancel & rebook', 'warn', 8000);
            if (toastEl) {
              toastEl.onclick = function () {
                cancelExistingAndRetry(existingId, payload);
              };
            }
            _resetRideSlide();
            return;
          }
          showToast(msg, 'error');
          _resetRideSlide();
        }
      })
      .catch(function (err) { showToast((err && err.message) || 'Network error — check your connection', 'error'); _resetRideSlide(); })
      .finally(function () {
        if (btn) { btn.disabled = false; btn.textContent = '🚗 Book Ride'; }
      });
  };

  /* ════════════ SSE — replaces 5s polling ════════════ */
  function startPolling() {
    stopPolling();
    /* Search timer (counts seconds while waiting for driver) */
    RIDE.searchSecs = 0;
    RIDE.searchTimer = setInterval(function() {
      RIDE.searchSecs++;
      var m = Math.floor(RIDE.searchSecs / 60);
      var s = RIDE.searchSecs % 60;
      var el = document.getElementById('searching-timer');
      if (el) el.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }, 1000);
    _startRideSSE();
  }

  function stopPolling() {
    if (RIDE.sseSource) { RIDE.sseSource.close(); RIDE.sseSource = null; }
    if (RIDE.pollInterval) { clearInterval(RIDE.pollInterval); RIDE.pollInterval = null; }
    if (RIDE.searchTimer) { clearInterval(RIDE.searchTimer); RIDE.searchTimer = null; }
  }

  function _startRideSSE() {
    var id = RIDE.currentRideId || RIDE.currentRequestId;
    if (!id) return;
    var token = (window.ETEL && ETEL.auth && ETEL.auth.token) ? ETEL.auth.token : (localStorage.getItem('etel_token') || '');
    var url = apiBase() + '?action=sse_ride_status&id=' + id + '&token=' + encodeURIComponent(token);
    try {
      var es = new EventSource(url);
      RIDE.sseSource = es;
      es.onmessage = function(e) {
        try {
          var rd = JSON.parse(e.data);
          _handleRideUpdate(rd);
        } catch(ex) {}
      };
      es.addEventListener('close', function() {
        es.close(); RIDE.sseSource = null;
      });
      es.onerror = function() {
        es.close(); RIDE.sseSource = null;
        /* Fallback to 6s polling */
        if (!RIDE.pollInterval) {
          RIDE.pollInterval = setInterval(pollRideStatus, 6000);
          pollRideStatus();
        }
      };
    } catch(ex) {
      RIDE.pollInterval = setInterval(pollRideStatus, 6000);
      pollRideStatus();
    }
  }

  /* Mobile browsers throttle/suspend timers and SSE connections once this
     tab is backgrounded (most aggressive on iOS Safari - that's an OS-level
     restriction, not fixable from here). The one thing we can do: the
     instant the rider comes back to this tab, resume tracking immediately
     instead of waiting for the next throttled timer tick. */
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible') return;
    if (!RIDE.currentRideId && !RIDE.currentRequestId) return;
    if (!RIDE.sseSource || RIDE.sseSource.readyState === EventSource.CLOSED) {
      _startRideSSE();
    }
    pollRideStatus();
  });

  /* The server never loses ride/fare data while the customer is offline
     (it's computed from the driver's GPS pings, independent of the
     customer's connection) - this is purely about the customer's own
     view catching up immediately instead of waiting for the next
     scheduled poll/SSE retry once their connection returns. */
  function _updateRideOfflineBadge() {
    var badge = document.getElementById('ride-offline-badge');
    if (badge) badge.style.display = navigator.onLine ? 'none' : 'flex';
  }
  window.addEventListener('offline', _updateRideOfflineBadge);
  window.addEventListener('online', function () {
    _updateRideOfflineBadge();
    if (!RIDE.currentRideId && !RIDE.currentRequestId) return;
    pollRideStatus();
    if (!RIDE.sseSource || RIDE.sseSource.readyState === EventSource.CLOSED) {
      _startRideSSE();
    }
  });

  function _handleRideUpdate(rd) {
    var status = rd.status || '';
    if (status === 'accepted' || status === 'in_progress') {
      RIDE.currentRideId = rd.id || RIDE.currentRideId;
      RIDE.activeRideData = rd;
      showView('active');
      renderActiveRide(rd);
      updateDriverMarker(rd);
      // Only (re)notify when the status actually changed - this used to
      // fire on every 6s poll tick with renotify:true + requireInteraction,
      // so it kept re-alerting/vibrating nonstop for as long as the ride
      // stayed in the same status.
      if (RIDE._lastReminderStatus !== status) {
        RIDE._lastReminderStatus = status;
        _showRideReminder(status === 'in_progress' ? 'Your ride is in progress' : 'Your driver is on the way');
      }
      _maybeShowBatteryTip();
    } else if (status === 'arrived') {
      RIDE.currentRideId = rd.id || RIDE.currentRideId;
      stopPolling();
      showView('arrived');
      renderArrivedCard(rd);
      if (RIDE._lastReminderStatus !== status) {
        RIDE._lastReminderStatus = status;
        _showRideReminder('Your driver has arrived');
      }
    } else if (status === 'completed') {
      stopPolling();
      showView('rating');
      RIDE._lastReminderStatus = null;
      _clearRideReminder();
    } else if (status === 'cancelled') {
      /* Clear stale ride IDs so polling + watcher don't keep firing on the cancelled ride */
      RIDE.currentRideId = null;
      RIDE.currentRequestId = null;
      RIDE.activeRideData = null;
      stopPolling();
      showView('book');
      showToast('Ride was cancelled', 'warn');
      RIDE._lastReminderStatus = null;
      _clearRideReminder();
    }
  }

  /* == "Your ride is active" reminder (persistent notification + app-icon badge) ==
     Same purpose as the driver app's online reminder: while a ride is
     matched/in-progress and the rider checks another app, give them a
     tappable way straight back, and a heads-up that this tab isn't tracking
     anything while backgrounded on phones that aggressively freeze it. */
  function _showRideReminder(bodyText) {
    try { if (navigator.setAppBadge) navigator.setAppBadge(); } catch (e) {}
    if (!('Notification' in window) || !navigator.serviceWorker) return;
    if (Notification.permission !== 'granted') {
      if (Notification.permission === 'default') {
        Notification.requestPermission().then(function (p) {
          if (p === 'granted') _doShowRideNotif(bodyText);
        });
      }
      return;
    }
    _doShowRideNotif(bodyText);
  }
  function _doShowRideNotif(bodyText) {
    navigator.serviceWorker.ready.then(function (reg) {
      reg.showNotification('ETEL Ride', {
        body: bodyText + ' \u2014 tap to track your trip.',
        icon: '../icons/icon-192.png',
        badge: '../icons/icon-72.png',
        tag: 'etel-ride-status',
        renotify: true,
        requireInteraction: true,
        silent: true,
        // Absolute path, not './' - notificationclick in sw.js resolves a
        // relative url against the service worker SCRIPT's own location
        // (always /delivery/sw.js, since every app shares one sw.js file),
        // never against whichever page happened to be open. './' was
        // therefore always resolving to /delivery/ (the Delivery home
        // page) instead of back into the Ride app.
        data: '/delivery/ride/'
      }).catch(function () {});
    });
  }
  function _clearRideReminder() {
    try { if (navigator.clearAppBadge) navigator.clearAppBadge(); } catch (e) {}
    if (!navigator.serviceWorker) return;
    navigator.serviceWorker.ready.then(function (reg) {
      reg.getNotifications({ tag: 'etel-ride-status' }).then(function (ns) {
        ns.forEach(function (n) { n.close(); });
      });
    }).catch(function () {});
  }

  /* == Battery-optimization tip (same brands/logic as the driver app) ==
     Shown once per device, the first time a rider has an active ride, since
     that's the first moment losing background tracking would actually cost
     them something (a missed "driver arrived" update). */
  function _detectBatteryAggressiveOEM() {
    var ua = (navigator.userAgent || '').toLowerCase();
    if (/android/.test(ua) === false) return null;
    if (/tecno|infinix|itel|camon|spark/.test(ua)) {
      return {
        brand: 'Tecno / Infinix / itel',
        steps: [
          'Settings \u2192 Battery Lab (or Battery Saving Settings) \u2192 disable "App Power Saving Management" for this browser app.',
          'Phone Master \u2192 Toolbox \u2192 Auto-start Management \u2192 allow this browser to run in the background.',
          'Open your recent-apps switcher, find this browser, and tap the lock icon on its card so the system does not close it.',
          'If you see "Power Boost" or "Sleep Mode Optimization", turn those off too.'
        ]
      };
    }
    if (/xiaomi|redmi|miui/.test(ua)) {
      return {
        brand: 'Xiaomi / Redmi',
        steps: [
          'Settings \u2192 Battery & performance \u2192 App battery saver \u2192 find this browser \u2192 set to "No restrictions".',
          'Settings \u2192 Apps \u2192 Manage apps \u2192 this browser \u2192 Autostart \u2192 enable it.',
          'Open recent apps, find this browser, and lock it so it is not cleared automatically.'
        ]
      };
    }
    if (/oppo|coloros|realme/.test(ua)) {
      return {
        brand: 'Oppo / Realme',
        steps: [
          'Settings \u2192 Battery \u2192 App Battery Management \u2192 find this browser \u2192 allow "Allow background running".',
          'Open recent apps, find this browser, and lock it from the app card menu.'
        ]
      };
    }
    if (/vivo|funtouch/.test(ua)) {
      return {
        brand: 'Vivo',
        steps: [
          'Settings \u2192 Battery \u2192 Background power consumption management \u2192 allow this browser.',
          'Open recent apps, find this browser, and lock it from the app card menu.'
        ]
      };
    }
    return null;
  }

  function _maybeShowBatteryTip() {
    try { if (localStorage.getItem('etelBatteryTipSeen') === '1') return; } catch (e) { return; }
    var info = _detectBatteryAggressiveOEM();
    if (!info) return;
    _showBatteryTipModal(info);
  }

  window._showBatteryTipModal = function (info) {
    if (!info) return;
    var wrap = document.createElement('div');
    wrap.className = 'modal-overlay';
    wrap.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';
    var stepsHtml = info.steps.map(function (s) { return '<li style="margin-bottom:10px;line-height:1.5">' + s + '</li>'; }).join('');
    wrap.innerHTML =
      '<div class="modal-content" style="max-width:420px;width:100%;padding:26px 24px;border-radius:20px;max-height:80vh;overflow:auto">' +
      '  <div style="font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.7;margin-bottom:8px">One more step \u2014 ' + info.brand + '</div>' +
      '  <div style="font-size:15px;line-height:1.6;margin-bottom:16px">Your phone\u2019s battery saver can pause ride tracking in the background. Allow this browser to keep running so you never miss a pickup update:</div>' +
      '  <ol style="padding-left:20px;font-size:13.5px;margin-bottom:20px">' + stepsHtml + '</ol>' +
      '  <button id="ride-battery-tip-ok" style="width:100%;padding:13px;border:none;border-radius:12px;font-weight:700;font-size:14px;cursor:pointer;background:linear-gradient(135deg,#3395FF,#007AFF);color:#fff">Got it</button>' +
      '</div>';
    document.body.appendChild(wrap);
    document.getElementById('ride-battery-tip-ok').addEventListener('click', function () {
      try { localStorage.setItem('etelBatteryTipSeen', '1'); } catch (e) {}
      wrap.remove();
    });
  };
  window._detectBatteryAggressiveOEM = _detectBatteryAggressiveOEM;

  /* Fallback polling — only used when SSE unavailable */
  function pollRideStatus() {
    if (!RIDE.currentRequestId && !RIDE.currentRideId) return;
    var id = RIDE.currentRideId || RIDE.currentRequestId;
    fetch(apiBase() + '?action=ride_status&id=' + id, { headers: authHeader() })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d.success && !d.ok) return;
        _handleRideUpdate(d.data || d);
      })
      .catch(function() {});
  }

  /* The Active view holds the driver card, ETA/fare rows and the Cancel
     button all in one container. When the server says there is no ride, only
     the header text used to change, so the last driver's card stayed on
     screen looking like a live trip (seen after a driver cancelled).
     This hides everything but the header when there is no ride and brings it
     back the next time a ride is rendered. */
  function _setActiveViewHasRide(has) {
    var view = document.getElementById('view-active');
    if (!view) return;
    var keep = { 'arriving-header': 1, 'arriving-sub': 1, 'ride-offline-badge': 1 };
    Array.prototype.forEach.call(view.children, function (el) {
      if (keep[el.id]) return;
      el.style.display = has ? '' : 'none';
    });
  }

  function _showNoActiveRide() {
    RIDE.currentRideId = null;
    RIDE.currentRequestId = null;
    RIDE.activeRideData = null;
    stopPolling();
    RIDE._lastReminderStatus = null;
    try { _clearRideReminder(); } catch (e) {}
    _setActiveViewHasRide(false);
    var hdr = document.getElementById('arriving-header');
    var sub = document.getElementById('arriving-sub');
    if (hdr) hdr.textContent = 'No Active Ride';
    if (sub) sub.textContent = 'Book a new ride from the Book tab';
  }

  function renderActiveRide(rd) {
    _setActiveViewHasRide(true);
    var status = rd.status || '';
    var isArriving = (status === 'accepted');
    var isMoving   = (status === 'in_progress' || status === 'active');

    /* Arriving header */
    var hdr = document.getElementById('arriving-header');
    var sub = document.getElementById('arriving-sub');
    if (hdr) {
      if (isArriving) { hdr.textContent = 'Arriving in ~1 min'; }
      else if (isMoving) { hdr.textContent = 'On the way to destination'; }
      else { hdr.textContent = 'Driver on the way'; }
    }
    if (sub) sub.textContent = isArriving ? 'Your driver is nearby' : isMoving ? 'Enjoy your ride' : 'Tracking your driver';

    /* Driver card fields */
    var name  = rd.driver_name || rd.driver && rd.driver.name || 'Your Driver';
    var plate = rd.vehicle_plate || rd.driver && rd.driver.plate || '——';
    var phone = rd.driver_phone || rd.driver && rd.driver.phone || '';
    var rating= parseFloat(rd.driver_rating || rd.driver && rd.driver.rating || 0);
    var photo = rd.driver_photo || rd.driver && rd.driver.photo || '';
    var vtype = rd.vehicle_type || 'Bajaj TukTuk';
    var vcolor = rd.vehicle_color || '';
    var vmake  = rd.vehicle_make || '';
    var vdesc = [vcolor, vmake, vtype].filter(Boolean).join(' ');
    var fareRaw = (rd.fare_estimate !== undefined && rd.fare_estimate !== null) ? rd.fare_estimate
                : (rd.fare_final !== undefined && rd.fare_final !== null) ? rd.fare_final
                : (rd.fare !== undefined && rd.fare !== null) ? rd.fare : null;
    var fare = (fareRaw !== null) ? 'ETB ' + parseFloat(fareRaw).toFixed(0) : '—';

    var dn = document.getElementById('drv-name');    if (dn) dn.textContent = name;
    var dr = document.getElementById('drv-rating');  if (dr) dr.textContent = rating ? ('⭐ ' + rating.toFixed(1)) : '⭐ New';
    var dv = document.getElementById('drv-vehicle'); if (dv) dv.textContent = vdesc;
    var dp = document.getElementById('drv-plate');   if (dp) dp.textContent = plate;
    var da = document.getElementById('driver-avatar-btn');
    if (da) da.innerHTML = photo
        ? '<img src="' + escUrl(photo) + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">'
        : '🧑';

    /* Call button href */
    var cb = document.getElementById('drv-call-btn');
    if (cb) cb.setAttribute('data-phone', phone);

    /* Info rows */
    var pickup = rd.pickup_address || rd.pickup_addr || 'Pickup';
    var drop   = rd.dropoff_address || rd.dropoff_addr || (rd.open_trip ? 'Open trip — customer directs' : 'Destination');
    var ap = document.getElementById('a-pickup-val'); if (ap) ap.textContent = pickup;
    var ad = document.getElementById('a-drop-val');   if (ad) ad.textContent = drop;
    var af = document.getElementById('a-fare-val');   if (af) af.textContent = fare;
    var asurge = document.getElementById('a-fare-surge');
    if (asurge) asurge.style.display = (rd.surge_applied == 1 || rd.surge == true) ? 'inline-flex' : 'none';

    /* Store for modal */
    RIDE.activeRideData = rd;
    RIDE._driverPhone = phone;

    // These target a-wait-box/a-wait-val and a-fare-breakdown, both
    // already present in the HTML template (built in an earlier session)
    // but never actually populated - renderActiveRide only ever filled
    // in the static pickup/drop/fare-estimate rows, so the customer never
    // saw the live GPS meter, waiting-fee clock, or itemized breakdown
    // the driver's own screen has shown all along for the same ride.
    _syncCustomerWaitState(rd);
    _updateFareBreakdown(rd);
  }

  /* ══════ ARRIVED — bill confirmation card ══════ */
  function renderArrivedCard(rd) {
    var el = document.getElementById('arrived-ride-content'); if (!el) return;
    var km   = parseFloat(rd.distance_km || 0).toFixed(2);
    var fare = parseFloat(rd.fare_final || rd.fare || 0).toFixed(0);
    /* If fare is 0 (open trip just computed), re-fetch ride details */
    if (parseFloat(fare) <= 0 && (rd.id || RIDE.currentRideId)) {
      el.innerHTML = '<div style="text-align:center;padding:32px;color:var(--dim)">Calculating fare…</div>';
      var rid2 = rd.id || RIDE.currentRideId;
      fetch(apiBase() + '?action=ride_status&id=' + rid2, { headers: authHeader() })
        .then(function(r2){ return r2.json(); })
        .then(function(d2){
          var fresh = (d2.data || d2);
          var km2 = parseFloat(fresh.distance_km || rd.distance_km || 0);
          var fare2 = parseFloat(fresh.fare_final || fresh.fare || 0);
          if (fare2 <= 0 && km2 > 0) fare2 = 120 + 30 * km2;
          rd.distance_km = km2; rd.fare_final = fare2;
          renderArrivedCard(rd);
        }).catch(function(){ renderArrivedCard(rd); });
      return;
    }
    var rid  = rd.ride_id || rd.id || RIDE.currentRideId;
    var surgeActive = (rd.surge_applied == 1 || rd.surge == true);
    // The multiplier was hardcoded as "1.3x" text regardless of the
    // actual value - fare_surge_mult carries the real number (this
    // vehicle's configured peak rate could be 1.2x, 1.5x, anything),
    // so a driver/rider running a different multiplier than 1.3 saw a
    // badge that flatly contradicted the itemized breakdown right below
    // it, showing the correct number.
    var surgeMultDisplay = rd.fare_surge_mult != null ? parseFloat(rd.fare_surge_mult).toFixed(1) : '1.3';
    var surgeBadgeHtml = surgeActive
      ? '<div style="display:inline-flex;align-items:center;gap:4px;background:rgba(255,152,0,.15);border:1px solid rgba(255,152,0,.4);color:#FFB74D;font-size:11px;font-weight:700;padding:4px 11px;border-radius:999px;margin-top:8px">⚡ Peak-hour pricing was applied (' + surgeMultDisplay + '×)</div>'
      : '';
    el.innerHTML =
      '<div style="text-align:center;margin-bottom:20px">' +
        '<div style="font-size:48px;margin-bottom:8px">&#x1F3C1;</div>' +
        '<div style="font-size:16px;font-weight:700;color:var(--gold-lt)">You have arrived!</div>' +
        '<div style="font-size:12px;color:var(--dim);margin-top:4px">Fare based on actual GPS-measured distance</div>' +
      '</div>' +
      '<div style="background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.3);border-radius:16px;padding:20px;margin-bottom:18px;text-align:center">' +
        '<div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Distance travelled</div>' +
        '<div style="font-size:24px;font-weight:800;color:var(--teal)">' + km + ' km</div>' +
        '<div style="height:1px;background:rgba(255,255,255,.07);margin:14px 0"></div>' +
        '<div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Total fare</div>' +
        '<div style="font-size:40px;font-weight:800;color:var(--gold-lt)">ETB ' + fare + '</div>' +
        surgeBadgeHtml +
      '</div>' +
      (rd.fare_total != null ? '<div style="margin-bottom:18px;background:rgba(255,255,255,.02);border:1px solid rgba(212,175,55,.12);border-radius:12px;padding:14px 16px">' + _fareBreakdownHtml(rd) + '</div>' : '') +
      '<button class="action-btn" style="width:100%;margin-bottom:12px;background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.25);color:var(--gold-lt)" onclick="showRoutePath(' + rid + ')">🧭 View Route Driven</button>' +
      '<button onclick="confirmPayment(' + rid + ',\'cash\')" style="width:100%;padding:16px;border-radius:12px;border:none;' +
        'background:linear-gradient(135deg,#FFE9A8,#D4AF37);color:#1a1a2e;font-size:16px;font-weight:800;cursor:pointer;margin-bottom:10px">' +
        '&#x2705; Pay Cash &mdash; ETB ' + fare +
      '</button>' +
      '<button onclick="openEtelPayWallet(' + rid + ',' + fare + ')" style="width:100%;padding:14px;border-radius:12px;' +
        'border:1px solid rgba(212,175,55,.4);background:linear-gradient(135deg,rgba(42,26,6,.9),rgba(74,50,16,.8));color:#E8C84A;font-size:15px;font-weight:700;cursor:pointer">' +
        '&#x1F4B0; &#x1298;E Pay &mdash; Ethiopian Wallet' +
      '</button>' +
      '<div id="confirm-status" style="margin-top:12px;text-align:center;font-size:13px;min-height:18px"></div>';
  }

  // Etel Pay's own live API -- the rider's PIN goes straight from this
  // browser to Etel Pay, never through delivery's backend.
  var ETEL_PAY_API = 'https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php';

  /* Pay a ride fare with ኢE Pay -- prompts for the rider's Etel Pay PIN,
     logs into Etel Pay directly (their phone never leaves this device
     except to Etel Pay itself), sends the fare to Etel Delivery's
     merchant account (resolved live from Etel-ID's company admin link,
     not a hardcoded number), then tells delivery's own backend the
     tx_ref so it can independently verify the transfer before marking
     the ride paid. */
  window.openEtelPayWallet = function(rideId, fare) {
    var st = document.getElementById('confirm-status');
    var btns = document.querySelectorAll('#arrived-ride-content button');
    var reEnable = function () { btns.forEach(function (b) { b.disabled = false; }); };

    /* If a previous attempt for this ride already moved real money but
       failed to confirm (network blip, verification mismatch, etc.), do
       NOT send a second transfer -- that would double-charge the rider
       for one ride. Retry confirming the transfer that already happened
       instead. This key is only cleared once confirmation truly succeeds. */
    var pendingKey = 'etel_pending_wallet_tx_' + rideId;
    var pendingTx = localStorage.getItem(pendingKey);
    if (pendingTx) {
      if (st) { st.style.color = 'var(--gold-lt)'; st.textContent = 'Re-checking your previous payment…'; }
      btns.forEach(function (b) { b.disabled = true; });
      _confirmWalletPayment(rideId, fare, pendingTx, pendingKey, st, reEnable);
      return;
    }

    var user = {};
    try { user = JSON.parse(localStorage.getItem('etel_user') || '{}'); } catch (e) {}
    var phone = user.phone || '';
    if (!phone) { showToast('Could not find your phone number. Please pay cash.', 'err'); return; }

    var pin = prompt('Enter your ኢE Pay PIN to pay ETB ' + fare);
    if (!pin) return;

    if (st) { st.style.color = 'var(--gold-lt)'; st.textContent = 'Looking up payment account…'; }
    btns.forEach(function (b) { b.disabled = true; });

    fetch(apiBase() + '?action=get_etelpay_merchant', { headers: authHeader() })
      .then(function (r) { return r.json(); })
      .then(function (merchRes) {
        if (!(merchRes.success || merchRes.ok) || !merchRes.data || !merchRes.data.phone) {
          throw new Error((merchRes.data && merchRes.data.message) || merchRes.error || 'Etel Pay is not set up yet for this app. Pay cash instead.');
        }
        _payEtelPay(rideId, fare, phone, pin, merchRes.data.phone, st, reEnable);
      })
      .catch(function (err) {
        reEnable();
        if (st) { st.style.color = '#f87171'; st.textContent = ''; }
        showToast(err.message || 'Could not reach ETEL Delivery.', 'err');
      });
  };

  function _payEtelPay(rideId, fare, phone, pin, merchantPhone, st, reEnable) {
    if (st) st.textContent = 'Connecting to ኢE Pay…';
    var pendingKey = 'etel_pending_wallet_tx_' + rideId;

    fetch(ETEL_PAY_API + '?action=ep_login_etelid', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'ep_login_etelid', phone: phone, pin: pin }),
    })
      .then(function (r) { return r.json(); })
      .then(function (loginRes) {
        if (!(loginRes.success || loginRes.ok) || !loginRes.data || !loginRes.data.token) {
          throw new Error((loginRes.data && loginRes.data.message) || loginRes.error || 'ኢE Pay sign-in failed. Check your PIN.');
        }
        var epToken = loginRes.data.token;
        if (st) st.textContent = 'Sending payment…';
        return fetch(ETEL_PAY_API + '?action=ep_send', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + epToken },
          body: JSON.stringify({ action: 'ep_send', receiver: merchantPhone, amount: fare, currency: 'ETB', note: 'ETEL Ride #' + rideId }),
        });
      })
      .then(function (r) { return r.json(); })
      .then(function (sendRes) {
        if (!(sendRes.success || sendRes.ok) || !sendRes.data || !sendRes.data.tx_ref) {
          throw new Error((sendRes.data && sendRes.data.message) || sendRes.error || 'ኢE Pay transfer failed.');
        }
        /* Real money has now left the wallet. Remember the tx_ref BEFORE
           attempting to confirm it -- if confirmation fails or the network
           drops right here, the next attempt must retry confirming THIS
           transfer, never send a second one. */
        localStorage.setItem(pendingKey, sendRes.data.tx_ref);
        _confirmWalletPayment(rideId, fare, sendRes.data.tx_ref, pendingKey, st, reEnable);
      })
      .catch(function (err) {
        reEnable();
        if (st) { st.style.color = '#f87171'; st.textContent = ''; }
        showToast(err.message || 'ኢE Pay payment failed. Please pay cash instead.', 'err');
      });
  }

  function _confirmWalletPayment(rideId, fare, txRef, pendingKey, st, reEnable) {
    if (st) st.textContent = 'Confirming with ETEL Delivery…';
    fetch(apiBase(), {
      method: 'POST', headers: authHeader(),
      body: JSON.stringify({ action: 'customer_confirm_arrival', ride_id: rideId, payment_method: 'wallet', wallet_tx_ref: txRef }),
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success || d.ok) {
          localStorage.removeItem(pendingKey);
          RIDE.currentRideId = rideId;
          showView('rating');
          showToast('Paid ETB ' + parseFloat((d.data || d).fare_paid || fare).toFixed(0) + ' with ኢE Pay — Thank you!', 'success');
        } else {
          // Do NOT clear pendingKey -- the transfer already happened.
          // A retry must re-verify this same tx_ref, never send again.
          throw new Error(d.message || (d.data && d.data.message) || 'Payment could not be confirmed. Your money is safe — contact support.');
        }
      })
      .catch(function (err) {
        reEnable();
        if (st) { st.style.color = '#f87171'; st.textContent = ''; }
        showToast(err.message || 'Could not confirm payment. Tap Pay again to retry — you will not be charged twice.', 'err');
      });
  }

  window.confirmPayment = function (rideId, method) {
    var btns = document.querySelectorAll('#arrived-ride-content button');
    btns.forEach(function (b) { b.disabled = true; });
    var st = document.getElementById('confirm-status');
    if (st) { st.style.color = 'var(--gold-lt)'; st.textContent = 'Processing...'; }
    fetch(apiBase(), {
      method: 'POST', headers: authHeader(),
      body: JSON.stringify({ action: 'customer_confirm_arrival', ride_id: rideId, payment_method: method })
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.success || d.ok) {
        RIDE.currentRideId = rideId;
        showView('rating');
        showToast('Paid ETB ' + parseFloat((d.data || d).fare_paid || 0).toFixed(0) + ' - Thank you!', 'success');
      } else {
        var msg = (d.data && d.data.message) || d.message || 'Payment failed - try again';
        if (st) { st.style.color = '#FF8A80'; st.textContent = msg; }
        btns.forEach(function (b) { b.disabled = false; });
      }
    })
    .catch(function () {
      if (st) { st.style.color = '#FF8A80'; st.textContent = 'Network error - try again'; }
      btns.forEach(function (b) { b.disabled = false; });
    });
  };

  // Builds a small round map pin with an emoji matching the driver's
  // actual vehicle type, so a Car, Motorcycle, and Bajaj driver are
  // visually distinguishable on the map instead of all sharing one
  // generic arrow icon. Matches the exact option values used at
  // driver onboarding (driver/index.html's vehicle-type <select>):
  // "Bajaj", "Motorcycle", "Car" - matched case-insensitively with a
  // few common synonyms as a safety net for older/legacy data.
  function driverIconForVehicleType(vtype) {
    var t = (vtype || '').toLowerCase();
    // Was building a flat emoji-on-colored-circle SVG here instead of
    // using the real gold-toned vehicle photos - the driver's own app
    // switched to those (driver-premium.js) so this looked stuck on an
    // old, plain style even after that fix, since this is an entirely
    // separate marker system on the customer side that was never
    // updated to match. RIDE.vtypeImages already has the same image
    // paths driver.js/driver-premium.js use.
    var imgUrl = null;
    if (t.indexOf('moto') !== -1 || t.indexOf('bike') !== -1 || t.indexOf('motor') !== -1) imgUrl = RIDE.vtypeImages.moto;
    else if (t.indexOf('mini') !== -1 || t.indexOf('van') !== -1 || t.indexOf('bus') !== -1) imgUrl = RIDE.vtypeImages.minibus;
    else if (t.indexOf('car') !== -1 || t.indexOf('sedan') !== -1) imgUrl = RIDE.vtypeImages.car;
    else if (t.indexOf('qute') !== -1) imgUrl = RIDE.vtypeImages.qute;
    else imgUrl = RIDE.vtypeImages.bajaj; // default, matches driver.js's own _driverVtypeImage() fallback
    if (imgUrl && RIDE._vimgOk && RIDE._vimgOk[imgUrl]) {
      return {
        url: imgUrl,
        scaledSize: new google.maps.Size(60, 60),
        anchor: new google.maps.Point(30, 30)
      };
    }
    // The vehicle photos (img/vehicles/*.png) are not part of the repo, so a
    // missing file would leave an invisible marker. Until a photo is verified
    // to load (see _probeVehicleImages), draw the carbon/gold vehicle badge.
    if (window.EtelUI) return EtelUI.vehicleMarker(t);
    return { url: imgUrl || '', scaledSize: new google.maps.Size(44, 44), anchor: new google.maps.Point(22, 22) };
  }
  RIDE._vimgOk = {};
  function _probeVehicleImages() {
    var map = RIDE.vtypeImages || {};
    Object.keys(map).forEach(function (k) {
      var u = map[k]; if (!u) return;
      var im = new Image();
      im.onload = function () { RIDE._vimgOk[u] = true; };
      im.onerror = function () { RIDE._vimgOk[u] = false; };
      im.src = u;
    });
  }
  function updateDriverMarker(rd) {
    if (!rd.driver_lat || !rd.driver_lng || !RIDE.map || !window.google) return;
    var pos = { lat: parseFloat(rd.driver_lat), lng: parseFloat(rd.driver_lng) };
    try {
      if (RIDE.driverMarker) {
        RIDE.driverMarker.setPosition(pos);
        // The vehicle type isn't always present on the very first status
        // poll (driver just accepted, profile join still loading) - once
        // it does show up, or if it changes, swap the icon in rather than
        // leaving the default Bajaj pin stuck for the whole ride.
        if (rd.vehicle_type && RIDE._driverMarkerVtype !== rd.vehicle_type) {
          RIDE.driverMarker.setIcon(driverIconForVehicleType(rd.vehicle_type));
          RIDE._driverMarkerVtype = rd.vehicle_type;
        }
      } else {
        RIDE.driverMarker = new google.maps.Marker({
          position: pos, map: RIDE.map,
          title: rd.vehicle_type ? ('Driver — ' + rd.vehicle_type) : 'Driver',
          zIndex: 998,
          icon: driverIconForVehicleType(rd.vehicle_type)
        });
        RIDE._driverMarkerVtype = rd.vehicle_type || null;
      }
    } catch (e) {}
  }

  // ── NEARBY ONLINE DRIVERS (Book screen, before booking) ────────────
  // Shows online drivers near the pickup point as icons on the map,
  // filtered to the currently-selected vehicle type by default (tap
  // "See All Types" to show every online driver regardless of type).
  // Purely informational - separate from the actual matching/assignment
  // that happens once a ride is booked (that's updateDriverMarker above).
  RIDE.nearbyDriverMarkers = [];
  RIDE.driverMapFilterMode = 'selected'; // 'selected' | 'all'
  RIDE.nearbyDriversPollId = null;

  function clearNearbyDriverMarkers() {
    RIDE.nearbyDriverMarkers.forEach(function (m) { try { m.setMap(null); } catch (e) {} });
    RIDE.nearbyDriverMarkers = [];
  }

  function loadNearbyDrivers() {
    if (!RIDE.map || !window.google) return;
    var center = RIDE.pickupLatLng || (RIDE.map.getCenter ? { lat: RIDE.map.getCenter().lat(), lng: RIDE.map.getCenter().lng() } : null);
    if (!center) return;
    var vtype = RIDE.driverMapFilterMode === 'all' ? 'all' : (RIDE.selectedVtype || 'bajaj');
    fetch(apiBase() + '?action=passenger_get_nearby_drivers&lat=' + encodeURIComponent(center.lat) +
          '&lng=' + encodeURIComponent(center.lng) + '&radius_km=6&vehicle_type=' + encodeURIComponent(vtype),
          { headers: authHeader() })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.success) return;
        clearNearbyDriverMarkers();
        (res.data.drivers || []).forEach(function (d) {
          try {
            var marker = new google.maps.Marker({
              position: { lat: d.lat, lng: d.lng }, map: RIDE.map,
              icon: driverIconForVehicleType(d.vehicle_type), zIndex: 500
            });
            RIDE.nearbyDriverMarkers.push(marker);
          } catch (e) {}
        });
      })
      .catch(function () { /* silent - nice-to-have overlay, not critical path */ });
  }

  function startNearbyDriversPolling() {
    stopNearbyDriversPolling();
    loadNearbyDrivers();
    RIDE.nearbyDriversPollId = setInterval(loadNearbyDrivers, 7000);
  }
  function stopNearbyDriversPolling() {
    if (RIDE.nearbyDriversPollId) { clearInterval(RIDE.nearbyDriversPollId); RIDE.nearbyDriversPollId = null; }
    clearNearbyDriverMarkers();
  }
  window.toggleDriverMapFilter = function () {
    RIDE.driverMapFilterMode = RIDE.driverMapFilterMode === 'all' ? 'selected' : 'all';
    var btn = document.getElementById('driver-map-filter-btn');
    if (btn) {
      btn.textContent = RIDE.driverMapFilterMode === 'all' ? '🗺️ Show Selected Type Only' : '🗺️ See All Types on Map';
    }
    loadNearbyDrivers();
  };

  /* ════════════ DRIVER MODAL ════════════ */
  window.callDriver = function () {
    var phone = RIDE._driverPhone || '';
    if (phone) { window.location.href = 'tel:' + phone.replace(/\s/g,''); }
    else { showToast('Driver phone not available', 'warn'); }
  };

  window.openDriverModal = function () {
    var rd = RIDE.activeRideData || {};
    var name  = rd.driver_name || 'Driver';
    var plate = rd.vehicle_plate || '——';
    var phone = rd.driver_phone || RIDE._driverPhone || '—';
    var rating= parseFloat(rd.driver_rating || 0);
    var photo = rd.driver_photo || '';
    var vtype = rd.vehicle_type || 'Bajaj TukTuk';
    var vcolor = rd.vehicle_color || '';
    var vmake  = rd.vehicle_make || '';
    var vdesc = [vcolor, vmake, vtype].filter(Boolean).join(' ');
    var mn = document.getElementById('modal-name');    if (mn) mn.textContent = name;
    var mr = document.getElementById('modal-rating');  if (mr) mr.textContent = '🏅 ' + (rating ? rating.toFixed(1) : '5.0') + ' · rating';
    var mv = document.getElementById('modal-vehicle'); if (mv) mv.textContent = vdesc;
    var mp = document.getElementById('modal-plate');   if (mp) mp.textContent = plate;
    var mh = document.getElementById('modal-phone');   if (mh) mh.textContent = phone;
    var ma = document.getElementById('modal-avatar');
    if (ma) ma.innerHTML = photo
        ? '<img src="' + escUrl(photo) + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">'
        : '🧑';
    RIDE._modalPlate = plate;
    var bg = document.getElementById('driver-modal-bg');
    if (bg) bg.classList.add('open');
  };

  window.closeDriverModal = function (e) {
    if (e && e.target !== document.getElementById('driver-modal-bg')) return;
    var bg = document.getElementById('driver-modal-bg');
    if (bg) bg.classList.remove('open');
  };

  window.copyPlate = function () {
    var p = RIDE._modalPlate || '';
    if (navigator.clipboard) navigator.clipboard.writeText(p).then(function () { showToast('Plate copied: ' + p, 'success'); });
  };

  /* ════════════ RIDE ISSUES ════════════ */
  window.showRideIssues = function () {
    var bg = document.getElementById('ride-issues-modal-bg');
    if (!bg) return;
    document.querySelectorAll('.ride-issue-cat-btn').forEach(function (b) { b.classList.remove('selected'); });
    var details = document.getElementById('ride-issue-details');
    if (details) details.value = '';
    RIDE._selectedIssueCat = null;
    bg.classList.add('open');
  };
  window.closeRideIssuesModal = function () {
    var bg = document.getElementById('ride-issues-modal-bg');
    if (bg) bg.classList.remove('open');
  };
  window.selectRideIssueCat = function (btn) {
    document.querySelectorAll('.ride-issue-cat-btn').forEach(function (b) { b.classList.remove('selected'); });
    btn.classList.add('selected');
    RIDE._selectedIssueCat = btn.getAttribute('data-cat');
  };
  window.submitRideIssueReport = function () {
    if (!RIDE._selectedIssueCat) { showToast('Please select what happened', 'warn'); return; }
    var btn = document.getElementById('ride-issue-submit-btn');
    var details = document.getElementById('ride-issue-details');
    if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
    fetch(apiBase(), {
      method: 'POST', headers: authHeader(),
      body: JSON.stringify({
        action: 'submit_ride_issue',
        ride_id: RIDE.currentRideId || RIDE.currentRequestId || null,
        category: RIDE._selectedIssueCat,
        details: details ? details.value.trim() : ''
      })
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (btn) { btn.disabled = false; btn.textContent = 'Submit Report'; }
      if (res.success || res.ok) {
        closeRideIssuesModal();
        showToast((res.data && res.data.message) || 'Report submitted — our team will follow up', 'success');
      } else {
        showToast((res.data && res.data.message) || res.message || 'Could not submit report', 'error');
      }
    }).catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = 'Submit Report'; }
      showToast('Network error — could not submit report', 'error');
    });
  };

  /* ════════════ CANCEL REASONS ════════════ */
  window.showCancelReasons = function () {
    /* populate searching addresses into cancel view if needed */
    var sp = document.getElementById('s-pickup-addr');
    var pickup = sp ? sp.textContent : 'Your location';
    showView('cancel');
  };

  window.submitCancel = function (el, reason) {
    if (el) {
      document.querySelectorAll('.reason-chip').forEach(function (c) { c.classList.remove('selected'); });
      el.classList.add('selected');
      /* slight delay so user sees the selection */
      setTimeout(function () { doCancel(reason); }, 350);
    } else {
      var cc = document.getElementById('cancel-comment');
      var extra = cc ? cc.value.trim() : '';
      doCancel(extra ? reason + ': ' + extra : reason);
    }
  };

  function doCancel(reason) {
    var id = RIDE.currentRideId || RIDE.currentRequestId;
    if (!id) { showView('book'); return; }
    /* Wait for the server to confirm before leaving the ride screen -- if the
       driver already arrived and billed the trip in the meantime, cancelling
       is no longer allowed and the customer needs to see the payment screen
       instead of silently walking away thinking they cancelled. */
    fetch(apiBase(), { method: 'POST', headers: authHeader(),
      body: JSON.stringify({ action: 'cancel_ride', id: id, reason: reason || 'Customer cancelled' }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && (d.success || d.ok)) {
          stopPolling();
          try {
            var cancelled = JSON.parse(localStorage.getItem('etel_cancelled_rides') || '[]');
            if (cancelled.indexOf(id) < 0) cancelled.push(id);
            if (cancelled.length > 20) cancelled = cancelled.slice(-20);
            localStorage.setItem('etel_cancelled_rides', JSON.stringify(cancelled));
          } catch (e) {}
          RIDE.currentRequestId = null; RIDE.currentRideId = null; RIDE.activeRideData = null;
          showView('book');
          showToast('Ride cancelled', 'warn');
        } else {
          /* Cancel was rejected -- most likely the driver just arrived. Refresh
             the current ride state instead of assuming it's gone. */
          showToast((d && (d.error || d.message)) || 'Could not cancel — checking trip status…', 'warn');
          fetch(apiBase() + '?action=my_active_ride', { headers: authHeader() })
            .then(function (r) { return r.json(); })
            .then(function (dd) {
              if ((dd.success || dd.ok) && dd.data && dd.data.status === 'arrived') {
                showView('arrived'); renderArrivedCard(dd.data);
              }
            }).catch(function () {});
        }
      })
      .catch(function () {
        showToast('Network error — could not cancel. Please try again.', 'err');
      });
  }

  /* ════════════ CANCEL ════════════ */
  window.cancelRide = function () { showCancelReasons(); };

  /* ════════════ CHECK ACTIVE (on load) ════════════ */
  window.checkActiveRide = function () {
    var cancelledLocally = [];
    try { cancelledLocally = JSON.parse(localStorage.getItem('etel_cancelled_rides') || '[]'); } catch (e) {}
    fetch(apiBase() + '?action=my_active_ride', { headers: authHeader() })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if ((d.success || d.ok) && d.data && d.data.id) {
          var rid = d.data.id;
          /* If user already cancelled this locally, force-cancel server-side and show book view */
          if (cancelledLocally.indexOf(rid) >= 0) {
            fetch(apiBase(), { method: 'POST', headers: authHeader(),
              body: JSON.stringify({ action: 'cancel_ride', id: rid, reason: 'Customer cancelled' }) })
              .catch(function () {});
            showView('book');
            return;
          }
          RIDE.currentRideId = rid;
          var st = d.data.status || '';
          if (st === 'searching' || st === 'pending') { showView('searching'); startPolling(); }
          else if (st === 'accepted' || st === 'active' || st === 'in_progress') { showView('active'); renderActiveRide(d.data); startPolling(); }
          else if (st === 'arrived') { showView('arrived'); renderArrivedCard(d.data); }
          else if (st === 'completed') { showView('rating'); }
          else { showView('book'); }
        } else {
          showView('book');
        }
      })
      .catch(function () { showView('book'); });
  };

  /* ════════════ RATING ════════════ */
  window.setStar = function (n) {
    RIDE.selectedRating = n;
    document.querySelectorAll('.star-btn').forEach(function (b, i) {
      b.textContent = i < n ? '⭐' : '☆';
    });
  };

  window.submitRating = function () {
    if (!RIDE.selectedRating) { showToast('Please select a rating', 'warn'); return; }
    var comment = document.getElementById('rating-comment');
    var payload = { action: 'rate_ride', ride_id: RIDE.currentRideId, rating: RIDE.selectedRating, comment: comment ? comment.value : '' };
    fetch(apiBase(), { method: 'POST', headers: authHeader(), body: JSON.stringify(payload) })
      .then(function (r) { return r.json(); })
      .catch(function () {})
      .finally(function () { _afterRating(); });
  };

  window.skipRating = function () { _afterRating(); };

  function _afterRating() {
    RIDE.currentRideId = null; RIDE.currentRequestId = null; RIDE.selectedRating = 0;
    RIDE.pickupLatLng = null; RIDE.dropoffLatLng = null;
    if (RIDE.pickupMarker)  { try { RIDE.pickupMarker.setMap(null);  } catch (e) {} RIDE.pickupMarker  = null; }
    if (RIDE.dropoffMarker) { try { RIDE.dropoffMarker.setMap(null); } catch (e) {} RIDE.dropoffMarker = null; }
    if (RIDE.driverMarker)  { try { RIDE.driverMarker.setMap(null);  } catch (e) {} RIDE.driverMarker  = null; RIDE._driverMarkerVtype = null; }
    if (RIDE._fbLine)       { try { RIDE._fbLine.setMap(null);       } catch (e) {} RIDE._fbLine       = null; }
    var pi = document.getElementById('pickup-input');  if (pi) pi.value = '';
    var di = document.getElementById('dropoff-input'); if (di) di.value = '';
    var fc = document.getElementById('fare-card');     if (fc) fc.style.display = 'none';
    document.querySelectorAll('.star-btn').forEach(function (b) { b.textContent = '⭐'; });
    var rc = document.getElementById('rating-comment'); if (rc) rc.value = '';
    showView('book');
    showToast('Thanks for riding with ETEL! 🚗', 'success');
  }

  /* ════════════ HISTORY ════════════ */
  function loadHistory() {
    var el = document.getElementById('history-list'); if (!el) return;
    el.innerHTML = '<div style="text-align:center;padding:32px;color:var(--dim)">Loading…</div>';
    fetch(apiBase() + '?action=ride_history', { headers: authHeader() })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var rides = (d.success || d.ok) ? (d.data && d.data.rides ? d.data.rides : (d.data || d.rides || [])) : [];
        if (!rides.length) { el.innerHTML = '<div style="text-align:center;padding:32px;color:var(--dim)">No rides yet</div>'; return; }
        window._rideHistoryCache = rides;
        el.innerHTML = rides.map(function (r, idx) {
          return '<div onclick="showRideDetail(' + idx + ')" style="cursor:pointer;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px;margin-bottom:10px">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">' +
            '<span style="font-size:12px;font-weight:700;color:var(--gold-lt)">' + (r.status || '') + '</span>' +
            '<span style="font-size:11px;color:var(--dim)">' + (r.created_at ? r.created_at.split(' ')[0] : '') + '</span></div>' +
            '<div style="font-size:12px;color:#EDEDF5;margin-bottom:3px">📍 ' + escapeHtml(r.pickup_address || r.pickup_addr || '—') + '</div>' +
            '<div style="font-size:12px;color:#EDEDF5;margin-bottom:8px">🏁 ' + escapeHtml(r.dropoff_address || r.dropoff_addr || '—') + '</div>' +
            '<div style="display:flex;justify-content:space-between;align-items:center">' +
            '<div><div style="font-size:13px;font-weight:700;color:var(--gold-lt)">ETB ' + (parseFloat(r.fare_final||r.fare_paid||r.fare||0)||0).toFixed(0) + '</div>' +
            '<div style="font-size:11px;color:var(--dim);margin-top:2px">' + (parseFloat(r.distance_km||0)).toFixed(2) + ' km · ' + (r.payment_method||'cash') + (r.driver_name?' · Driver: '+escapeHtml(r.driver_name):'') + '</div></div>' +
            '<div style="display:flex;align-items:center;gap:4px;min-height:32px;padding:6px 10px;border-radius:999px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.3);color:var(--gold-lt);font-size:11px;font-weight:700;white-space:nowrap">Details <span style="font-size:13px">›</span></div>' +
            '</div></div>';
        }).join('');
      })
      .catch(function () { el.innerHTML = '<div style="text-align:center;padding:24px;color:var(--dim)">Could not load history</div>'; });
  }

  function showRideDetail(idx) {
    var r = (window._rideHistoryCache || [])[idx];
    if (!r) return;
    var fareRows = [['Final', r.fare_final], ['Paid', r.fare_paid]].filter(function (x) { return x[1] > 0; })
      .map(function (x) { return '<div style="display:flex;justify-content:space-between;padding:4px 0;"><span style="color:#9A9AAE">' + x[0] + '</span><span style="color:var(--gold-lt);font-weight:700">ETB ' + parseFloat(x[1]).toFixed(2) + '</span></div>'; })
      .join('') || '<div style="color:#B8B0A0">No fare recorded</div>';
    var html =
      '<div id="ride-detail-overlay" onclick="if(event.target===this)closeRideDetail()" style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.75);display:flex;align-items:center;justify-content:center;padding:16px;">' +
        '<div style="background:#16121a;border:1px solid rgba(212,175,55,.35);border-radius:14px;width:min(96vw,440px);max-height:85vh;overflow-y:auto;padding:20px;">' +
          '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">' +
            '<div style="font-size:15px;font-weight:800;color:var(--gold-lt)">Ride #' + (r.id||'') + ' &middot; ' + (r.status||'') + '</div>' +
            '<button onclick="closeRideDetail()" style="background:none;border:1px solid #333;color:#B8B0A0;border-radius:6px;padding:4px 10px;cursor:pointer;">✕</button>' +
          '</div>' +
          (r.driver_name ? '<div style="margin-bottom:12px;"><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Driver</div><div style="color:#EDEDF5;font-weight:700">' + escapeHtml(r.driver_name) + '</div><div style="color:#9A9AAE;font-size:12px">' + escapeHtml(r.driver_phone||'') + (r.vehicle_plate ? ' · ' + escapeHtml(r.vehicle_plate) : '') + (r.vehicle_type ? ' · ' + escapeHtml(r.vehicle_type) : '') + '</div></div>' : '') +
          '<div style="margin-bottom:12px;"><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;margin-bottom:4px;">Route</div>' +
          '<div style="color:#EDEDF5;font-size:13px;margin-bottom:3px;">📍 ' + escapeHtml(r.pickup_address||'—') + '</div>' +
          '<div style="color:#EDEDF5;font-size:13px;">🏁 ' + escapeHtml(r.dropoff_address||'—') + '</div></div>' +
          '<div style="display:flex;gap:20px;margin-bottom:12px;">' +
          '<div><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Distance</div><div style="color:#EDEDF5;">' + parseFloat(r.distance_km||r.traveled_km||0).toFixed(2) + ' km</div></div>' +
          '<div><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Payment</div><div style="color:#EDEDF5;">' + (r.payment_method||'cash') + '</div></div>' +
          (r.rating ? '<div><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Your Rating</div><div style="color:#EDEDF5;">★ ' + r.rating + '</div></div>' : '') +
          '</div>' +
          '<div style="border-top:1px solid rgba(212,175,55,.15);padding-top:10px;margin-bottom:14px">' + fareRows + '</div>' +
          (r.fare_total != null ? '<div style="margin-bottom:14px;background:rgba(255,255,255,.02);border:1px solid rgba(212,175,55,.12);border-radius:12px;padding:14px 16px">' + _fareBreakdownHtml(r) + '</div>' : '') +
          '<div style="display:flex;gap:10px;margin-bottom:10px">' +
            (r.pickup_lat ? '<button class="action-btn" style="flex:1;background:rgba(78,205,196,.12);border:1px solid rgba(78,205,196,.3);color:var(--teal)" onclick="navigateTo(' + parseFloat(r.pickup_lat) + ',' + parseFloat(r.pickup_lng) + ')">🗺️ Pickup Nav</button>' : '') +
            (r.dropoff_lat ? '<button class="action-btn" style="flex:1;background:rgba(96,180,255,.10);border:1px solid rgba(96,180,255,.2);color:#60B4FF" onclick="navigateTo(' + parseFloat(r.dropoff_lat) + ',' + parseFloat(r.dropoff_lng) + ')">📍 Dropoff Nav</button>' : '') +
          '</div>' +
          '<button class="action-btn" style="width:100%;background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.3);color:var(--gold-lt)" onclick="showRoutePath(' + (r.id || 0) + ')">🧭 View Route Driven</button>' +
        '</div>' +
      '</div>';
    var wrap = document.createElement('div');
    wrap.id = 'ride-detail-wrap';
    wrap.innerHTML = html;
    document.body.appendChild(wrap);
  }
  function closeRideDetail() {
    var el = document.getElementById('ride-detail-wrap');
    if (el) el.remove();
  }
  window.showRideDetail = showRideDetail;
  window.closeRideDetail = closeRideDetail;

  // Shows the actual GPS breadcrumb trail recorded during a trip - fed
  // by ride_path_points, which only starts recording for rides created
  // after this feature shipped, so older completed rides simply have no
  // points and the map falls back to pickup/dropoff markers only. Works
  // for both a completed ride (from Ride History) and a live one (path
  // so far, called from the active-ride screen).
  function showRoutePath(rideId) {
    if (!rideId) return;
    var overlay = document.createElement('div');
    overlay.id = 'route-path-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.85);display:flex;flex-direction:column';
    overlay.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;background:#16121a;border-bottom:1px solid rgba(212,175,55,.25)">' +
        '<div style="color:var(--gold-lt);font-weight:800;font-size:15px">Route driven</div>' +
        '<button onclick="document.getElementById(\'route-path-overlay\').remove()" style="background:none;border:1px solid #333;color:#B8B0A0;border-radius:6px;padding:4px 10px;cursor:pointer">✕</button>' +
      '</div>' +
      '<div id="route-path-map" style="flex:1"></div>' +
      '<div id="route-path-status" style="padding:10px 16px;color:#9A9AAE;font-size:12px;text-align:center;background:#16121a">Loading route…</div>';
    document.body.appendChild(overlay);

    fetch(apiBase(), { method: 'POST', headers: authHeader(), body: JSON.stringify({ action: 'get_ride_path', ride_id: rideId }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var statusEl = document.getElementById('route-path-status');
        var mapDiv = document.getElementById('route-path-map');
        if (!mapDiv) return; // overlay already closed
        if (!(d.success || d.ok) || !d.data) {
          if (statusEl) statusEl.textContent = 'Could not load route.';
          return;
        }
        var data = d.data;
        var points = (data.points || []).map(function (p) { return { lat: p.lat, lng: p.lng }; });
        var center = points.length ? points[Math.floor(points.length / 2)]
          : (data.pickup_lat ? { lat: data.pickup_lat, lng: data.pickup_lng } : { lat: 7.0504, lng: 38.4955 });
        var map = new google.maps.Map(mapDiv, { center: center, zoom: 14, disableDefaultUI: true, zoomControl: true });
        if (points.length > 1) {
          new google.maps.Polyline({ path: points, map: map, strokeColor: '#D4AF37', strokeWeight: 4, strokeOpacity: .9 });
          var bounds = new google.maps.LatLngBounds();
          points.forEach(function (p) { bounds.extend(p); });
          map.fitBounds(bounds, 40);
        }
        if (data.pickup_lat && data.pickup_lng) {
          new google.maps.Marker({ position: { lat: data.pickup_lat, lng: data.pickup_lng }, map: map, label: 'A', title: 'Pickup' });
        }
        if (data.dropoff_lat && data.dropoff_lng) {
          new google.maps.Marker({ position: { lat: data.dropoff_lat, lng: data.dropoff_lng }, map: map, label: 'B', title: 'Dropoff' });
        }
        if (statusEl) statusEl.textContent = points.length ? (points.length + ' recorded points') : 'No route recorded for this trip (started before route tracking was added, or driver never moved).';
      })
      .catch(function (err) {
        var statusEl = document.getElementById('route-path-status');
        if (statusEl) statusEl.textContent = 'Could not load route: ' + (err && err.message || 'network error');
      });
  }
  window.showRoutePath = showRoutePath;

  // ride.js never had its own navigateTo (driver.js's version drives an
  // in-app directions renderer tied to the live map, which doesn't apply
  // in a modal popup like Ride History) - opening Google Maps externally
  // works from anywhere without needing a map instance already loaded.
  function navigateTo(lat, lng) {
    if (!lat || !lng) { if (typeof toast === 'function') toast('No coordinates for this location'); return; }
    window.open('https://www.google.com/maps/dir/?api=1&destination=' + lat + ',' + lng, '_blank');
  }
  window.navigateTo = navigateTo;

  /* ════════════ HELPERS ════════════ */
  // Passenger safety tips - drivers already have a full Learning Hub with
  // safety content (js/driver.js DRIVER_LEARNING_CONTENT.safety); passengers
  // had nothing proactive, only a reactive "report a safety concern" button.
  // One rotates in on the searching screen while they wait.
  var RIDE_SAFETY_TIPS = [
    'Check that the driver\u2019s name and plate number match what\u2019s shown in the app before getting in.',
    'Share your trip with a friend or family member so someone knows where you are.',
    'Keep your phone charged and easy to reach during the ride.',
    'If anything feels off before or during a trip, use the Issue button to reach support right away.',
    'Wait somewhere well-lit and visible, especially after dark.'
  ];

  function showView(name) {
    document.querySelectorAll('.ride-view').forEach(function (v) { v.style.display = 'none'; });
    document.querySelectorAll('.ride-tab').forEach(function (b) { b.classList.remove('active'); });
    var view = document.getElementById('view-' + name);
    if (view) view.style.display = 'block';
    var btn = document.querySelector('.ride-tab[data-tab="' + name + '"]');
    if (btn) btn.classList.add('active');
    if (name === 'searching') {
      var tipEl = document.getElementById('searching-safety-tip');
      if (tipEl) tipEl.textContent = RIDE_SAFETY_TIPS[Math.floor(Math.random() * RIDE_SAFETY_TIPS.length)];
      /* Auto-collapse the bottom sheet the moment a search starts, so the
         rider sees the full map (hex grid, nearby drivers, pickup pin)
         right away instead of having to manually tap the collapse arrow
         every time. Reuses the exact same toggleSheet() the manual button
         calls - same collapsed state, same "▲/▼" indicator - just
         triggered automatically instead of waiting for a tap. Only fires
         if the sheet isn't already collapsed, so it never fights a rider
         who already had it collapsed or re-expanded it themselves. */
      if (!window._sheetCollapsed && typeof window.toggleSheet === 'function') {
        setTimeout(function () { if (!window._sheetCollapsed) window.toggleSheet(); }, 300);
      }
    }
    // The nearby-drivers map overlay only makes sense while actually
    // browsing to book - stop it the moment we leave that view (covers
    // a successful booking, and restoring an already-active ride at
    // page load), and resume it if we land back on 'book'.
    if (name === 'book') startNearbyDriversPolling(); else stopNearbyDriversPolling();
  }

  function showToast(msg, type, durationMs) {
    var box = document.getElementById('ride-toasts'); if (!box) return;
    var t = document.createElement('div');
    var bg = type === 'error' ? '#c62828' : type === 'warn' ? '#e65100' : '#2e7d32';
    t.style.cssText = 'background:' + bg + ';color:#fff;padding:12px 18px;border-radius:14px;margin-bottom:8px;font-family:Outfit,sans-serif;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.4);cursor:pointer';
    t.textContent = msg;
    box.appendChild(t);
    var ms = (typeof durationMs === 'number' && durationMs > 0) ? durationMs : 3500;
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, ms);
    return t;
  }

  /* ════════════ SHEET COLLAPSE ════════════ */
  window._sheetCollapsed = false;
  window.toggleSheet = function() {
    var sheet = document.querySelector('.ride-sheet');
    if (!sheet) return;
    _sheetCollapsed = !_sheetCollapsed;
    sheet.style.transform = _sheetCollapsed ? 'translateY(calc(100% - 52px))' : 'translateY(0)';
    var btn = document.getElementById('sheet-toggle-btn');
    if (btn) btn.textContent = _sheetCollapsed ? '▲' : '▼';
  };

  /* ════════════ DOM READY ════════════ */
  function whenReady(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  whenReady(function () {
    /* Auth gate - without this, an unauthenticated visitor just sees a
       blank/placeholder "Rider" profile (0 rides, no name, '?' avatar)
       instead of being sent to log in. */
    if (!Auth.isLoggedIn()) {
      window.location.href = ETEL.api.replace('api/boot.php', '') + 'login.html?redirect=ride/';
      return;
    }
    /* Set user name in header */
    try {
      var u = Auth.getUser ? Auth.getUser() : null;
      if (!u && localStorage.getItem('etel_user')) u = JSON.parse(localStorage.getItem('etel_user'));
      var nm = document.getElementById('user-name');
      if (nm && u) nm.textContent = u.name || u.phone || 'Rider';
    } catch (e) {}
    /* Default payment selection */
    selectPayment('cash');
    // Replaces the static Bajaj/Moto/Car chips (plain emoji, hardcoded
    // in ride/index.html) with the live, admin-configured vehicle type
    // list - including the real gold-toned vehicle photos the driver's
    // Update Vehicle Info panel already shows, instead of leaving the
    // static emoji-only placeholder chips in place forever. This call
    // was present in the deployed bundle but missing from this source
    // file, so the panel worked in the driver app but never actually
    // ran here.
    loadVehicleTypeChips();
    // Keep availability current without a page reload - a driver going
    // online/offline while the customer sits on this screen shouldn't
    // leave a stale greyed-out (or wrongly-selectable) chip in place.
    // Guarded against a duplicate interval if this init block ever runs
    // more than once. Same 20s cadence as the admin live-driver map.
    if (!RIDE._vtypeRefreshTimer) {
        RIDE._vtypeRefreshTimer = setInterval(loadVehicleTypeChips, 20000);
    }
    initSlideBook();
  });

  /* ── Slide-to-request-ride: same drag mechanics as the Driver app's
     slide-to-go-online, calling the existing bookRide() unchanged. ── */
  function initSlideBook() {
    var track = document.getElementById('slide-book-track');
    var thumb = document.getElementById('slide-book-thumb');
    var fill  = document.getElementById('slide-book-fill');
    if (!track || !thumb) return;
    var dragging = false, startX = 0, progress = 0;

    function getClientX(e) { return e.touches ? e.touches[0].clientX : e.clientX; }
    function onStart(e) {
      dragging = true; startX = getClientX(e); progress = 0;
      e.preventDefault();
    }
    function onMove(e) {
      if (!dragging) return;
      var maxX = track.offsetWidth - thumb.offsetWidth - 8;
      if (maxX <= 0) return;
      var dx = startX - getClientX(e); // positive when sliding LEFT (RTL, matches Driver app)
      dx = Math.max(0, Math.min(dx, maxX));
      progress = dx / maxX;
      thumb.style.transform = 'translateX(' + (-dx) + 'px)';
      if (fill) fill.style.transform = 'scaleX(' + progress + ')';
      e.preventDefault();
    }
    function onEnd() {
      if (!dragging) return;
      dragging = false;
      if (progress >= 0.75) {
        var maxX = track.offsetWidth - thumb.offsetWidth - 8;
        thumb.style.transform = 'translateX(' + (-maxX) + 'px)';
        if (fill) fill.style.transform = 'scaleX(1)';
        setTimeout(function () {
          bookRide();
        }, 180);
      } else {
        _resetRideSlide();
        progress = 0;
      }
    }
    track.addEventListener('mousedown', onStart);
    track.addEventListener('touchstart', onStart, { passive: false });
    document.addEventListener('mousemove', onMove);
    document.addEventListener('touchmove', onMove, { passive: false });
    document.addEventListener('mouseup', onEnd);
    document.addEventListener('touchend', onEnd);
  }

  function _resetRideSlide() {
    var thumb = document.getElementById('slide-book-thumb');
    var fill  = document.getElementById('slide-book-fill');
    if (thumb) { thumb.style.transition = 'transform .25s'; thumb.style.transform = 'translateX(0)'; setTimeout(function () { thumb.style.transition = ''; }, 260); }
    if (fill)  { fill.style.transform = 'scaleX(0)'; }
  }

  /* == Full-map toggle ==
     Unlike Driver, ride-map is already a fixed full-screen background
     (z-index:0) with the header + sheet floating on top - so "full map"
     here just means hiding those two overlays, not resizing the map. */
  window._rideSheetCollapsed = false;
  window.toggleRideSheet = function () {
    window._rideSheetCollapsed = !window._rideSheetCollapsed;
    var header = document.querySelector('.ride-header');
    var sheet  = document.querySelector('.ride-sheet');
    var btn    = document.getElementById('ride-map-expand-btn');
    if (window._rideSheetCollapsed) {
      if (header) { header.style.opacity = '0'; header.style.pointerEvents = 'none'; }
      if (sheet)  { sheet.style.opacity = '0'; sheet.style.pointerEvents = 'none'; }
      if (btn)    { btn.innerHTML = '✕'; btn.title = 'Show panel'; }
      var fab = document.getElementById('ride-map-fab');
      if (!fab) {
        fab = document.createElement('button');
        fab.id = 'ride-map-fab';
        fab.style.cssText = 'position:fixed;bottom:30px;left:50%;transform:translateX(-50%);z-index:12;' +
          'background:rgba(212,175,55,.95);color:#0d0d0d;padding:10px 22px;border-radius:999px;' +
          'font-family:Outfit,sans-serif;font-size:13px;font-weight:800;cursor:pointer;border:none;' +
          'box-shadow:0 6px 20px rgba(0,0,0,.4);display:none;white-space:nowrap;';
        fab.textContent = '🗺 Full Map — tap to restore panel';
        fab.onclick = function () { window.toggleRideSheet(); };
        document.body.appendChild(fab);
      }
      fab.style.display = 'block';
    } else {
      if (header) { header.style.opacity = '1'; header.style.pointerEvents = ''; }
      if (sheet)  { sheet.style.opacity = '1'; sheet.style.pointerEvents = ''; }
      if (btn)    { btn.innerHTML = '⤢'; btn.title = 'Full map'; }
      var fab2 = document.getElementById('ride-map-fab');
      if (fab2) fab2.style.display = 'none';
    }
  };

  /* ════════════ STREET-HAIL WATCHER ════════════
     If a driver links this customer via Street Hail (phone/QR),
     the ride appears here automatically within ~10 seconds.
     Polls regardless of which tab the customer is on, so they
     don't have to be on the Book view to see the link. */
  setInterval(function () {
    if (RIDE.currentRideId || RIDE.currentRequestId) return; // already in a flow
    fetch(apiBase() + '?action=my_active_ride', { headers: authHeader() })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if ((d.success || d.ok) && d.data && d.data.id) {
          var st = d.data.status || '';
          if (st === 'accepted' || st === 'in_progress' || st === 'active') {
            RIDE.currentRideId = d.data.id;
            showToast('🚖 Driver ' + (d.data.driver_name || '') + ' linked your ride!', 'ok');
            showView('active');
            renderActiveRide(d.data);
            startPolling();
          }
        }
      })
      .catch(function () {});
  }, 5000);


  /* ---- Reconciled functions (must live inside this IIFE - they call
     apiBase()/authHeader(), which are private to this closure) ---- */
/* ---- Reconciled from ride-bundle.js (same drift as driver-bundle.js -
   see build-bundles.js's header). ---- */
function _syncCustomerWaitState(e){
  if(!e||(e.wait_active==null&&!(e.wait_minutes>0))){
    var box=document.getElementById("a-wait-box");if(box)box.style.display="none";
    if(RIDE._waitTickInterval){clearInterval(RIDE._waitTickInterval);RIDE._waitTickInterval=null}
    return
  }
  RIDE._waitRide=e;RIDE._waitBaselineMinutes=e.wait_minutes||0;RIDE._waitBaselineMs=Date.now();
  var active=!!e.wait_active;
  if(active&&!RIDE._waitTickInterval){RIDE._waitTickInterval=setInterval(_updateCustomerWaitBox,1000)}
  else if(!active&&RIDE._waitTickInterval){clearInterval(RIDE._waitTickInterval);RIDE._waitTickInterval=null}
  _updateCustomerWaitBox()
}

function _updateCustomerWaitBox(){
  var box=document.getElementById("a-wait-box"),val=document.getElementById("a-wait-val");
  if(!box||!val||!RIDE._waitRide)return;
  var r=RIDE._waitRide,active=!!r.wait_active;
  if(!active&&!(r.wait_minutes>0)){box.style.display="none";return}
  box.style.display="flex";
  var elapsed=(RIDE._waitBaselineMinutes||0)*60;
  if(active&&RIDE._waitBaselineMs){elapsed+=(Date.now()-RIDE._waitBaselineMs)/1000}
  var etb=parseFloat(r.wait_etb||0).toFixed(0);
  val.textContent=active?(_fmtWaitClock(elapsed)+" · +ETB "+etb):("Stopped · +ETB "+etb+" total")
}

function _updateFareBreakdown(e){
  var box=document.getElementById("a-fare-breakdown");
  if(!box)return;
  if(e.fare_total==null){box.style.display="none";return}
  box.style.display="block";
  box.innerHTML=_fareBreakdownHtml(e)
}

function loadVehicleTypeChips(){
    var el = document.getElementById("vtype-row");
    if (!el) return;
    fetch(apiBase() + "?action=ride_get_vehicle_types", { headers: authHeader() })
        .then(function (r) { return r.json(); })
        .then(function (t) {
            if (!(t.success || t.ok) || !t.data || !t.data.vehicle_types || !t.data.vehicle_types.length) return;
            var types = t.data.vehicle_types, baseFares = t.data.base_fares || {};
            var avail = t.data.driver_availability || {};
            // availability_known === false means the server-side availability
            // query itself failed - fall back to "allow everything" rather
            // than reading the seeded-zero counts as real unavailability
            // (matches the backend's own stated safe-default intent).
            var availKnown = t.data.availability_known !== false;
            types.forEach(function (e) {
                if (!RIDE.vtypeIcons[e]) RIDE.vtypeIcons[e] = vtGuessIcon(e);
                if (!RIDE.vtypeTitles[e]) RIDE.vtypeTitles[e] = vtGuessTitle(e);
            });
            function isAvailable(vt) { return !availKnown || (parseInt(avail[vt] || 0, 10) > 0); }
            var sel = RIDE.selectedVtype || "bajaj";
            var previouslySelected = sel;
            // Don't let a customer land on (or stay on) a type with nobody
            // online to serve it - switch to the first type that actually
            // has an available driver instead of silently letting them book
            // toward a ride that will just sit in "searching" forever.
            if (types.indexOf(sel) === -1 || !isAvailable(sel)) {
                var firstAvailable = types.filter(isAvailable)[0];
                sel = firstAvailable || types[0];
            }
            RIDE.selectedVtype = sel;
            // This function runs on a 20s background timer (see the
            // setInterval call at init) as well as on initial load, fully
            // independent of any tap on the vtype chips. If the
            // availability check above silently moved RIDE.selectedVtype
            // to a different type than what was showing, the fare card
            // was previously left stale - it only got recalculated when
            // the user happened to tap a chip themselves. Real bug: the
            // fare display could silently desync from RIDE.selectedVtype
            // up to 20s after a real switch, with no user action, and no
            // visible error - it just looked like "switching vtypes
            // doesn't reliably update the fare."
            if (sel !== previouslySelected && typeof window.refreshFareDisplay === 'function') {
                refreshFareDisplay();
            }
            el.innerHTML = types.map(function (e) {
                var available = isAvailable(e);
                var cls = "vtype-chip" + (e === sel ? " active" : "") + (available ? "" : " vtype-chip-disabled");
                var price = baseFares[e] != null ? "fr ETB " + Math.round(baseFares[e]) : "";
                var iconHtml = RIDE.vtypeImages[e]
                    ? '<img class="vt-icon-img" src="' + RIDE.vtypeImages[e] + '" alt="" loading="lazy">'
                    : RIDE.vtypeIcons[e];
                var clickAttr = available ? ' onclick="selectVtype(this,\'' + e + '\')"' : '';
                var disabledStyle = available ? '' : ' style="opacity:.4;filter:grayscale(1);cursor:not-allowed;pointer-events:none"';
                var badge = available ? '' : '<div class="vt-unavailable-badge" style="font-size:9.5px;color:#FF8A80;margin-top:2px;white-space:nowrap">No drivers online</div>';
                return '<div class="' + cls + '" data-vtype="' + e + '"' + clickAttr + disabledStyle + '>'
                    + '<div class="vt-icon">' + iconHtml + '</div>'
                    + '<div class="vt-name">' + RIDE.vtypeTitles[e] + '</div>'
                    + (price ? '<div class="vt-price">' + price + '</div>' : '')
                    + badge
                    + '</div>';
            }).join("");
        })
        .catch(function () {});
}

// Vehicle-type icon/title/image lookups for the booking screen's picker
// grid - three property assignments onto the existing RIDE object, all
// missed by the earlier reconciliation (I caught vtypeImages on a later
// pass but missed that vtypeIcons and vtypeTitles sit in the same
// three-statement block immediately before it). Without vtypeIcons/
// vtypeTitles specifically, loadVehicleTypeChips() replaces the static
// HTML chips with dynamically-built ones that have blank icons and
// blank names - which is exactly what showed up on the Book screen.
RIDE.vtypeIcons={bajaj:"🛺",moto:"🛵",car:"🚗",minibus:"🚐"};
RIDE.vtypeTitles={bajaj:"Bajaj",moto:"Moto",car:"Car",minibus:"Minibus"};
RIDE.vtypeImages={bajaj:"../img/vehicles/bajaj.png",moto:"../img/vehicles/moto.png",car:"../img/vehicles/car.png",minibus:"../img/vehicles/minibus.png",qute:"../img/vehicles/qute.png"};

function vtGuessIcon(e){var t=(e||"").toLowerCase();if(t.indexOf("moto")!==-1||t.indexOf("bike")!==-1||t.indexOf("scoot")!==-1)return"🛵";if(t.indexOf("mini")!==-1||t.indexOf("van")!==-1||t.indexOf("bus")!==-1)return"🚐";if(t.indexOf("car")!==-1||t.indexOf("sedan")!==-1)return"🚗";if(t.indexOf("bajaj")!==-1||t.indexOf("rickshaw")!==-1||t.indexOf("tuk")!==-1||t.indexOf("three")!==-1)return"🛺";return"🚙"}

function vtGuessTitle(e){return(e||"").split("_").map(function(e){return e.charAt(0).toUpperCase()+e.slice(1)}).join(" ")}

function _fareBreakdownHtml(e){
  var base=parseFloat(e.fare_base||0),dist=parseFloat(e.fare_distance||0),wait=parseFloat(e.fare_wait||0);
  var subtotal=parseFloat(e.fare_subtotal!=null?e.fare_subtotal:(base+dist+wait));
  var surgeMult=parseFloat(e.fare_surge_mult||1),surgeEtb=parseFloat(e.fare_surge_etb||0);
  var floorTopup=parseFloat(e.fare_floor_topup||0);
  var total=parseFloat(e.fare_total!=null?e.fare_total:(e.meter_fare!=null?e.meter_fare:e.fare));
  var km=parseFloat(e.distance_km!=null&&e.status==="arrived"?e.distance_km:(e.traveled_km||0));
  var waitMin=parseFloat(e.wait_minutes||0);
  var row=function(label,sub,val,color){return '<div style="display:flex;justify-content:space-between;align-items:baseline;font-size:12.5px;padding:3px 0;color:'+(color||"var(--dim)")+'"><span>'+label+(sub?' <span style="opacity:.7;font-size:11px">'+sub+"</span>":"")+'</span><span style="color:var(--text)">ETB '+val.toFixed(0)+"</span></div>"};
  var html='<div style="font-size:10.5px;font-weight:700;color:var(--dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px">🧾 Fare Breakdown</div>'+
    row("Base fare",null,base)+
    row("Distance",km.toFixed(2)+" km",dist);
  if(wait>0)html+=row("Waiting",waitMin.toFixed(0)+" min",wait,"#FFB74D");
  html+='<div style="border-top:1px solid rgba(255,255,255,.08);margin-top:4px;padding-top:6px">'+row("Subtotal",null,subtotal)+"</div>";
  if(surgeMult>1&&surgeEtb>0)html+=row("Surge ("+surgeMult.toFixed(1)+"×)",null,surgeEtb,"#FFB74D");
  if(floorTopup>0)html+=row("Minimum fare applied",null,floorTopup,"#4ECDC4");
  html+='<div style="display:flex;justify-content:space-between;font-size:14px;font-weight:800;padding-top:6px;margin-top:4px;border-top:1px solid rgba(212,175,55,.22)"><span>Total</span><span style="color:var(--gold-lt)">ETB '+total.toFixed(0)+"</span></div>";
  return html
}

function _fmtWaitClock(e){e=Math.max(0,Math.floor(e));var t=Math.floor(e/60),n=e%60;return(t<10?"0":"")+t+":"+(n<10?"0":"")+n}
})();

