/* ============================================================
   ETEL Ride — Quote-Token Capture & Attach Fix
   ------------------------------------------------------------
   Real bug: the backend (RideController.php) implements a complete
   price-lock system - ride_estimate_fare signs a quote_token binding
   the exact fare/km/vehicle-type, and book_ride is supposed to
   receive it back to trust that exact quote instead of recomputing
   live (which could differ if a surge window started in the gap
   between quote and confirm). The frontend never implemented either
   half: it discards quote_token from the estimate response, and
   never includes it when booking. Net effect: ANY booking attempted
   while a peak-hour/surge window is active gets rejected with 409
   every single time, with no way to recover by retrying, since the
   condition that blocks it never changes.

   Fixed here by intercepting window.fetch rather than trying to
   locate and safely override internal closures inside the minified
   bundle (the booking submission logic isn't exposed as a named
   global the way switchRideTab is) - this works regardless of the
   bundle's internal function organization.
   ============================================================ */

(function () {
  let lastQuoteToken = null;
  let lastQuoteKm = null;
  let lastQuoteVtype = null;

  const _originalFetch = window.fetch;

  window.fetch = function (input, init) {
    const url = typeof input === 'string' ? input : (input && input.url) || '';

    // Capture: whenever a fare estimate comes back, remember its
    // token - this is the piece the app never did at all before.
    if (url.indexOf('action=ride_estimate_fare') !== -1) {
      return _originalFetch.apply(this, arguments).then(function (res) {
        const cloned = res.clone();
        cloned.json().then(function (data) {
          if (data && data.success && data.data && data.data.quote_token) {
            lastQuoteToken = data.data.quote_token;
            lastQuoteKm = data.data.distance_km;
            lastQuoteVtype = data.data.vehicle_type;
          }
        }).catch(function () {});
        return res;
      });
    }

    // Attach: inject the most recent quote_token (and the real routed
    // km, via RIDE.lastRouteKm - also never sent before, and used by
    // the backend's own route-distance accuracy check) into the
    // booking request right before it goes out.
    if (url.indexOf('action=book_ride') !== -1 && init && init.body) {
      try {
        const bodyObj = JSON.parse(init.body);
        const routeStillMatches = window.RIDE && RIDE.lastRouteKm != null && lastQuoteKm != null
          && Math.abs(RIDE.lastRouteKm - lastQuoteKm) < 0.05; // small float-precision allowance, not a real distance change
        if (lastQuoteToken && bodyObj.vehicle_type === lastQuoteVtype && routeStillMatches) {
          bodyObj.quote_token = lastQuoteToken;
        }
        if (window.RIDE && RIDE.lastRouteKm != null && !bodyObj.route_km) {
          bodyObj.route_km = RIDE.lastRouteKm;
        }
        init = Object.assign({}, init, { body: JSON.stringify(bodyObj) });
      } catch (e) { /* if parsing fails, fall through and send the original request unmodified */ }

      return _originalFetch.call(this, input, init).then(function (res) {
        if (res.status === 409) {
          // Self-healing recovery: the specific scenario this status
          // guards against is "your quote is stale, here's what
          // actually changed" - rather than leaving the rider stuck
          // (retrying would fail identically forever, since nothing
          // about their held state changes on its own), fetch one
          // fresh quote in the background so a second tap on "slide
          // to request ride" succeeds with a valid token, matching
          // what the error message itself already promises ("review
          // the updated fare and confirm again").
          if (window.RIDE && RIDE.lastRouteKm != null && RIDE.selectedVtype) {
            const refreshUrl = (window.ETEL && ETEL.api ? ETEL.api : '/delivery/api/boot.php')
              + '?action=ride_estimate_fare&distance_km=' + encodeURIComponent(RIDE.lastRouteKm)
              + '&vehicle_type=' + encodeURIComponent(RIDE.selectedVtype)
              + (RIDE.lastRouteIsManual ? '&open_trip=1' : '');
            let authHeader = {};
            try {
              const t = (typeof Auth !== 'undefined' && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || '');
              if (t) authHeader = { Authorization: 'Bearer ' + t };
            } catch (e) {}
            _originalFetch(refreshUrl, { headers: authHeader }).then(r => r.json()).then(data => {
              if (data && data.success && data.data) {
                lastQuoteToken = data.data.quote_token;
                lastQuoteKm = data.data.distance_km;
                lastQuoteVtype = data.data.vehicle_type;
                if (typeof window.refreshFareDisplay === 'function') window.refreshFareDisplay();
              }
            }).catch(() => {});
          }
        }
        return res;
      });
    }

    return _originalFetch.apply(this, arguments);
  };
})();
