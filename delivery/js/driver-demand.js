/* ETEL Driver - hot areas (additive script, same idea as sos-button.js).

   A "Hot areas" button on the driver's map while online and free. It shows where
   ride requests have been piling up in the last 45 minutes compared with how many
   idle drivers are there (api: driver_get_demand - counts only, never riders),
   as a ranked list with distances, and as coloured circles on the map.
   Red = high, amber = medium, grey = low. Tap an area to show it on the map or to
   open directions to it in the maps app. Refreshes every 60 s while circles are shown.
   Reads DRV (driver.js) and google.maps only if they exist; fails quietly otherwise. */
(function () {
    'use strict';
    if (window.EtelDemand) return;
    var doc = document;
    var host = null, shadow = null, body = null, chip = null;
    var circles = [], shown = false, timer = 0, last = [];

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
        return call('driver_get_demand', extra).then(function (r) { return (r && r.success && r.data) ? r.data.areas || [] : null; });
    }

    /* ---------- map circles ---------- */
    var COLORS = { high: '#E5484D', med: '#F5A524', low: '#8A8F9C' };
    function clearCircles() { circles.forEach(function (c) { try { c.setMap(null); } catch (e) {} }); circles = []; }
    function drawCircles(areas) {
        clearCircles();
        var d = window.DRV;
        if (!d || !d.map || !window.google || !google.maps || !google.maps.Circle) return;
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
        '.foot{display:flex;gap:8px;margin-top:12px}.foot .b{min-height:48px;font-size:15px}';
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
            '<p class="sub">Where riders have been asking for rides in the last 45 minutes, compared with free drivers there.</p>';
        if (!areas.length) html += '<p class="dim" style="padding:14px 0">No busy areas right now. Stay where you are, or check again in a few minutes.</p>';
        areas.forEach(function (a, i) {
            var km = a.distance_km === null || a.distance_km === undefined ? '' : (a.distance_km + ' km');
            html += '<div class="area"><div class="hd"><span class="lv ' + esc(a.level) + '">' + levelText(a.level) + '</span><span class="km">' + esc(km) + '</span></div>' +
                '<div class="dim">' + a.requests + ' request' + (a.requests === 1 ? '' : 's') + (a.waiting ? ', <b style="color:#FF9C98">' + a.waiting + ' waiting now</b>' : '') +
                ' &middot; ' + a.idle_drivers + ' free driver' + (a.idle_drivers === 1 ? '' : 's') + '</div>' +
                '<div class="row"><button class="b" data-show="' + i + '">Show on map</button>' +
                '<a class="b p" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=' + a.lat + ',' + a.lng + '">Directions</a></div></div>';
        });
        html += '<div class="foot"><button class="b" id="refresh">Refresh</button><button class="b p" id="toggle">' + (shown ? 'Hide circles' : 'Show all on map') + '</button></div>';
        body.innerHTML = html;
        shadow.getElementById('x').onclick = closePanel;
        shadow.getElementById('refresh').onclick = openPanel;
        shadow.getElementById('toggle').onclick = function () { if (shown) { setShown(false); } else { drawCircles(last); setShown(true); } closePanel(); };
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
    }

    window.EtelDemand = { open: openPanel, _tick: tick };
    function start() { setInterval(tick, 2500); }
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start); else start();
})();
