/* ETEL Ride - driver rates rider back (v1.2)
 * Own file, not an edit to driver-bundle.js / driver-bundle-extra.js.
 *
 * IMPORTANT: driver_complete_ride does NOT complete a ride - it moves it
 * to status='arrived' and waits for the passenger to confirm payment via
 * customer_confirm_arrival. The ride only becomes status='completed'
 * after that customer-side action, which this driver page has no direct
 * signal for.
 *
 * v1.1 tried to catch the exact moment a ride left 'arrived' status by
 * watching driver_get_profile's active_ride in a live poll loop - that
 * broke the first time it was used on a real trip, because the driver's
 * phone screen locked/backgrounded between "Arrived - Bill Customer" and
 * the rider actually confirming payment, and mobile browsers throttle or
 * pause setInterval timers while backgrounded, silently losing the
 * in-memory transition-tracking state.
 *
 * v1.2 instead just asks, statelessly, on every poll and on every time
 * the driver returns to the app: "is there a completed ride in my
 * recent history I haven't rated yet?" No transition to miss - it works
 * regardless of what happened to the phone in between.
 *
 * Builds its own overlay DOM rather than relying on the page's #modal
 * container, since that div doesn't exist on every driver page.
 */
(function () {
  "use strict";

  function api() {
    // Same fix as ride-extra-features.js - don't depend on window.ETEL
    // being global (it isn't), compute the absolute boot.php URL
    // directly from this page's own path instead.
    var path = window.location.pathname;
    var marker = "/delivery/";
    var idx = path.indexOf(marker);
    var base = idx >= 0 ? path.substring(0, idx + marker.length) : path.substring(0, path.lastIndexOf("/") + 1);
    return window.location.protocol + "//" + window.location.host + base + "api/boot.php";
  }
  function authHeaders() {
    var t = "";
    try { t = window.Auth && Auth.getToken ? Auth.getToken() : (localStorage.getItem("etel_token") || ""); } catch (e) {}
    return t ? { "Content-Type": "application/json", "Authorization": "Bearer " + t } : { "Content-Type": "application/json" };
  }
  function say(msg, kind) {
    if (typeof window.toast === "function") { toast(msg, kind === "err" ? "err" : "ok"); return; }
    console.log(msg);
  }

  var selected = 0;

  var prompted = {};
  // FIX: was sessionStorage (cleared whenever the browser/tab session
  // ends, including a phone reboot or the browser being killed) -
  // localStorage persists properly across those, so a rider who was
  // already skipped/rated doesn't get re-prompted on every fresh app
  // open throughout the day.
  try { prompted = JSON.parse(localStorage.getItem("etel_rated_riders_prompted") || "{}"); } catch (e) {}
  function markPrompted(id) {
    prompted[id] = 1;
    try { localStorage.setItem("etel_rated_riders_prompted", JSON.stringify(prompted)); } catch (e) {}
  }

  function closeSheet() {
    var el = document.getElementById("etel-rate-rider-sheet");
    if (el) el.remove();
  }

  /* Real trip summary above the rating - fare, breakdown, distance and
     duration, all straight from the completed ride row (no fabricated
     fields). This is the driver's only view of "trip completed": there
     is no separate completion screen elsewhere, so it belongs here. */
  function _completionSummaryHtml(ride) {
    var fare = parseFloat(ride.fare_paid != null ? ride.fare_paid : (ride.fare_final || 0));
    var km = parseFloat(ride.traveled_km != null ? ride.traveled_km : (ride.distance_km || 0));
    var durationLine = "";
    if (ride.started_at && ride.completed_at) {
      var startMs = Date.parse(String(ride.started_at).replace(" ", "T") + "Z");
      var endMs = Date.parse(String(ride.completed_at).replace(" ", "T") + "Z");
      if (startMs && endMs && endMs > startMs) {
        var mins = Math.round((endMs - startMs) / 60000);
        durationLine = mins + " min";
      }
    }
    var hasBreakdown = ride.fare_base != null || ride.fare_distance != null;
    var breakdown = (hasBreakdown && typeof _fareBreakdownHtml === "function")
      ? _fareBreakdownHtml(Object.assign({}, ride, { fare_total: fare, status: "completed" }))
      : "";
    return '<div style="text-align:center;margin-bottom:18px">' +
      '<div style="font-size:34px;margin-bottom:4px">✅</div>' +
      '<div style="font-size:13px;font-weight:800;color:#9A9AAE;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Trip Completed</div>' +
      '<div style="font-family:\'Sora\',sans-serif;font-size:32px;font-weight:800;color:#F5E7B8;margin-bottom:8px">ETB ' + fare.toFixed(0) + '</div>' +
      '<div style="display:flex;justify-content:center;gap:14px;font-size:12px;color:#9A9AAE;margin-bottom:' + (breakdown ? "14" : "4") + 'px">' +
        (km ? '<span>' + km.toFixed(1) + ' km</span>' : '') +
        (durationLine ? '<span>' + durationLine + '</span>' : '') +
      '</div>' +
      breakdown +
    '</div>';
  }

  function openRateRiderSheet(ride) {
    closeSheet();
    selected = 0;
    var rideId = ride.id;
    var wrap = document.createElement("div");
    wrap.id = "etel-rate-rider-sheet";
    wrap.style.cssText = "position:fixed;inset:0;z-index:20000;background:rgba(5,5,10,.75);display:flex;align-items:flex-end;justify-content:center;font-family:'Outfit',sans-serif;overflow-y:auto";
    wrap.innerHTML =
      '<div style="width:min(460px,100vw);background:linear-gradient(180deg,#14141f,#0b0b13);border:1px solid rgba(212,175,55,.16);border-bottom:none;border-radius:22px 22px 0 0;padding:24px 20px max(24px,env(safe-area-inset-bottom,24px));text-align:center">' +
      _completionSummaryHtml(ride) +
      '<div style="border-top:1px solid rgba(255,255,255,.08);margin-bottom:18px"></div>' +
      '<div style="font-size:16px;font-weight:800;color:#EDEDF5;margin-bottom:6px">Rate your rider</div>' +
      '<div style="font-size:12.5px;color:#9A9AAE;margin-bottom:18px">How was this trip?</div>' +
      '<div id="etel-rr-stars" style="display:flex;justify-content:center;gap:8px;margin-bottom:16px;font-size:32px">' +
      [1, 2, 3, 4, 5].map(function (n) { return '<button data-star="' + n + '" style="background:none;border:none;cursor:pointer;padding:0;opacity:.4">⭐</button>'; }).join("") +
      '</div>' +
      '<textarea id="etel-rr-comment" placeholder="Leave a comment (optional)…" rows="2" style="width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);border-radius:14px;color:#EDEDF5;font-family:inherit;font-size:13px;padding:12px 14px;resize:none;outline:none;margin-bottom:14px"></textarea>' +
      '<button id="etel-rr-submit" style="width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#FFE9A8,#D4AF37);color:#1a1a2e;font-weight:800;font-size:14px;cursor:pointer;margin-bottom:10px">Submit Rating</button>' +
      '<button id="etel-rr-skip" style="width:100%;padding:11px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#9A9AAE;font-size:13px;cursor:pointer">Skip</button>' +
      '</div>';
    document.body.appendChild(wrap);

    wrap.querySelectorAll("#etel-rr-stars button").forEach(function (btn) {
      btn.addEventListener("click", function () {
        selected = parseInt(btn.getAttribute("data-star"), 10);
        wrap.querySelectorAll("#etel-rr-stars button").forEach(function (b, i) {
          b.style.opacity = (i < selected) ? "1" : ".4";
        });
      });
    });

    document.getElementById("etel-rr-submit").onclick = function () {
      if (!selected) { say("Please select a rating", "err"); return; }
      var btn = document.getElementById("etel-rr-submit");
      btn.disabled = true; btn.textContent = "Submitting…";
      var comment = (document.getElementById("etel-rr-comment") || {}).value || "";
      fetch(api(), {
        method: "POST", headers: authHeaders(),
        body: JSON.stringify({ action: "rate_rider", ride_id: rideId, rating: selected, comment: comment })
      }).then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success || res.ok) { say("Thanks for rating your rider", "ok"); }
          else { say((res.data && res.data.message) || res.message || "Could not submit rating", "err"); }
          closeSheet();
        }).catch(function () { say("Network error", "err"); closeSheet(); });
    };
    document.getElementById("etel-rr-skip").onclick = function () { markPrompted(rideId); closeSheet(); };
  }

  function checkForUnratedCompletedRides() {
    // FIX: this used to only fire by CATCHING the exact moment a ride
    // transitioned from 'arrived' to gone (via lastArrivedRideId, an
    // in-memory variable). That's fragile against anything that
    // interrupts the live polling session between those two moments -
    // the phone screen locking, the app being backgrounded (mobile
    // browsers throttle/pause setInterval timers when backgrounded),
    // switching apps, or the page reloading - all of which are entirely
    // normal things to happen during the several minutes between
    // "Arrived - Bill Customer" and the rider actually confirming
    // payment. Missing that narrow window meant the prompt silently
    // never fired for the rest of the session.
    //
    // Now: on every poll (and on initial load), just directly ask
    // "is there any completed ride in my recent history that I haven't
    // rated yet?" - a stateless check with no transition to miss. Only
    // considers rides completed within the last 3 hours, so reopening
    // the app days later doesn't suddenly surface a stale prompt for an
    // old trip.
    fetch(api() + "?action=passenger_get_ride_history", { headers: authHeaders() })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var rows = (res.success || res.ok) && res.data ? (res.data.rides || []) : [];
        var cutoff = Date.now() - 3 * 60 * 60 * 1000; // 3 hours
        for (var i = 0; i < rows.length; i++) {
          var row = rows[i];
          if (row.status !== "completed" || row.driver_rating || prompted[row.id]) continue;
          var completedTime = row.completed_at ? Date.parse(row.completed_at.replace(" ", "T") + "Z") : 0;
          if (completedTime && completedTime < cutoff) continue;
          markPrompted(row.id);
          openRateRiderSheet(row);
          return; // one at a time
        }
      })
      .catch(function () {});
  }

  function poll() {
    checkForUnratedCompletedRides();
  }

  function init() {
    // FIX: this used to check Auth.isLoggedIn() exactly once and bail
    // out permanently if it happened to return false at that exact
    // moment (e.g. auth/token validation still resolving asynchronously
    // when this script runs) - no retry, no polling ever got set up,
    // silently disabling the entire feature for the rest of that page
    // session even though the driver genuinely was logged in moments
    // later. Now retries until Auth is actually ready instead of a
    // single all-or-nothing check.
    if (!window.Auth || !Auth.isLoggedIn || !Auth.isLoggedIn()) {
      setTimeout(init, 1000);
      return;
    }
    poll();
    setInterval(poll, 20000);
    // Also check immediately whenever the driver returns to this tab -
    // exactly the scenario that broke the old logic (backgrounded during
    // the arrived->completed window). No need to wait for the next
    // interval tick once they're back.
    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState === "visible") poll();
    });
  }

  if (document.readyState !== "loading") init();
  else document.addEventListener("DOMContentLoaded", init);
})();
