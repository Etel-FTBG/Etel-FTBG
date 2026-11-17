/* ETEL Ride - estimate versus final fare notice (v1.0, launch-readiness phase 2)
 * The server (api/lib/ride_quote_cap.php) adds quote_estimate / quote_cap / quote_over / quote_capped to the
 * rider's ride_status and my_active_ride answers. This small add-on reads those answers and shows the rider:
 *   - while the trip runs: a warning when the meter is above the estimate, and the most they can be charged;
 *   - at the end: the estimate next to the final fare, and when the fare limit was used.
 * Own file: it does not edit ride-bundle.js. It only watches answers and shows a card. English text only (no Amharic yet).
 */
(function () {
  "use strict";
  if (window.__etelQuoteNotice) return;
  window.__etelQuoteNotice = true;

  var shownKey = "";
  var box = null;

  function etb(n) { return "ETB " + Number(n).toFixed(0); }

  function hide() { if (box && box.parentNode) box.parentNode.removeChild(box); box = null; }

  function show(html, tone) {
    hide();
    box = document.createElement("div");
    box.id = "etel-quote-note";
    box.setAttribute("role", "status");
    box.style.cssText = "position:fixed;left:12px;right:12px;bottom:84px;z-index:9998;max-width:460px;margin:0 auto;padding:12px 14px;border-radius:14px;" +
      "font-size:13px;line-height:1.45;color:#EDEDF5;background:#16161d;box-shadow:0 10px 30px rgba(0,0,0,.5);border:1px solid " + (tone === "warn" ? "#FFB74D" : "rgba(212,175,55,.5)");
    box.innerHTML = html + '<button type="button" aria-label="Close" style="position:absolute;top:6px;right:8px;background:none;border:0;color:#9A9AAE;font-size:18px;cursor:pointer">&times;</button>';
    box.querySelector("button").onclick = hide;
    document.body.appendChild(box);
  }

  function handle(d) {
    if (!d || d.quote_estimate == null) { return; }
    var est = Number(d.quote_estimate);
    if (!(est > 0)) return;
    var st = d.status;
    var id = d.id || d.ride_id || "";
    if (st === "in_progress") {
      if (!Number(d.quote_over)) return;
      var k = id + ":run";
      if (shownKey === k) return;
      shownKey = k;
      var msg = "Your estimate was <b>" + etb(est) + "</b>. The trip is now above the estimate.";
      if (d.quote_cap != null) msg += " You will not be charged more than <b>" + etb(d.quote_cap) + "</b>.";
      show(msg, "warn");
      return;
    }
    if (st === "arrived" || st === "completed") {
      var fin = d.fare_total != null ? Number(d.fare_total) : (d.fare_final != null ? Number(d.fare_final) : null);
      if (fin === null || isNaN(fin)) return;
      var k2 = id + ":end:" + fin + ":" + st;
      if (shownKey === k2) return;
      shownKey = k2;
      var h = "Estimate <b>" + etb(est) + "</b> &middot; Final fare <b>" + etb(fin) + "</b>";
      if (Number(d.quote_capped) && d.quote_uncapped != null) {
        h += "<br>The trip was longer than quoted (meter " + etb(d.quote_uncapped) + "). We limited the fare to " + etb(fin) + ".";
      }
      show(h, Number(d.quote_capped) ? "warn" : "ok");
    }
  }

  var origFetch = window.fetch;
  if (typeof origFetch !== "function") return;
  window.fetch = function (input) {
    var p = origFetch.apply(this, arguments);
    try {
      var url = typeof input === "string" ? input : (input && input.url) || "";
      if (url.indexOf("action=ride_status") >= 0 || url.indexOf("action=my_active_ride") >= 0) {
        p.then(function (res) {
          try { res.clone().json().then(function (j) { if (j && j.success && j.data) handle(j.data); }).catch(function () {}); } catch (e) {}
        }).catch(function () {});
      }
    } catch (e) {}
    return p;
  };
})();
