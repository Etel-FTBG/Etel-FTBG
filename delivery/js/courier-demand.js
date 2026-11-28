/* ETEL Courier - hot areas as gold hexagons on the courier map (v1.0, 28 Nov 2026; additive script, no courier bundle release)
 * Where delivery jobs nobody has taken yet are piling up, compared with how many couriers are online there (api: courier_get_demand, counts only, never a customer or address).
 * Same look as the driver map (js/driver-demand.js): flat-top hexagons on the same 700 m grid; the busier the cell, the brighter the gold and the thicker the edge.
 * A small card on the map (top left) has the title, an On/Off button, a Quiet-to-Busiest bar, the busiest area with its distance, and "Go there" (moves the map).
 * The On/Off button hides or shows the hexagons; the choice is remembered. Only while the courier is online. Refreshes every 60 s while shown.
 * Reads the courier app's own globals (map, COURIER_IS_ONLINE, courierLat, courierLng) the same way js/courier-location-report.js does. Fails quietly without Google Maps.
 * The hexagon maths and the gold scale are the same as js/driver-demand.js (a CI check keeps the numbers equal).
 */
(function () {
  'use strict';
  if (window.__etelCourierDemand) return;
  window.__etelCourierDemand = true;

  var PREF = 'etel_courier_hot_areas';          // 'off' once the courier hides the hexagons; otherwise shown
  var AM = { 'Hot areas': '\u121e\u1243\u1273\u121b \u12a0\u12ab\u1263\u1262\u12ce\u127d', 'Quiet': '\u1338\u1325 \u12eb\u1208', 'Busiest': '\u1260\u1323\u121d \u12e8\u1260\u12db', 'Go there': '\u12c8\u12f0\u12da\u12eb \u1202\u12f5', 'jobs': '\u1225\u122b\u12ce\u127d', 'job': '\u1225\u122b', 'km away': '\u12aa.\u121c \u122d\u1240\u1275', 'Hide hot areas': '\u121e\u1243\u1273\u121b \u12a0\u12ab\u1263\u1262\u12ce\u127d\u1295 \u12f0\u1265\u1245', 'Show hot areas': '\u121e\u1243\u1273\u121b \u12a0\u12ab\u1263\u1262\u12ce\u127d\u1295 \u12a0\u1233\u12ed' };
  var overlays = [], hexes = [], areas = [], size = 700, shown = true, wrap = null, card = null, timerOn = false, busy = false, lastFetch = 0;

  function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
  function pref() { try { return localStorage.getItem(PREF); } catch (e) { return null; } }
  function setPref(v) { try { localStorage.setItem(PREF, v); } catch (e) {} }
  function getMap() {
    try { if (typeof map !== 'undefined' && map && map.controls) return map; } catch (e) {}
    return window.__etelCourierMap && window.__etelCourierMap.controls ? window.__etelCourierMap : null;
  }
  function online() { try { return typeof COURIER_IS_ONLINE !== 'undefined' && !!COURIER_IS_ONLINE; } catch (e) { return false; } }
  function pos() {
    try { if (typeof courierLat !== 'undefined' && typeof courierLng !== 'undefined' && courierLat && courierLng) return { lat: +courierLat, lng: +courierLng }; } catch (e) {}
    return null;
  }
  function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
  function apiUrl() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }

  /* ---------- hexagons (same cells and constants as api/lib/hexgrid.php and js/driver-demand.js) ---------- */
  var HEX_LAT0 = 7.0621, HEX_LNG0 = 38.4761, EARTH_R = 6371000.0;
  function hexCorners(q, r, sz) {
    var cx = sz * 1.5 * q, cy = sz * (Math.sqrt(3) / 2 * q + Math.sqrt(3) * r), lat0 = HEX_LAT0 * Math.PI / 180, out = [];
    for (var i = 0; i < 6; i++) {
      var a = Math.PI / 180 * 60 * i, x = cx + sz * Math.cos(a), y = cy + sz * Math.sin(a);
      out.push({ lat: HEX_LAT0 + (y / EARTH_R) * 180 / Math.PI, lng: HEX_LNG0 + (x / (Math.cos(lat0) * EARTH_R)) * 180 / Math.PI });
    }
    return out;
  }
  var GOLD_LO = [154, 122, 30], GOLD_HI = [255, 226, 122];
  function goldFor(h) {
    h = Math.max(0, Math.min(1, +h || 0));
    var c = GOLD_LO.map(function (v, i) { return Math.round(v + (GOLD_HI[i] - v) * h); });
    return '#' + c.map(function (v) { return (v < 16 ? '0' : '') + v.toString(16); }).join('');
  }
  function clear() { overlays.forEach(function (o) { try { o.setMap(null); } catch (e) {} }); overlays = []; }
  function draw() {
    clear();
    var m = getMap();
    if (!m || !shown || !online() || !window.google || !google.maps || !google.maps.Polygon) return;
    hexes.slice().sort(function (a, b) { return (a.heat || 0) - (b.heat || 0); }).forEach(function (c) {   // brightest drawn last, on top
      if (typeof c.q !== 'number' || typeof c.r !== 'number') return;
      var h = Math.max(0, Math.min(1, +c.heat || 0)), col = goldFor(h);
      overlays.push(new google.maps.Polygon({
        map: m, paths: hexCorners(c.q, c.r, size), clickable: false, zIndex: 1 + Math.round(h * 10),
        strokeColor: h >= 0.8 ? '#FFF3B0' : col, strokeOpacity: 0.55 + 0.45 * h, strokeWeight: 1 + 2 * h,
        fillColor: col, fillOpacity: 0.12 + 0.5 * h
      }));
    });
  }

  /* ---------- data ---------- */
  function load() {
    if (busy || !token()) return;
    busy = true; lastFetch = Date.now();
    var p = pos(), body = { action: 'courier_get_demand' };
    if (p && Math.abs(p.lat) <= 90 && Math.abs(p.lng) <= 180) { body.lat = p.lat; body.lng = p.lng; }
    fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.success && j.data) { hexes = j.data.hexes || []; areas = j.data.areas || []; size = +j.data.size_m || 700; }
        draw(); paintCard();
      })
      .catch(function () { /* offline or server error: keep what is on the map */ })
      .then(function () { busy = false; });
  }

  /* ---------- the card (a Google map control, top left) ---------- */
  function mk(tag, css, text) { var e = document.createElement(tag); if (css) e.style.cssText = css; if (text) e.textContent = text; return e; }
  function build(m) {
    wrap = mk('div', 'margin:10px 0 0 10px;max-width:210px;font:600 12.5px system-ui,sans-serif;color:#F7F5EE;display:none');
    wrap.id = 'etel-courier-demand';
    card = mk('div', 'background:rgba(17,19,28,.94);border:1px solid rgba(212,175,55,.5);border-radius:14px;padding:9px 11px;box-shadow:0 2px 8px rgba(0,0,0,.35)');
    wrap.appendChild(card);
    m.controls[google.maps.ControlPosition.LEFT_TOP].push(wrap);
  }
  function paintCard() {
    if (!card) return;
    card.innerHTML = '';
    var head = mk('div', 'display:flex;align-items:center;justify-content:space-between;gap:8px');
    head.appendChild(mk('b', 'font-size:13px', T('Hot areas')));
    var tog = mk('button', 'min-height:28px;padding:0 10px;border-radius:999px;border:1px solid rgba(212,175,55,.55);background:' + (shown ? 'linear-gradient(135deg,#F4D56A,#C89A24)' : 'transparent') + ';color:' + (shown ? '#16120A' : '#F7F5EE') + ';font:700 11.5px system-ui,sans-serif;cursor:pointer', shown ? 'On' : 'Off');
    tog.type = 'button'; tog.id = 'etel-courier-demand-toggle';
    tog.setAttribute('aria-pressed', shown ? 'true' : 'false'); tog.setAttribute('aria-label', shown ? T('Hide hot areas') : T('Show hot areas'));
    tog.addEventListener('click', function () { shown = !shown; setPref(shown ? 'on' : 'off'); if (shown) { draw(); load(); } else { clear(); } paintCard(); });
    head.appendChild(tog); card.appendChild(head);
    if (!shown) return;
    var lg = mk('div', 'display:flex;align-items:center;gap:6px;margin-top:7px;font-size:11px;color:#C4C6D0');
    lg.appendChild(mk('span', '', T('Quiet')));
    lg.appendChild(mk('i', 'display:block;flex:1;height:7px;border-radius:4px;background:linear-gradient(90deg,#9A7A1E,#FFE27A)'));
    lg.appendChild(mk('span', '', T('Busiest')));
    card.appendChild(lg);
    if (areas.length) {
      var a = areas[0], km = (a.distance_km === null || a.distance_km === undefined) ? '' : (' \u00b7 ' + a.distance_km + ' ' + T('km away'));
      card.appendChild(mk('div', 'margin-top:7px;color:#FFE27A', a.requests + ' ' + T(a.requests === 1 ? 'job' : 'jobs') + km));
      var go = mk('button', 'width:100%;min-height:34px;margin-top:6px;border:0;border-radius:10px;background:linear-gradient(135deg,#F4D56A,#C89A24);color:#16120A;font:800 12.5px system-ui,sans-serif;cursor:pointer', T('Go there'));
      go.type = 'button'; go.id = 'etel-courier-demand-go';
      go.addEventListener('click', function () {
        var m = getMap(); if (!m) return;
        try { m.panTo({ lat: +a.lat, lng: +a.lng }); if (m.getZoom && m.getZoom() < 15 && m.setZoom) m.setZoom(15); } catch (e) {}
      });
      card.appendChild(go);
    }
  }

  /* ---------- keep it in step with the app ---------- */
  function tick() {
    var m = getMap();
    if (!m || !window.google || !google.maps || !google.maps.Polygon) return;
    if (!wrap) { shown = pref() !== 'off'; build(m); paintCard(); }
    var on = online();
    wrap.style.display = on ? 'block' : 'none';
    if (!on) { clear(); hexes = []; areas = []; lastFetch = 0; return; }
    if (shown && (!lastFetch || Date.now() - lastFetch >= 60000)) load();
  }
  if (!timerOn) { timerOn = true; setInterval(tick, 2500); setTimeout(tick, 800); }
})();
