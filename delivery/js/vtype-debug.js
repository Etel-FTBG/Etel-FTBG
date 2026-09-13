/* TEMPORARY DEBUG - wraps window.selectVtype to show exactly what's
 * happening when a vehicle type chip is tapped: what RIDE.lastRouteKm
 * and RIDE.selectedVtype actually hold, and whether the fare-refresh
 * fetch fires and what it returns. Remove this file once the vehicle-
 * type-switch fare bug is found and fixed - it's not a real feature.
 */
(function () {
  "use strict";
  function wrapWhenReady() {
    if (typeof window.selectVtype !== "function") {
      setTimeout(wrapWhenReady, 300);
      return;
    }
    if (window.__etelVtypeDebugWrapped) return;
    window.__etelVtypeDebugWrapped = true;

    var originalSelectVtype = window.selectVtype;
    var originalFetch = window.fetch;

    window.fetch = function (input, init) {
      var url = typeof input === "string" ? input : (input && input.url) || "";
      if (url.indexOf("ride_estimate_fare") !== -1) {
        console.log("[VTYPE DEBUG] fetch fired:", url);
        return originalFetch(input, init).then(function (resp) {
          resp.clone().json().then(function (data) {
            var msg = "[VTYPE DEBUG] ride_estimate_fare response:\n" + JSON.stringify(data, null, 2);
            console.log(msg);
            window.__etelLastFareDebug = { url: url, data: data };
            alert(msg);
          }).catch(function () {});
          return resp;
        });
      }
      return originalFetch(input, init);
    };

    window.selectVtype = function (el, type) {
      var before = {
        lastRouteKm: window.RIDE ? RIDE.lastRouteKm : "no RIDE",
        selectedVtypeBefore: window.RIDE ? RIDE.selectedVtype : "no RIDE",
        pickupLatLng: window.RIDE ? JSON.stringify(RIDE.pickupLatLng) : "no RIDE",
        dropoffLatLng: window.RIDE ? JSON.stringify(RIDE.dropoffLatLng) : "no RIDE"
      };
      var result = originalSelectVtype(el, type);
      var after = { selectedVtypeAfter: window.RIDE ? RIDE.selectedVtype : "no RIDE" };
      var msg = "[VTYPE DEBUG] selectVtype('" + type + "') called\nBefore: " + JSON.stringify(before) + "\nAfter: " + JSON.stringify(after);
      console.log(msg);
      alert(msg);
      return result;
    };

    if (typeof window.calcRouteIfReady === "function") {
      var originalCalcRoute = window.calcRouteIfReady;
      window.calcRouteIfReady = function () {
        alert("[VTYPE DEBUG] calcRouteIfReady() was called - pickup=" + JSON.stringify(RIDE.pickupLatLng) + " dropoff=" + JSON.stringify(RIDE.dropoffLatLng));
        return originalCalcRoute.apply(this, arguments);
      };
    } else {
      alert("[VTYPE DEBUG] window.calcRouteIfReady does not exist as a global function");
    }

    if (typeof window.setPickup === "function") {
      var originalSetPickup = window.setPickup;
      window.setPickup = function () {
        alert("[VTYPE DEBUG] setPickup() was called with: " + JSON.stringify(Array.prototype.slice.call(arguments)));
        return originalSetPickup.apply(this, arguments);
      };
    } else {
      alert("[VTYPE DEBUG] window.setPickup does not exist");
    }
    if (typeof window.setDropoff === "function") {
      var originalSetDropoff = window.setDropoff;
      window.setDropoff = function () {
        alert("[VTYPE DEBUG] setDropoff() was called with: " + JSON.stringify(Array.prototype.slice.call(arguments)));
        return originalSetDropoff.apply(this, arguments);
      };
    } else {
      alert("[VTYPE DEBUG] window.setDropoff does not exist");
    }

    // Direct value watcher - catches ANY change to these, regardless of
    // which (possibly unknown/differently-named) code path caused it.
    var lastSeen = { pickup: null, dropoff: null, routeKm: null };
    setInterval(function () {
      if (!window.RIDE) return;
      var p = JSON.stringify(RIDE.pickupLatLng);
      var d = JSON.stringify(RIDE.dropoffLatLng);
      var r = RIDE.lastRouteKm;
      if (p !== lastSeen.pickup) {
        alert("[VTYPE DEBUG] RIDE.pickupLatLng changed to: " + p);
        lastSeen.pickup = p;
      }
      if (d !== lastSeen.dropoff) {
        alert("[VTYPE DEBUG] RIDE.dropoffLatLng changed to: " + d);
        lastSeen.dropoff = d;
      }
      if (r !== lastSeen.routeKm) {
        alert("[VTYPE DEBUG] RIDE.lastRouteKm changed to: " + r);
        lastSeen.routeKm = r;
      }
    }, 500);
  }
  wrapWhenReady();
})();
