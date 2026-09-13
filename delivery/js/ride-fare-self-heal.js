/* ETEL Ride - fare display self-heal (v1.0)
 * Real bug found via live device debugging: RIDE.lastRouteKm (the real
 * road-route distance, set by calcRouteIfReady() via setPickup/
 * setDropoff) was staying null in real usage even after both pickup and
 * dropoff coordinates were genuinely populated. Confirmed via a device
 * watcher: pickupLatLng and dropoffLatLng both had real values, but
 * lastRouteKm never changed from null - meaning calcRouteIfReady()
 * never actually ran for however dropoff ended up getting set on this
 * flow (auto-geolocation re-firing, a possible Quick Zones path, or
 * some other route into RIDE.dropoffLatLng that doesn't go through the
 * tracked window.setDropoff/calcRouteIfReady chain).
 *
 * Rather than patch every possible entry point individually (fragile -
 * a new one could always turn up), this is a general self-healing net:
 * whenever selectVtype() runs (or on a periodic safety check) and both
 * coordinates exist but lastRouteKm doesn't, proactively call
 * calcRouteIfReady() so a real route + fare actually gets computed. If
 * lastRouteKm was already correctly set, this is a harmless no-op call.
 *
 * Own file, doesn't edit ride-bundle.js directly.
 */
(function () {
  "use strict";

  function readyToRoute() {
    return window.RIDE && RIDE.pickupLatLng && RIDE.dropoffLatLng;
  }

  function selfHeal() {
    if (!readyToRoute()) return;
    if (RIDE.lastRouteKm === null || RIDE.lastRouteKm === undefined) {
      if (typeof window.calcRouteIfReady === "function") {
        window.calcRouteIfReady();
      }
    }
  }

  function wrapWhenReady() {
    if (typeof window.selectVtype !== "function" || typeof window.calcRouteIfReady !== "function") {
      setTimeout(wrapWhenReady, 300);
      return;
    }
    if (window.__etelFareSelfHealWrapped) return;
    window.__etelFareSelfHealWrapped = true;

    var originalSelectVtype = window.selectVtype;
    window.selectVtype = function (el, type) {
      var result = originalSelectVtype(el, type);
      // If lastRouteKm is missing, refreshFareDisplay() (called inside
      // the original selectVtype) will have silently done nothing - heal
      // it right after, so this exact tap still gets a real update.
      selfHeal();
      return result;
    };

    // Periodic safety net independent of vtype taps too - covers the
    // case where coordinates get set (by whatever path) and the person
    // never touches the vehicle type chips at all before booking, so
    // the very first fare shown is already correct rather than needing
    // a tap to trigger the heal.
    setInterval(selfHeal, 2000);
  }

  wrapWhenReady();
})();
