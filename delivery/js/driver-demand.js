/* ETEL Driver - hot areas (additive script, same idea as sos-button.js).

   A "Hot areas" button on the driver's map while online and free. It shows where
   ride requests have been piling up in the last 45 minutes compared with how many
   idle drivers are there (api: driver_get_demand - counts only, never riders),
   as a ranked list with distances, and as coloured circles on the map.
   Red = high, amber = medium, grey = low. Tap an area to show it on the map or to
   open directions to it in the maps app. Refreshes every 60 s while circles are shown. The circles also appear by themselves when the driver goes online (hide them from the panel; that choice is remembered).
   28 Nov 2026: on the map the busy places are HEXAGONS (the same cells the server counts, api driver_get_demand 'hexes'), not circles. The busier a cell, the brighter
   the gold (heat 0 to 1 from the server) and the thicker its edge; when a peak window is active the busiest cells show the fare multiplier (for example 1.3x).
   Old servers without 'hexes' still get the circles.
   Reads DRV (driver.js) and google.maps only if they exist; fails quietly otherwise. */
(function () {
    'use strict';
    if (window.EtelDemand) return;
    var doc = document;
    var host = null, shadow = null, body = null, chip = null;
    var circles = [], shown = false, timer = 0, last = [], autoDone = false, meta = { hexes: [], size: 700, surge: null };
    var PREF = 'etel_demand_circles'; // 'off' once the driver hides the circles; otherwise they show by themselves
    function pref(v) { try { if (v === undefined) return localStorage.getItem(PREF); localStorage.setItem(PREF, v); } catch (e) {} return null; }

    function apiUrl() {
        var path = window.location.pathname, mk = '/delivery/', i = path.indexOf(mk);
        var base = i >= 0 ? path.substring(0, i + mk.length) : path.substring(0, path.lastIndexOf('/') + 1);
        return window.location.protocol + '//' + window.location.host + base + 'api/boot.php';
    }
    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
    function call(action, extra) {
        var p = { action: action }; for (var k in (extra || {})) p[k] = extra[k];
        return fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify(p) })
            .then(function (r) { return r.text(); })
            .then(function (t) { try { return JSON.parse(t); } catch (e) { return { success: false, message: 'Server error. Try again.' }; } })
            .catch(function () { return { success: false, message: 'Cannot reach the server. Check your connection.' }; });
    }
    function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function me() { var d = window.DRV; return d && d.currentLat ? { lat: d.currentLat, lng: d.currentLng } : null; }
    function fetchAreas() {
        var m = me(); var extra = m ? { lat: m.lat, lng: m.lng } : {};
        return call('driver_get_demand', extra).then(function (r) {
            if (!(r && r.success && r.data)) return null;
            meta = { hexes: r.data.hexes || [], size: +r.data.size_m || 700, surge: r.data.surge_now || null };
            return r.data.areas || [];
        });
    }

    /* ---------- hexagon map (same cells and constants as api/lib/hexgrid.php) ---------- */
    var HEX_LAT0 = 7.0621, HEX_LNG0 = 38.4761, EARTH_R = 6371000.0;
    function hexCorners(q, r, size) {
        var cx = size * 1.5 * q, cy = size * (Math.sqrt(3) / 2 * q + Math.sqrt(3) * r), lat0 = HEX_LAT0 * Math.PI / 180, out = [];
        for (var i = 0; i < 6; i++) {
            var a = Math.PI / 180 * 60 * i, x = cx + size * Math.cos(a), y = cy + size * Math.sin(a);
            out.push({ lat: HEX_LAT0 + (y / EARTH_R) * 180 / Math.PI, lng: HEX_LNG0 + (x / (Math.cos(lat0) * EARTH_R)) * 180 / Math.PI });
        }
        return out;
    }
    function hexCenter(q, r, size) {
        var cx = size * 1.5 * q, cy = size * (Math.sqrt(3) / 2 * q + Math.sqrt(3) * r), lat0 = HEX_LAT0 * Math.PI / 180;
        return { lat: HEX_LAT0 + (cy / EARTH_R) * 180 / Math.PI, lng: HEX_LNG0 + (cx / (Math.cos(lat0) * EARTH_R)) * 180 / Math.PI };
    }
    /* heat 0..1 -> gold: dim bronze gold (quiet) to bright gold (busiest) */
    var GOLD_LO = [154, 122, 30], GOLD_HI = [255, 226, 122];
    function goldFor(h) {
        h = Math.max(0, Math.min(1, +h || 0));
        var c = GOLD_LO.map(function (v, i) { return Math.round(v + (GOLD_HI[i] - v) * h); });
        return '#' + c.map(function (v) { return (v < 16 ? '0' : '') + v.toString(16); }).join('');
    }
    function surgePill(text) {
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="46" height="22" viewBox="0 0 46 22"><rect x="1" y="1" width="44" height="20" rx="10" fill="#FFE27A" stroke="#16120A" stroke-width="2"/>' +
            '<text x="23" y="15" text-anchor="middle" font-family="Arial,sans-serif" font-size="12" font-weight="800" fill="#16120A">' + String(text).replace(/[^0-9.x]/g, '') + '</text></svg>';
        return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
    }
    function drawHexes(d) {
        var gm = google.maps, hexes = meta.hexes.slice().sort(function (a, b) { return (a.heat || 0) - (b.heat || 0); }); // brightest drawn last, on top
        hexes.forEach(function (c) {
            if (typeof c.q !== 'number' || typeof c.r !== 'number') return;
            var h = Math.max(0, Math.min(1, +c.heat || 0)), col = goldFor(h);
            circles.push(new gm.Polygon({
                map: d.map, paths: hexCorners(c.q, c.r, meta.size), clickable: false, zIndex: 1 + Math.round(h * 10),
                strokeColor: h >= 0.8 ? '#FFF3B0' : col, strokeOpacity: 0.55 + 0.45 * h, strokeWeight: 1 + 2 * h,
                fillColor: col, fillOpacity: 0.12 + 0.5 * h
            }));
        });
        if (meta.surge && gm.Marker) {
            hexes.slice(-3).reverse().forEach(function (c) {
                if (typeof c.q !== 'number' || (+c.heat || 0) < 0.6) return;
                circles.push(new gm.Marker({ map: d.map, position: hexCenter(c.q, c.r, meta.size), clickable: false, zIndex: 50,
                    icon: { url: surgePill(meta.surge + 'x'), scaledSize: new gm.Size(46, 22), anchor: new gm.Point(23, 11) } }));
            });
        }
    }

    /* ---------- map circles (fallback for an old server) and the draw entry point ---------- */
    var COLORS = { high: '#E5484D', med: '#F5A524', low: '#8A8F9C' };
    function clearCircles() { circles.forEach(function (c) { try { c.setMap(null); } catch (e) {} }); circles = []; }
    function drawCircles(areas) {
        clearCircles();
        var d = window.DRV;
        if (!d || !d.map || !window.google || !google.maps || !google.maps.Circle) return;
        if (meta.hexes && meta.hexes.length && google.maps.Polygon) { drawHexes(d); return; }
        areas.forEach(function (a) {
            circles.push(new google.maps.Circle({
                map: d.map, center: { lat: a.lat, lng: a.lng }, radius: 350,
                strokeColor: COLORS[a.level] || COLORS.low, strokeOpacity: 0.9, strokeWeight: 2,
                fillColor: COLORS[a.level] || COLORS.low, fillOpacity: a.level === 'high' ? 0.32 : 0.2, clickable: false, zIndex: 1
            }));
        });
    }
    function focusArea(a) {
        var d = window.DRV;
        try { if (d && d.map) { d.map.panTo({ lat: a.lat, lng: a.lng }); if (d.map.getZoom && d.map.getZoom() < 15) d.map.setZoom(15); } } catch (e) {}
    }
    function setShown(on) {
        shown = on; refreshChip();
        clearInterval(timer);
        if (on) {
            timer = setInterval(function () { fetchAreas().then(function (a) { if (a && shown) { last = a; drawCircles(a); } }); }, 60000);
        } else { clearCircles(); }
    }

    /* ---------- panel (Shadow DOM) ---------- */
    var CSS = ':host{all:initial}*{box-sizing:border-box}' +
        '.bg{position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.7);display:flex;align-items:flex-end;justify-content:center;font-family:Outfit,system-ui,-apple-system,sans-serif}' +
        '@media(min-width:560px){.bg{align-items:center}}' +
        '.card{width:100%;max-width:460px;max-height:88vh;overflow:auto;background:#11131C;color:#F7F5EE;border:1px solid rgba(212,175,55,.35);border-radius:22px 22px 0 0;padding:18px 16px calc(16px + env(safe-area-inset-bottom,0px));box-shadow:0 20px 60px rgba(0,0,0,.6);line-height:1.5}' +
        '@media(min-width:560px){.card{border-radius:22px}}' +
        '.top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:4px}h2{margin:0;font-size:19px;font-weight:800}' +
        '.x{width:40px;height:40px;border-radius:50%;border:1px solid rgba(255,255,255,.18);background:transparent;color:#C4C6D0;font-size:18px;cursor:pointer}' +
        '.sub{color:#C4C6D0;font-size:13px;margin:0 0 10px}.dim{color:#9A9AAE;font-size:13px}' +
        '.area{padding:12px 0;border-top:1px solid rgba(255,255,255,.08)}' +
        '.hd{display:flex;align-items:center;gap:8px;margin-bottom:2px}.lv{padding:2px 10px;border-radius:999px;font-size:12px;font-weight:800;color:#16120A}' +
        '.lv.high{background:#FF8A8D}.lv.med{background:#F8C467}.lv.low{background:#B9BDC8}' +
        '.km{margin-left:auto;font-weight:700;font-size:14px}' +
        '.row{display:flex;gap:8px;margin-top:8px}' +
        '.b{flex:1;min-height:44px;padding:0 12px;border-radius:12px;border:1px solid rgba(212,175,55,.45);background:transparent;color:#F7F5EE;font:600 14px Outfit,system-ui,sans-serif;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}' +
        '.b.p{border:0;background:linear-gradient(135deg,#F4D56A,#C89A24);color:#16120A;font-weight:800}' +
        '.foot{display:flex;gap:8px;margin-top:12px}.foot .b{min-height:48px;font-size:15px}' +
        '.hb{height:6px;border-radius:3px;background:rgba(255,255,255,.1);margin-top:8px;overflow:hidden}.hb i{display:block;height:100%;border-radius:3px}' +
        '.lg{display:flex;align-items:center;gap:8px;margin:2px 0 8px;font-size:12px;color:#C4C6D0}.lg b{display:block;flex:1;max-width:130px;height:8px;border-radius:4px;background:linear-gradient(90deg,#9A7A1E,#FFE27A)}' +
        '.sg{margin:0 0 8px;padding:8px 10px;border-radius:10px;background:rgba(255,226,122,.12);border:1px solid rgba(255,226,122,.4);color:#FFE27A;font-weight:700;font-size:13px}';
    function build() {
        if (host) return;
        host = doc.createElement('div');
        host.style.cssText = 'all:initial;position:fixed;inset:0;z-index:2147483000;display:none';
        shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
        shadow.innerHTML = '<style>' + CSS + '</style><div class="bg"><div class="card"><div id="body"></div></div></div>';
        doc.body.appendChild(host);
        body = shadow.getElementById('body');
        shadow.querySelector('.bg').addEventListener('click', function (e) { if (e.target === e.currentTarget) closePanel(); });
    }
    function closePanel() { if (host) host.style.display = 'none'; }
    function levelText(l) { return l === 'high' ? 'Busy' : (l === 'med' ? 'Getting busy' : 'A little busy'); }
    function render(areas) {
        var html = '<div class="top"><h2>Hot areas</h2><button class="x" id="x" aria-label="Close">&#10005;</button></div>' +
            '<p class="sub">Where riders have been asking for rides in the last 45 minutes, compared with free drivers there.</p>' +
            (meta.surge ? '<div class="sg">Peak time: fares are x' + esc(meta.surge) + ' now</div>' : '') +
            '<div class="lg"><span>Quiet</span><b></b><span>Busiest</span></div>';
        if (!areas.length) html += '<p class="dim" style="padding:14px 0">No busy areas right now. Stay where you are, or check again in a few minutes.</p>';
        areas.forEach(function (a, i) {
            var km = a.distance_km === null || a.distance_km === undefined ? '' : (a.distance_km + ' km');
            html += '<div class="area"><div class="hd"><span class="lv ' + esc(a.level) + '">' + levelText(a.level) + '</span><span class="km">' + esc(km) + '</span></div>' +
                '<div class="dim">' + a.requests + ' request' + (a.requests === 1 ? '' : 's') + (a.waiting ? ', <b style="color:#FF9C98">' + a.waiting + ' waiting now</b>' : '') +
                ' &middot; ' + a.idle_drivers + ' free driver' + (a.idle_drivers === 1 ? '' : 's') + '</div>' +
                '<div class="hb"><i style="width:' + Math.round(Math.max(0.06, Math.min(1, +a.heat || 0)) * 100) + '%;background:' + goldFor(a.heat) + '"></i></div>' +
                '<div class="row"><button class="b" data-show="' + i + '">Show on map</button>' +
                '<a class="b p" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=' + a.lat + ',' + a.lng + '">Directions</a></div></div>';
        });
        html += '<div class="foot"><button class="b" id="refresh">Refresh</button><button class="b p" id="toggle">' + (shown ? 'Hide circles' : 'Show all on map') + '</button></div>';
        body.innerHTML = html;
        shadow.getElementById('x').onclick = closePanel;
        shadow.getElementById('refresh').onclick = openPanel;
        shadow.getElementById('toggle').onclick = function () { if (shown) { setShown(false); pref('off'); } else { drawCircles(last); setShown(true); pref('on'); } closePanel(); };
        [].forEach.call(shadow.querySelectorAll('[data-show]'), function (b) {
            b.onclick = function () { var a = last[parseInt(b.getAttribute('data-show'), 10)]; drawCircles(last); setShown(true); focusArea(a); closePanel(); };
        });
    }
    function openPanel() {
        build(); host.style.display = 'block';
        body.innerHTML = '<div class="top"><h2>Hot areas</h2><button class="x" id="x" aria-label="Close">&#10005;</button></div><p class="sub">Looking for busy areas&hellip;</p>';
        shadow.getElementById('x').onclick = closePanel;
        fetchAreas().then(function (a) {
            if (a === null) { body.innerHTML = '<div class="top"><h2>Hot areas</h2><button class="x" id="x" aria-label="Close">&#10005;</button></div><p class="sub">Could not load. Check your connection and try again.</p>'; shadow.getElementById('x').onclick = closePanel; return; }
            last = a; if (shown) drawCircles(a); render(a);
        });
    }

    /* ---------- the button ---------- */
    function chipCss() {
        if (doc.getElementById('etel-demand-css')) return;
        var s = doc.createElement('style'); s.id = 'etel-demand-css';
        s.textContent = '#etel-demand-chip{position:fixed;left:14px;top:calc(env(safe-area-inset-top,0px) + 138px);z-index:9400;min-height:40px;padding:0 14px;border-radius:999px;' +
            'border:1px solid rgba(212,175,55,.55);background:rgba(17,19,28,.92);color:#F7F5EE;font:700 13px Outfit,system-ui,sans-serif;display:none;align-items:center;gap:6px;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.4)}' +
            '#etel-demand-chip.on{background:linear-gradient(135deg,#F4D56A,#C89A24);color:#16120A;border-color:transparent}';
        doc.head.appendChild(s);
    }
    function buildChip() {
        if (chip) return;
        chipCss();
        chip = doc.createElement('button'); chip.id = 'etel-demand-chip'; chip.type = 'button';
        chip.addEventListener('click', openPanel);
        doc.body.appendChild(chip); refreshChip();
    }
    function refreshChip() { if (!chip) return; chip.className = shown ? 'on' : ''; chip.textContent = shown ? '🔥 Hot areas on' : '🔥 Hot areas'; }
    function wantChip() {
        var d = window.DRV;
        if (!d || !d.isOnline || d.activeRide) return false;
        var pg = doc.body.getAttribute('data-page') || 'requests';
        return pg === 'requests';
    }
    function tick() {
        buildChip();
        var on = wantChip();
        chip.style.display = on ? 'inline-flex' : 'none';
        if (!on && shown) setShown(false);
        if (!on) { autoDone = false; return; }
        // Show the busy-area circles on the map by themselves once per online session (the driver can hide them; that is remembered)
        if (!autoDone && !shown && pref() !== 'off' && window.DRV && DRV.map && window.google && google.maps && google.maps.Circle) {
            autoDone = true;
            fetchAreas().then(function (a) { if (a && a.length && wantChip() && pref() !== 'off') { last = a; drawCircles(a); setShown(true); } });
        }
    }

    window.EtelDemand = { open: openPanel, _tick: tick };
    function start() { setInterval(tick, 2500); }
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start); else start();
})();
