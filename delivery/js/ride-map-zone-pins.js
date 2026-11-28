/* ETEL Ride - the quick-zone places as small gold pins on the rider map (v1.0, 28 Nov 2026)
 * The places the admin entered for the rider's city (Admin > Service areas > Quick zones, the same list as the Quick zones box) also show on the map.
 * Tap a pin: a small card with the place name and two buttons, "Pickup here" and "Drop-off here" (they call the same setPickup / setDropoff the chips use).
 * A small round button under the traffic button shows or hides the pins; the choice is remembered. Default: shown.
 * Own file: it does not edit the ride bundle. Place names are written with textContent (never as HTML). Does nothing without Google Maps or a city.
 */
(function () {
  "use strict";
  if (window.__etelZonePins) return;
  window.__etelZonePins = true;

  var KEY = "etel_ride_zone_pins";
  var cache = {}, markers = [], shownKey = null, on = true, info = null, btn = null, added = false;

  function saved() { try { return localStorage.getItem(KEY) !== "0"; } catch (e) { return true; } }
  function save(v) { try { localStorage.setItem(KEY, v ? "1" : "0"); } catch (e) {} }
  function lang() {
    var l = "";
    try { l = localStorage.getItem("etel_lang") || localStorage.getItem("lang") || localStorage.getItem("ride_lang") || ""; } catch (e) {}
    return String(l).toLowerCase().indexOf("am") === 0 ? "am" : "en";
  }
  function T(en, am) { return lang() === "am" ? am : en; }
  function apiBase() {
    try { if (window.ETEL && ETEL.api) return ETEL.api; } catch (e) {}
    var p = location.pathname, i = p.indexOf("/delivery/");
    return (i >= 0 ? p.slice(0, i) : "") + "/delivery/api/boot.php";
  }
  function pname(p) { return lang() === "am" && p.name_am ? p.name_am : p.name; }

  function load(code) {
    if (cache[code]) return Promise.resolve(cache[code]);
    return fetch(apiBase() + "?action=get_area_places&area=" + encodeURIComponent(code))
      .then(function (r) { return r.json(); })
      .then(function (j) { var list = (j && j.success && j.data && j.data.places) || []; cache[code] = list; return list; });
  }

  function clear() {
    markers.forEach(function (m) { try { m.setMap(null); } catch (e) {} });
    markers = [];
    if (info) { try { info.close(); } catch (e) {} }
  }

  function card(p, area) {
    var d = document.createElement("div");
    d.style.cssText = "font:600 13px system-ui,sans-serif;color:#222;min-width:150px;max-width:220px";
    var h = document.createElement("div");
    h.textContent = pname(p); h.style.cssText = "margin:0 0 8px;font-size:14px";
    d.appendChild(h);
    function mk(label, fn) {
      var b = document.createElement("button");
      b.type = "button"; b.textContent = label;
      b.style.cssText = "display:block;width:100%;margin:4px 0 0;padding:8px 10px;border:0;border-radius:8px;background:#D4AF37;color:#1a1400;font:700 13px system-ui,sans-serif;cursor:pointer";
      b.addEventListener("click", function () { if (info) info.close(); if (navigator.vibrate) navigator.vibrate(12); fn(); });
      d.appendChild(b);
    }
    var addr = p.name + ", " + (area.name || "");
    mk(T("Pickup here", "\u12a5\u12da\u1205 \u12ed\u12cd\u1230\u12f1\u129d"), function () { if (typeof window.setPickup === "function") window.setPickup(+p.lat, +p.lng, addr); });
    mk(T("Drop-off here", "\u12a5\u12da\u1205 \u12eb\u12cd\u122d\u12f1\u129d"), function () { if (typeof window.setDropoff === "function") window.setDropoff(+p.lat, +p.lng, addr); });
    return d;
  }

  function draw(area, places) {
    var map = window.RIDE.map, gm = google.maps;
    clear();
    if (!info && gm.InfoWindow) info = new gm.InfoWindow();
    places.forEach(function (p) {
      var lat = +p.lat, lng = +p.lng;
      if (!isFinite(lat) || !isFinite(lng) || (lat === 0 && lng === 0) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return;
      var m = new gm.Marker({
        position: { lat: lat, lng: lng }, map: on ? map : null, title: pname(p), zIndex: 5, clickable: true,
        icon: { path: gm.SymbolPath ? gm.SymbolPath.CIRCLE : 0, scale: 7, fillColor: "#D4AF37", fillOpacity: 0.96, strokeColor: "#0A0B10", strokeWeight: 2.5 }
      });
      m.addListener("click", function () { if (!info) return; info.setContent(card(p, area)); info.open(map, m); });
      markers.push(m);
    });
  }

  function refresh() {
    var area = window.EtelArea && window.EtelArea.get && window.EtelArea.get();
    if (!area || !area.code) { clear(); shownKey = ""; return; }
    var key = area.code + "|" + lang();
    if (shownKey === key) return;
    shownKey = key;
    load(area.code).then(function (places) { if (shownKey === key) draw(area, places); }).catch(function () { shownKey = null; });
  }

  function setOn(v) {
    on = v; save(v);
    markers.forEach(function (m) { m.setMap(on ? window.RIDE.map : null); });
    if (!on && info) info.close();
    paint();
  }
  function paint() {
    if (!btn) return;
    btn.setAttribute("aria-pressed", on ? "true" : "false");
    var t = on ? T("Hide places", "\u1266\u1273\u12ce\u127d\u1295 \u12f0\u1265\u1245") : T("Show places", "\u1266\u1273\u12ce\u127d\u1295 \u12a0\u1233\u12ed");
    btn.title = t; btn.setAttribute("aria-label", t);
    btn.style.outline = on ? "2px solid #D4AF37" : "none"; btn.style.outlineOffset = "-2px";
    btn.style.background = on ? "#FFF8E1" : "#fff";
  }
  function addButton(map) {
    var gm = google.maps;
    btn = document.createElement("button");
    btn.type = "button"; btn.id = "ride-zonepins-btn";
    btn.style.cssText = "width:40px;height:40px;margin:0 10px 10px 0;padding:0;border:0;border-radius:2px;cursor:pointer;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center";
    btn.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5a6.5 6.5 0 0 0-6.5 6.5c0 4.7 6.5 12 6.5 12s6.5-7.3 6.5-12A6.5 6.5 0 0 0 12 2.5z" fill="#D4AF37" stroke="#0A0B10" stroke-width="1.6"/><circle cx="12" cy="9" r="2.4" fill="#0A0B10"/></svg>';
    btn.addEventListener("click", function () { setOn(!on); });
    paint();
    map.controls[gm.ControlPosition.RIGHT_CENTER].push(btn);
    added = true;
  }

  function tick() {
    try {
      var r = window.RIDE;
      if (!(r && r.map && window.google && google.maps && google.maps.Marker && r.map.controls)) return false;
      if (!added) { on = saved(); addButton(r.map); }
      refresh();
      return true;
    } catch (e) { return false; }
  }

  var tries = 0, timer = setInterval(function () { tries++; if (tick() && tries > 4) { /* keep a slow watch for a city change below */ } if (tries > 80) clearInterval(timer); }, 750);
  try { window.addEventListener("etel-area-change", function () { shownKey = null; tick(); }); } catch (e) {}
})();
