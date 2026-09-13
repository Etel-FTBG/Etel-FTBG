/* ETEL Ride - additive features (v1.0)
 * Share trip with a trusted contact + scheduled/pre-booked rides.
 *
 * Deliberately a SEPARATE file from ride-bundle.js / ride-bundle-extra.js
 * (which are large minified single-line bundles) rather than an edit to
 * them - this keeps the risky, hard-to-diff files untouched and makes
 * this feature easy to roll back on its own if something's off. It reads
 * globals already defined by ride-bundle.js (ETEL, Auth, RIDE, toast)
 * and only adds new DOM it creates itself, so it has no dependency on
 * exact markup inside the bundle.
 */
(function () {
  "use strict";

  function api() {
    // Do NOT depend on window.ETEL.api - it's very likely private to
    // ride-bundle.js's own closure, not actually exposed on window. A
    // silent fallback to a bare relative "api/boot.php" string resolved
    // wrong from this page's own path (/delivery/ride/index.html ->
    // /delivery/ride/api/boot.php, which doesn't exist), producing a
    // real web-server 404 HTML page - not a PHP error at all. Compute
    // the absolute URL directly instead, the same way ride-bundle.js
    // does internally.
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
    if (kind === "err") console.error(msg); else console.log(msg);
  }
  function currentRideId() {
    return (window.RIDE && (RIDE.currentRideId || RIDE.currentRequestId)) || null;
  }

  /* ---------- Share trip ---------- */

  function openShareSheet(url) {
    var old = document.getElementById("etel-share-sheet");
    if (old) old.remove();
    var wrap = document.createElement("div");
    wrap.id = "etel-share-sheet";
    wrap.style.cssText = "position:fixed;inset:0;z-index:20000;background:rgba(5,5,10,.72);display:flex;align-items:flex-end;justify-content:center;font-family:'Outfit',sans-serif";
    wrap.innerHTML =
      '<div style="width:min(460px,100vw);background:linear-gradient(180deg,#14141f,#0b0b13);border:1px solid rgba(212,175,55,.16);border-bottom:none;border-radius:22px 22px 0 0;padding:22px 20px max(22px,env(safe-area-inset-bottom,22px))">' +
      '<div style="font-size:15px;font-weight:800;color:#EDEDF5;margin-bottom:6px">📍 Share this trip</div>' +
      '<div style="font-size:12.5px;color:#9A9AAE;margin-bottom:16px;line-height:1.5">Send this link to someone you trust — they can watch your driver, route and status live, no app or login needed.</div>' +
      '<div style="display:flex;gap:8px;margin-bottom:14px">' +
      '<input id="etel-share-url" readonly value="' + url.replace(/"/g, "&quot;") + '" style="flex:1;min-width:0;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:11px 12px;color:#EDEDF5;font-size:12.5px">' +
      '<button id="etel-share-copy" style="flex-shrink:0;padding:0 16px;border:none;border-radius:10px;background:linear-gradient(135deg,#C8960C,#D4AF37);color:#1a1206;font-weight:700;font-size:12.5px;cursor:pointer">Copy</button>' +
      '</div>' +
      '<button id="etel-share-native" style="width:100%;padding:13px;border-radius:12px;border:none;background:linear-gradient(135deg,#FFE9A8,#D4AF37);color:#1a1a2e;font-weight:800;font-size:14px;cursor:pointer;margin-bottom:10px">Share via…</button>' +
      '<button id="etel-share-stop" style="width:100%;padding:12px;border-radius:12px;border:1px solid rgba(255,138,128,.35);background:rgba(255,138,128,.08);color:#FF8A80;font-weight:700;font-size:13px;cursor:pointer;margin-bottom:10px">Stop sharing</button>' +
      '<button id="etel-share-close" style="width:100%;padding:11px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#9A9AAE;font-size:13px;cursor:pointer">Close</button>' +
      '</div>';
    wrap.addEventListener("click", function (e) { if (e.target === wrap) wrap.remove(); });
    document.body.appendChild(wrap);

    document.getElementById("etel-share-copy").onclick = function () {
      var input = document.getElementById("etel-share-url");
      input.select();
      try { navigator.clipboard ? navigator.clipboard.writeText(url) : document.execCommand("copy"); say("Link copied", "ok"); }
      catch (e) { say("Could not copy — select and copy manually", "err"); }
    };
    document.getElementById("etel-share-native").onclick = function () {
      if (navigator.share) {
        navigator.share({ title: "Follow my ETEL trip", text: "Track my ride live:", url: url }).catch(function () {});
      } else {
        say("Sharing isn't supported on this browser — use Copy instead", "err");
      }
    };
    document.getElementById("etel-share-stop").onclick = function () {
      var rid = currentRideId();
      if (!rid) { wrap.remove(); return; }
      fetch(api(), { method: "POST", headers: authHeaders(), body: JSON.stringify({ action: "ride_stop_sharing", ride_id: rid }) })
        .then(function (r) { return r.json(); })
        .then(function () { say("Sharing stopped", "ok"); wrap.remove(); })
        .catch(function () { say("Network error", "err"); });
    };
    document.getElementById("etel-share-close").onclick = function () { wrap.remove(); };
  }

  window.shareCurrentTrip = function () {
    var rid = currentRideId();
    if (!rid) { say("No active trip to share yet", "err"); return; }
    fetch(api(), { method: "POST", headers: authHeaders(), body: JSON.stringify({ action: "ride_get_share_link", ride_id: rid }) })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if ((res.success || res.ok) && res.data && res.data.share_url) {
          openShareSheet(res.data.share_url);
        } else {
          say((res.data && res.data.message) || res.message || "Could not create share link", "err");
        }
      })
      .catch(function () { say("Network error creating share link", "err"); });
  };

  function injectShareButton() {
    var bar = document.querySelector("#view-active .action-bar");
    if (!bar || document.getElementById("etel-share-trip-btn")) return;
    var btn = document.createElement("button");
    btn.id = "etel-share-trip-btn";
    btn.className = "action-bar-btn";
    btn.innerHTML = '<span class="ab-icon">📍</span><span class="ab-label">Share</span>';
    btn.onclick = window.shareCurrentTrip;
    bar.appendChild(btn);
  }

  /* ---------- Scheduled rides ---------- */

  function fmtWhen(dtStr) {
    try {
      var d = new Date(dtStr.replace(" ", "T"));
      return d.toLocaleString([], { weekday: "short", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
    } catch (e) { return dtStr; }
  }

  function loadScheduledList(targetEl) {
    targetEl.innerHTML = '<div style="text-align:center;padding:16px;color:#9A9AAE;font-size:12.5px">Loading…</div>';
    // POST, not GET - this is per-user data and must never be cached by
    // an intermediate CDN/edge cache keyed on URL. A GET here previously
    // got a stale 404 stuck in front-end caching even after the backend
    // was fixed and LiteSpeed's own cache was purged.
    fetch(api(), { method: "POST", headers: authHeaders(), body: JSON.stringify({ action: "ride_get_scheduled" }) })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var list = (res.success || res.ok) && res.data ? (res.data.scheduled || []) : [];
        if (!list.length) {
          targetEl.innerHTML = '<div style="text-align:center;padding:16px;color:#9A9AAE;font-size:12.5px">No scheduled rides yet</div>';
          return;
        }
        targetEl.innerHTML = list.map(function (r) {
          return '<div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px;margin-bottom:8px">' +
            '<div style="font-size:12.5px;font-weight:700;color:#E5C76B;margin-bottom:4px">' + fmtWhen(r.scheduled_at) + '</div>' +
            '<div style="font-size:12px;color:#EDEDF5">📍 ' + (r.pickup_address || "—") + '</div>' +
            '<div style="font-size:12px;color:#EDEDF5;margin-bottom:8px">🏁 ' + (r.dropoff_address || "—") + '</div>' +
            '<div style="display:flex;justify-content:space-between;align-items:center">' +
            '<span style="font-size:12px;color:#9A9AAE">ETB ' + Math.round(parseFloat(r.fare_estimate || 0)) + ' estimate</span>' +
            '<button data-cancel-scheduled="' + r.id + '" style="border:1px solid rgba(255,138,128,.35);background:rgba(255,138,128,.08);color:#FF8A80;border-radius:8px;padding:6px 12px;font-size:11.5px;font-weight:700;cursor:pointer">Cancel</button>' +
            '</div></div>';
        }).join("");
        targetEl.querySelectorAll("[data-cancel-scheduled]").forEach(function (btn) {
          btn.onclick = function () {
            var id = btn.getAttribute("data-cancel-scheduled");
            fetch(api(), { method: "POST", headers: authHeaders(), body: JSON.stringify({ action: "ride_cancel_scheduled", id: id }) })
              .then(function (r) { return r.json(); })
              .then(function () { loadScheduledList(targetEl); })
              .catch(function () { say("Could not cancel", "err"); });
          };
        });
      })
      .catch(function () { targetEl.innerHTML = '<div style="text-align:center;padding:16px;color:#FF8A80;font-size:12.5px">Could not load scheduled rides</div>'; });
  }

  window.openScheduleRideSheet = function () {
    var old = document.getElementById("etel-schedule-sheet");
    if (old) old.remove();
    var wrap = document.createElement("div");
    wrap.id = "etel-schedule-sheet";
    wrap.style.cssText = "position:fixed;inset:0;z-index:20000;background:rgba(5,5,10,.72);display:flex;align-items:flex-end;justify-content:center;font-family:'Outfit',sans-serif";
    var minDt = new Date(Date.now() + 15 * 60000);
    var minVal = new Date(minDt.getTime() - minDt.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    wrap.innerHTML =
      '<div style="width:min(460px,100vw);max-height:82vh;overflow-y:auto;background:linear-gradient(180deg,#14141f,#0b0b13);border:1px solid rgba(212,175,55,.16);border-bottom:none;border-radius:22px 22px 0 0;padding:22px 20px max(22px,env(safe-area-inset-bottom,22px))">' +
      '<div style="font-size:15px;font-weight:800;color:#EDEDF5;margin-bottom:14px">🕓 Schedule a ride</div>' +
      '<div style="font-size:11.5px;color:#9A9AAE;margin-bottom:10px">Uses the pickup &amp; drop-off you already set on the Book tab — set those first, then pick a time here.</div>' +
      '<label style="font-size:11px;color:#9A9AAE;display:block;margin-bottom:5px">Pickup time</label>' +
      '<input id="etel-sched-when" type="datetime-local" min="' + minVal + '" value="' + minVal + '" style="width:100%;padding:11px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#EDEDF5;font-size:13px;margin-bottom:16px">' +
      '<button id="etel-sched-submit" style="width:100%;padding:13px;border-radius:12px;border:none;background:linear-gradient(135deg,#FFE9A8,#D4AF37);color:#1a1a2e;font-weight:800;font-size:14px;cursor:pointer;margin-bottom:10px">Schedule Ride</button>' +
      '<div id="etel-sched-error" style="display:none;color:#FF8A80;font-size:12px;text-align:center;margin:-4px 0 14px;line-height:1.4"></div>' +
      '<div style="font-size:11px;color:#9A9AAE;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px">Your scheduled rides</div>' +
      '<div id="etel-sched-list"></div>' +
      '<button id="etel-sched-close" style="width:100%;padding:11px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#9A9AAE;font-size:13px;cursor:pointer;margin-top:6px">Close</button>' +
      '</div>';
    wrap.addEventListener("click", function (e) { if (e.target === wrap) wrap.remove(); });
    document.body.appendChild(wrap);
    loadScheduledList(document.getElementById("etel-sched-list"));

    function showSchedError(msg) {
      var el = document.getElementById("etel-sched-error");
      if (el) { el.textContent = msg; el.style.display = "block"; }
      say(msg, "err");
    }

    document.getElementById("etel-sched-submit").onclick = function () {
      var errEl = document.getElementById("etel-sched-error");
      if (errEl) errEl.style.display = "none";
      var whenVal = document.getElementById("etel-sched-when").value;
      if (!whenVal) { showSchedError("Pick a pickup time first"); return; }
      if (!window.RIDE || !RIDE.pickupLatLng || (!RIDE.dropoffLatLng)) {
        showSchedError("Set pickup & drop-off on the Book tab first (close this sheet, fill in \"Your location\" and \"Where to?\", then reopen Schedule).");
        return;
      }
      var btn = document.getElementById("etel-sched-submit");
      btn.disabled = true; btn.textContent = "Scheduling…";
      fetch(api(), {
        method: "POST", headers: authHeaders(),
        body: JSON.stringify({
          action: "ride_schedule",
          pickup_lat: RIDE.pickupLatLng.lat, pickup_lng: RIDE.pickupLatLng.lng,
          dropoff_lat: RIDE.dropoffLatLng.lat, dropoff_lng: RIDE.dropoffLatLng.lng,
          route_km: RIDE.lastRouteKm || "",
          pickup_addr: (document.getElementById("pickup-input") || {}).value || "",
          dropoff_addr: (document.getElementById("dropoff-input") || {}).value || "",
          vehicle_type: RIDE.selectedVtype || "bajaj",
          payment_method: RIDE.selectedPayment || "cash",
          scheduled_at: whenVal.replace("T", " ") + ":00"
        })
      }).then(function (r) { return r.json(); })
        .then(function (res) {
          btn.disabled = false; btn.textContent = "Schedule Ride";
          if (res.success || res.ok) {
            say("Ride scheduled — we'll find your driver at that time", "ok");
            loadScheduledList(document.getElementById("etel-sched-list"));
          } else {
            showSchedError((res.data && res.data.message) || res.message || "Could not schedule ride");
          }
        }).catch(function () { btn.disabled = false; btn.textContent = "Schedule Ride"; showSchedError("Network error"); });
    };
    document.getElementById("etel-sched-close").onclick = function () { wrap.remove(); };
  };

  function injectScheduleButton() {
    var bookView = document.getElementById("view-book");
    if (!bookView || document.getElementById("etel-schedule-btn")) return;
    var btn = document.createElement("button");
    btn.id = "etel-schedule-btn";
    btn.type = "button";
    btn.textContent = "🕓 Schedule for later";
    btn.style.cssText = "width:100%;margin-top:10px;padding:12px;border-radius:12px;border:1px solid rgba(212,175,55,.28);background:rgba(212,175,55,.07);color:#E5C76B;font-weight:700;font-size:13px;cursor:pointer;font-family:'Outfit',sans-serif";
    btn.onclick = window.openScheduleRideSheet;
    var payRow = bookView.querySelector(".pay-row");
    if (payRow && payRow.parentNode) payRow.parentNode.insertBefore(btn, payRow.nextSibling);
    else bookView.appendChild(btn);
  }

  function init() {
    injectShareButton();
    injectScheduleButton();
    // FIX: the previous version only retried for 10 seconds (20 tries x
    // 500ms) after page load, then gave up permanently. On a real trip
    // with real network latency, #view-active's content (and therefore
    // its .action-bar) can take longer than that to actually render -
    // meaning the Share button could silently never get injected for the
    // whole rest of the ride. A MutationObserver instead reacts to the
    // DOM actually changing, however long that takes, and never times
    // out - it just stops calling inject once the button already exists
    // (inject functions are already idempotent/no-op if found).
    var observer = new MutationObserver(function () {
      injectShareButton();
      injectScheduleButton();
    });
    observer.observe(document.body, { childList: true, subtree: true });
    // Keep the short interval too, cheap insurance for the very first
    // couple of seconds before the observer has anything to react to.
    var tries = 0;
    var iv = setInterval(function () {
      injectShareButton();
      injectScheduleButton();
      if (++tries > 20) clearInterval(iv);
    }, 500);
  }

  if (document.readyState !== "loading") init();
  else document.addEventListener("DOMContentLoaded", init);
})();
