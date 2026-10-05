/* ============================================================
   ETEL Delivery & Ride - pause background polling  v1.0
   Load FIRST (before any app script) on the customer and rider pages only.

   Live API timings showed about a quarter of all calls were riders' phones
   polling (active ride, chat unread, nearby drivers) while the app sat in the
   background. This wraps setInterval so a STEADY poll (3 s to 60 s period) is
   skipped while the page is hidden, and runs once immediately when the page
   becomes visible again - the user sees fresh data the moment they return.

   Deliberately NOT touched:
     - timers under 3 s (UI ticks, countdowns, the wait-fee clock)
     - timers over 60 s (the 2-minute ride safety check-in must keep running)
     - the driver app (it must keep reporting its location) - do not include
       this file there.
   Opt a page out with: window.ETEL_NO_VISIBILITY_THROTTLE = true  (set before load)
   ============================================================ */
(function () {
  'use strict';
  if (window.ETEL_NO_VISIBILITY_THROTTLE || window.__etelVisThrottle) return;
  window.__etelVisThrottle = true;

  var MIN_MS = 3000, MAX_MS = 60000;
  var nativeSet = window.setInterval, nativeClear = window.clearInterval;
  var wrappers = {};   // timer id -> { onVisible }

  window.setInterval = function (fn, ms) {
    var extra = Array.prototype.slice.call(arguments, 2);
    if (typeof fn !== 'function' || !(ms >= MIN_MS && ms <= MAX_MS)) {
      return nativeSet.apply(window, arguments);
    }
    var skipped = false;
    var run = function () { try { fn.apply(window, extra); } catch (e) { setTimeout(function () { throw e; }, 0); } };
    var id = nativeSet.call(window, function () {
      if (document.hidden) { skipped = true; return; }
      run();
    }, ms);
    var onVisible = function () {
      if (!document.hidden && skipped) { skipped = false; run(); }
    };
    document.addEventListener('visibilitychange', onVisible);
    wrappers[id] = { onVisible: onVisible };
    return id;
  };

  window.clearInterval = function (id) {
    var w = wrappers[id];
    if (w) { document.removeEventListener('visibilitychange', w.onVisible); delete wrappers[id]; }
    return nativeClear.call(window, id);
  };
})();
