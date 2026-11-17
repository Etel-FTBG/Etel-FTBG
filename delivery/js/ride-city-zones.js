/* ETEL Ride - quick zones of the rider's own city (v1.0, launch-readiness item 1)
 * The Quick zones box (#zone-pick, added by ride-fix.js) used to hold one fixed list of Hawassa places.
 * This add-on fills it with the places the admin entered for the city the rider is in
 * (Admin > Service areas > Quick zones, served by api action get_area_places). A rider in Addis Ababa sees Addis Ababa places.
 * The city comes from etel-area.js (window.EtelArea). A city with no places shows a short note instead of shortcuts.
 * If etel-area.js is not on the page this add-on does nothing and the old list stays.
 * Own file: it does not edit ride-bundle.js. Place names are written with textContent (never as HTML).
 */
(function () {
  "use strict";
  if (window.__etelCityZones) return;
  window.__etelCityZones = true;

  var cache = {};
  var shownFor = null;

  function lang() {
    var l = "";
    try { l = localStorage.getItem("etel_lang") || localStorage.getItem("lang") || localStorage.getItem("ride_lang") || ""; } catch (e) {}
    return String(l).toLowerCase().indexOf("am") === 0 ? "am" : "en";
  }
  function apiBase() {
    try { if (window.ETEL && ETEL.api) return ETEL.api; } catch (e) {}
    var p = location.pathname, i = p.indexOf("/delivery/");
    return (i >= 0 ? p.slice(0, i) : "") + "/delivery/api/boot.php";
  }
  function pname(p) { return lang() === "am" && p.name_am ? p.name_am : p.name; }

  function note(chips, text) {
    chips.innerHTML = "";
    var d = document.createElement("div");
    d.style.cssText = "font-size:12px;color:var(--dim,#9A9AAE);padding:6px 2px";
    d.textContent = text;
    chips.appendChild(d);
  }

  function activeTarget(box) {
    var on = box.querySelector(".zt-btn.on");
    return on ? on.getAttribute("data-t") : "pickup";
  }

  function render(box, area, places) {
    var chips = box.querySelector(".zp-chips");
    if (!chips) return;
    if (!places.length) { note(chips, "No quick zones for " + (area.name || "this city") + " yet. Type an address or tap the map."); return; }
    chips.innerHTML = "";
    places.forEach(function (p) {
      var b = document.createElement("button");
      b.type = "button"; b.className = "zchip";
      b.textContent = pname(p);
      b.addEventListener("click", function () {
        if (navigator.vibrate) navigator.vibrate(12);
        var addr = p.name + ", " + (area.name || "");
        if (activeTarget(box) === "pickup") {
          if (typeof window.setPickup === "function") window.setPickup(p.lat, p.lng, addr);
          var d = box.querySelector('.zt-btn[data-t="dropoff"]'); if (d) d.click();
        } else if (typeof window.setDropoff === "function") window.setDropoff(p.lat, p.lng, addr);
      });
      chips.appendChild(b);
    });
  }

  function load(code) {
    if (cache[code]) return Promise.resolve(cache[code]);
    return fetch(apiBase() + "?action=get_area_places&area=" + encodeURIComponent(code))
      .then(function (r) { return r.json(); })
      .then(function (j) { var list = (j && j.success && j.data && j.data.places) || []; cache[code] = list; return list; });
  }

  function refresh() {
    var box = document.getElementById("zone-pick");
    if (!box || !window.EtelArea) return false;
    var chips = box.querySelector(".zp-chips");
    if (!chips) return false;
    var area = window.EtelArea.get();
    if (!area || !area.code) {
      if (shownFor !== "") { shownFor = ""; note(chips, "Choose your city to see its quick zones."); }
      return true;
    }
    var key = area.code + "|" + lang();
    if (shownFor === key) return true;
    shownFor = key;
    load(area.code).then(function (places) { if (shownFor === key) render(box, area, places); })
      .catch(function () { shownFor = null; note(chips, "Quick zones could not load. Type an address or tap the map."); });
    return true;
  }

  var tries = 0;
  function poll() {
    if (refresh()) {
      try { window.addEventListener("etel-area-change", function () { shownFor = null; refresh(); }); } catch (e) {}
      return;
    }
    if (++tries < 60) setTimeout(poll, 500);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", poll); else poll();
})();
