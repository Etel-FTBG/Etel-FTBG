/* ETEL Ride - Traffic button on the map (v1.0, 28 Nov 2026)
 * A small square button under the + / - zoom buttons (same side, same look). Tap it to show Google's live traffic colours
 * (green = moving, orange = slow, red = jammed) so a rider can see why a trip may take longer. Tap again to hide.
 * The choice is remembered on the phone. Own file: it does not edit the ride bundle. Does nothing when Google Maps is not there.
 * The button is a Google map control, so it stays on the map and never covers the booking sheet.
 */
(function () {
  "use strict";
  if (window.__etelRideTraffic) return;
  window.__etelRideTraffic = true;

  var KEY = "etel_ride_traffic";
  var layer = null, added = false;

  function saved() { try { return localStorage.getItem(KEY) === "1"; } catch (e) { return false; } }
  function save(v) { try { localStorage.setItem(KEY, v ? "1" : "0"); } catch (e) {} }
  function lang() {
    var l = "";
    try { l = localStorage.getItem("etel_lang") || localStorage.getItem("lang") || localStorage.getItem("ride_lang") || ""; } catch (e) {}
    return String(l).toLowerCase().indexOf("am") === 0 ? "am" : "en";
  }
  function label(on) {
    if (lang() === "am") return on ? "\u12e8\u1275\u122b\u134a\u12ad \u1201\u1294\u1273 \u12f0\u1265\u1245" : "\u12e8\u1275\u122b\u134a\u12ad \u1201\u1294\u1273 \u12a0\u1233\u12ed";
    return on ? "Hide traffic" : "Show traffic";
  }

  function build(map) {
    var gm = window.google.maps;
    var b = document.createElement("button");
    b.type = "button"; b.id = "ride-traffic-btn";
    b.style.cssText = "width:40px;height:40px;margin:0 10px 10px 0;padding:0;border:0;border-radius:2px;cursor:pointer;" +
      "background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center";
    b.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">' +
      '<path d="M4 20L9 4" stroke="#2E9E5B"/><path d="M15 4l5 16" stroke="#D93025"/><path d="M12 5v3M12 11v3M12 17v3" stroke="#F29900"/></svg>';
    function paint(on) {
      b.setAttribute("aria-pressed", on ? "true" : "false");
      b.setAttribute("title", label(on)); b.setAttribute("aria-label", label(on));
      b.style.outline = on ? "2px solid #D4AF37" : "none"; b.style.outlineOffset = "-2px";
      b.style.background = on ? "#FFF8E1" : "#fff";
    }
    var on = saved();
    layer = new gm.TrafficLayer();
    if (on) layer.setMap(map);
    paint(on);
    b.addEventListener("click", function () {
      on = !on; save(on);
      layer.setMap(on ? map : null);
      paint(on);
      if (navigator.vibrate) navigator.vibrate(10);
    });
    map.controls[gm.ControlPosition.RIGHT_CENTER].push(b);
    added = true;
  }

  function tick() {
    if (added) return true;
    try {
      var r = window.RIDE;
      if (r && r.map && window.google && google.maps && google.maps.TrafficLayer && r.map.controls) { build(r.map); return true; }
    } catch (e) { /* map not ready yet: the timer tries again */ }
    return false;
  }

  var tries = 0, timer = setInterval(function () {
    tries++;
    if (tick() || tries > 80) clearInterval(timer);
  }, 750);
})();
