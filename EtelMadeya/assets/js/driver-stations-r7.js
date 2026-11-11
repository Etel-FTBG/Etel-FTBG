// Driver "Live Stations": the same Google Map the Delivery & Ride apps use (key comes from /delivery/api/boot.php),
// with a Leaflet fallback. Tap a station card (or its marker) -> the map flies there and draws the route from you.
(function () {
    var engine = '';                       // 'google' | 'leaflet' | ''
    var map = null, markers = {}, meMarker = null, me = null, timer = null, watchId = null, fitted = false, lastData = [];
    var allData = [], region = (function () { try { return localStorage.getItem('mad_stn_region') || ''; } catch (e) { return ''; } })();
    var selected = 0, dirSvc = null, dirRen = null, routeLine = null, infoWin = null, tryingGoogle = null, routeRes = null, routeSt = null;
    var nav = { on: false, st: null, steps: [], idx: 0, spoken: {}, off: 0, lastReroute: 0, muted: false, wake: null, poly: null, heading: null, first: true, lastPos: null };

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function km(a, b, c, d) {
        var R = 6371, t = Math.PI / 180, dLa = (c - a) * t, dLo = (d - b) * t;
        var x = Math.sin(dLa / 2) * Math.sin(dLa / 2) + Math.cos(a * t) * Math.cos(c * t) * Math.sin(dLo / 2) * Math.sin(dLo / 2);
        return 2 * R * Math.asin(Math.sqrt(x));
    }
    var ROOT = (function () {
        var src = (document.currentScript && document.currentScript.src || '').replace(/[?#].*$/, '');
        return /assets\/js\//.test(src) ? src.replace(/assets\/js\/[^\/]*$/, '') : '';
    })();
    var HAWASSA = { lat: 7.0621, lng: 38.4763 };
    function navUrl(s) { return 'https://www.google.com/maps/dir/?api=1&destination=' + s.lat + ',' + s.lng + '&travelmode=driving'; }
    function byId(id) { for (var i = 0; i < lastData.length; i++) if (lastData[i].id == id) return lastData[i]; return null; }
    function pinSvg(open, sel) {
        var c = open ? '#d4af37' : '#8a8a8a', s = sel ? 1.25 : 1, w = Math.round(40 * s), h = Math.round(50 * s);
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '" viewBox="0 0 40 50"><path d="M20 49C20 49 3 31 3 19a17 17 0 0 1 34 0c0 12-17 30-17 30z" fill="' + c + '" stroke="#fff" stroke-width="3"/><text x="20" y="25" font-size="16" text-anchor="middle">⛽</text></svg>';
        return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
    }

    // ── engines ─────────────────────────────────────────────────────
    function loadGoogle(cb) {
        if (window.google && google.maps && google.maps.Map) return cb(true);
        if (tryingGoogle) { tryingGoogle.push(cb); return; }
        tryingGoogle = [cb];
        function done(ok) { var q = tryingGoogle; tryingGoogle = null; q.forEach(function (f) { f(ok); }); }
        fetch('/delivery/api/boot.php?action=get_config', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
            var key = d && d.success && d.data && d.data.google_maps_api_key;
            if (!key) return done(false);
            window.gm_authFailure = function () { window.__madMapsFailed = true; fallbackToLeaflet(); };
            window.__madMapsReady = function () { done(true); };
            var s = document.createElement('script');
            s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&libraries=geometry&loading=async&callback=__madMapsReady';
            s.async = true; s.defer = true; s.onerror = function () { done(false); };
            document.head.appendChild(s);
            setTimeout(function () { if (tryingGoogle) done(false); }, 9000);
        }).catch(function () { done(false); });
    }
    function loadLeaflet(cb) {
        if (window.L) return cb(true);
        var css = document.createElement('link'); css.rel = 'stylesheet'; css.href = ROOT + 'assets/vendor/leaflet/leaflet.css'; document.head.appendChild(css);
        var js = document.createElement('script'); js.src = ROOT + 'assets/vendor/leaflet/leaflet.js'; js.onload = function () { cb(true); }; js.onerror = function () { cb(false); }; document.head.appendChild(js);
    }
    function initGoogle(el) {
        engine = 'google';
        map = new google.maps.Map(el, { center: HAWASSA, zoom: 13, mapTypeControl: false, streetViewControl: false, fullscreenControl: true, clickableIcons: false, gestureHandling: 'greedy',
            styles: [{ featureType: 'poi', stylers: [{ visibility: 'off' }] }, { featureType: 'transit', stylers: [{ visibility: 'off' }] }] });
        infoWin = new google.maps.InfoWindow();
        dirSvc = new google.maps.DirectionsService();
        dirRen = new google.maps.DirectionsRenderer({ map: map, suppressMarkers: true, preserveViewport: false, polylineOptions: { strokeColor: '#1a73e8', strokeWeight: 6, strokeOpacity: .9 } });
    }
    function initLeaflet(el) {
        engine = 'leaflet';
        map = L.map(el, { zoomControl: true }).setView([HAWASSA.lat, HAWASSA.lng], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
    }
    function fallbackToLeaflet() {
        if (engine === 'leaflet') return;
        var el = document.getElementById('stnMap'); if (!el) return;
        el.innerHTML = ''; markers = {}; meMarker = null; fitted = false; map = null; engine = '';
        loadLeaflet(function (ok) { if (ok) { initLeaflet(el); render(lastData); } else el.innerHTML = '<div style="padding:30px;text-align:center">Map could not load. The list below is still live.</div>'; });
    }

    // ── map actions ─────────────────────────────────────────────────
    function setMarker(s) {
        if (s.lat == null || s.lng == null || !map) return;
        var sel = selected == s.id;
        if (engine === 'google') {
            var icon = { url: pinSvg(s.is_open, sel), scaledSize: new google.maps.Size(sel ? 50 : 40, sel ? 62 : 50), anchor: new google.maps.Point(sel ? 25 : 20, sel ? 62 : 50) };
            if (markers[s.id]) { markers[s.id].setIcon(icon); markers[s.id].setPosition({ lat: s.lat, lng: s.lng }); }
            else { var m = new google.maps.Marker({ map: map, position: { lat: s.lat, lng: s.lng }, icon: icon, title: s.name }); m.addListener('click', function () { window.stnSelect(s.id); }); markers[s.id] = m; }
        } else if (engine === 'leaflet') {
            var li = L.divIcon({ className: '', iconSize: [34, 42], iconAnchor: [17, 40], html: '<div style="width:34px;height:34px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:' + (s.is_open ? '#d4af37' : '#8a8a8a') + ';border:3px solid #fff;box-shadow:0 3px 8px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center"><span style="transform:rotate(45deg);font-size:16px">⛽</span></div>' });
            if (markers[s.id]) { markers[s.id].setIcon(li).setLatLng([s.lat, s.lng]); }
            else { markers[s.id] = L.marker([s.lat, s.lng], { icon: li }).addTo(map).on('click', function () { window.stnSelect(s.id); }); }
        }
    }
    function setMe() {
        if (!me || !map) return;
        if (engine === 'google') {
            var ico = (nav.on && nav.heading != null)
                ? { path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW, scale: 7, rotation: nav.heading, fillColor: '#1a73e8', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2.5 }
                : { path: google.maps.SymbolPath.CIRCLE, scale: 9, fillColor: '#1a73e8', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 3 };
            if (meMarker) { meMarker.setPosition(me); meMarker.setIcon(ico); }
            else meMarker = new google.maps.Marker({ map: map, position: me, title: 'You are here', zIndex: 999, icon: ico });
        } else if (engine === 'leaflet') {
            if (meMarker) meMarker.setLatLng([me.lat, me.lng]);
            else meMarker = L.circleMarker([me.lat, me.lng], { radius: 9, color: '#fff', weight: 3, fillColor: '#1a73e8', fillOpacity: 1 }).addTo(map);
        }
    }
    function clearRoute() {
        if (engine === 'google' && dirRen) dirRen.set('directions', null);
        if (engine === 'leaflet' && routeLine) { map.removeLayer(routeLine); routeLine = null; }
    }
    function showRouteInfo(s, text) {
        var el = document.getElementById('stnRoute'); if (!el) return;
        el.innerHTML = '<div class="stn-route-in"><div><b>' + esc(s.name) + '</b><span>' + text + '</span></div><div class="stn-route-btns"><button type="button" class="stn-btn" onclick="stnNavStart()">▶ Start navigation</button><button type="button" class="stn-btn ghost" onclick="stnClear()">Close</button></div></div>';
        el.style.display = 'block';
    }
    window.stnClear = function () {
        if (nav.on) endNav(false);
        selected = 0; routeRes = null; clearRoute(); var el = document.getElementById('stnRoute'); if (el) el.style.display = 'none';
        lastData.forEach(setMarker); render(lastData, true);
    };
    window.stnSelect = function (id) {
        var s = byId(id); if (!s) return;
        routeRes = null; routeSt = s;
        if (s.lat == null || s.lng == null) { alert('This station has no location on the map yet.'); return; }
        selected = s.id; lastData.forEach(setMarker); render(lastData, true);
        var mapEl = document.getElementById('stnMap'); if (mapEl) mapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (!map) { showRouteInfo(s, 'Map not available - use Google Maps to navigate'); return; }
        var straight = me ? km(me.lat, me.lng, s.lat, s.lng) : null;
        if (engine === 'google') {
            if (me && dirSvc) {
                dirSvc.route({ origin: me, destination: { lat: s.lat, lng: s.lng }, travelMode: 'DRIVING' }, function (res, st) {
                    if (st === 'OK' && res.routes[0]) {
                        routeRes = res; routeSt = s;
                        dirRen.setDirections(res);
                        var l = res.routes[0].legs[0];
                        showRouteInfo(s, '🚗 ' + esc(l.distance.text) + ' · ' + esc(l.duration.text) + ' &middot; ' + s.queued + ' cars waiting');
                    } else {
                        map.panTo({ lat: s.lat, lng: s.lng }); map.setZoom(16);
                        showRouteInfo(s, (straight != null ? '~' + straight.toFixed(1) + ' km away &middot; ' : '') + s.queued + ' cars waiting');
                    }
                });
            } else { map.panTo({ lat: s.lat, lng: s.lng }); map.setZoom(16); showRouteInfo(s, 'Allow location to see the route &middot; ' + s.queued + ' cars waiting'); }
        } else if (engine === 'leaflet') {
            clearRoute();
            if (me) { routeLine = L.polyline([[me.lat, me.lng], [s.lat, s.lng]], { color: '#1a73e8', weight: 5, dashArray: '8 8' }).addTo(map); map.fitBounds([[me.lat, me.lng], [s.lat, s.lng]], { padding: [50, 50] }); }
            else map.setView([s.lat, s.lng], 16);
            showRouteInfo(s, (straight != null ? '~' + straight.toFixed(1) + ' km away &middot; ' : '') + s.queued + ' cars waiting');
        }
    };
    window.stnFocus = window.stnSelect;

    // ── in-app turn-by-turn navigation ──────────────────────────────
    function strip(h) { var d = document.createElement('div'); d.innerHTML = String(h || ''); return (d.textContent || '').replace(/\s+/g, ' ').trim(); }
    function fmtDist(m) { return m >= 1000 ? (m / 1000).toFixed(1) + ' km' : (Math.round(m / 10) * 10) + ' m'; }
    function arrowFor(man, bearingDeg) {
        if (bearingDeg != null) return '<span style="display:inline-block;transform:rotate(' + Math.round(bearingDeg) + 'deg)">↑</span>';
        man = String(man || '');
        if (/uturn/.test(man)) return '↩';
        if (/roundabout/.test(man)) return '⟳';
        if (/sharp-left|turn-left/.test(man)) return '↰';
        if (/sharp-right|turn-right/.test(man)) return '↱';
        if (/slight-left|fork-left|keep-left|ramp-left/.test(man)) return '↖';
        if (/slight-right|fork-right|keep-right|ramp-right/.test(man)) return '↗';
        return '↑';
    }
    function bearing(a, b) {
        var t = Math.PI / 180, y = Math.sin((b.lng - a.lng) * t) * Math.cos(b.lat * t);
        var x = Math.cos(a.lat * t) * Math.sin(b.lat * t) - Math.sin(a.lat * t) * Math.cos(b.lat * t) * Math.cos((b.lng - a.lng) * t);
        return (Math.atan2(y, x) / t + 360) % 360;
    }
    function dm(a, b) { return km(a.lat, a.lng, b.lat, b.lng) * 1000; }
    function say(text) {
        if (nav.muted || !('speechSynthesis' in window)) return;
        try { var u = new SpeechSynthesisUtterance(text); u.lang = 'en-US'; u.rate = 1; speechSynthesis.cancel(); speechSynthesis.speak(u); } catch (e) {}
    }
    function loadSteps(res) {
        nav.steps = []; nav.idx = 0; nav.spoken = {};
        if (!res || !res.routes[0]) return;
        var leg = res.routes[0].legs[0];
        leg.steps.forEach(function (st) {
            nav.steps.push({ text: strip(st.instructions), man: st.maneuver || '', m: st.distance.value, sec: st.duration.value, end: { lat: st.end_location.lat(), lng: st.end_location.lng() } });
        });
        try { nav.poly = new google.maps.Polyline({ path: res.routes[0].overview_path }); } catch (e) { nav.poly = null; }
    }
    window.stnNavStart = function () {
        var s = routeSt; if (!s) return;
        if (!me) { alert('Turn on your phone location to start navigation.'); return; }
        nav.on = true; nav.st = s; nav.muted = false; nav.off = 0; nav.first = true; nav.lastPos = null; nav.heading = null;
        if (engine === 'google') loadSteps(routeRes);
        var wrap = document.getElementById('stnMapWrap');
        nav.ph = document.createComment('stn'); wrap.parentNode.insertBefore(nav.ph, wrap); document.body.appendChild(wrap); // escape page stacking contexts
        wrap.classList.add('on');
        document.body.classList.add('stn-nav-lock');
        document.getElementById('stnNavTop').style.display = 'flex';
        document.getElementById('stnNavBot').style.display = 'flex';
        try { if (navigator.wakeLock) navigator.wakeLock.request('screen').then(function (w) { nav.wake = w; }).catch(function () {}); } catch (e) {}
        setTimeout(function () {
            if (engine === 'google') google.maps.event.trigger(map, 'resize'); else if (map && map.invalidateSize) map.invalidateSize();
            say('Starting navigation to ' + s.name);
            navTick();
        }, 120);
    };
    function endNav(arrived) {
        var s = nav.st; nav.on = false;
        var wrap2 = document.getElementById('stnMapWrap');
        wrap2.classList.remove('on');
        if (nav.ph && nav.ph.parentNode) { nav.ph.parentNode.insertBefore(wrap2, nav.ph); nav.ph.parentNode.removeChild(nav.ph); } nav.ph = null;
        document.body.classList.remove('stn-nav-lock');
        document.getElementById('stnNavTop').style.display = 'none';
        document.getElementById('stnNavBot').style.display = 'none';
        try { if (nav.wake) nav.wake.release(); } catch (e) {} nav.wake = null;
        try { if ('speechSynthesis' in window) speechSynthesis.cancel(); } catch (e) {}
        if (engine === 'google') { if (map) { google.maps.event.trigger(map, 'resize'); } setMe(); }
        if (arrived && s) { var el = document.getElementById('stnRoute'); if (el) { el.innerHTML = '<div class="stn-route-in"><div><b>🏁 You have arrived</b><span>' + esc(s.name) + ' &middot; ' + s.queued + ' cars waiting</span></div><div class="stn-route-btns"><button type="button" class="stn-btn ghost" onclick="stnClear()">Close</button></div></div>'; el.style.display = 'block'; } }
    }
    window.stnNavEnd = function () { endNav(false); };
    window.stnNavMute = function () {
        nav.muted = !nav.muted; if (nav.muted) { try { speechSynthesis.cancel(); } catch (e) {} }
        var b = document.getElementById('stnMuteBtn'); if (b) b.textContent = nav.muted ? '🔇 Voice off' : '🔊 Voice on';
    };
    window.stnNavRecenter = function () { nav.first = true; navTick(); };
    function reroute() {
        if (engine !== 'google' || !dirSvc || !nav.st || Date.now() - nav.lastReroute < 15000) return;
        nav.lastReroute = Date.now();
        dirSvc.route({ origin: me, destination: { lat: nav.st.lat, lng: nav.st.lng }, travelMode: 'DRIVING' }, function (res, st) {
            if (st === 'OK' && res.routes[0]) { routeRes = res; dirRen.setDirections(res); loadSteps(res); nav.off = 0; say('Rerouting'); }
        });
    }
    function navTick() {
        if (!nav.on || !me || !nav.st) return;
        var dest = { lat: nav.st.lat, lng: nav.st.lng }, dDest = dm(me, dest);
        if (dDest < 40) { say('You have arrived at ' + nav.st.name); endNav(true); return; }
        var top = document.getElementById('stnNavTop'), bot = document.getElementById('stnNavBot');
        var arrow, text, nextM, remM, remSec;
        if (engine === 'google' && nav.steps.length) {
            while (nav.idx < nav.steps.length - 1 && dm(me, nav.steps[nav.idx].end) < 25) nav.idx++;
            var st = nav.steps[nav.idx];
            nextM = dm(me, st.end);
            remM = nextM; remSec = st.m ? st.sec * Math.min(1, nextM / st.m) : st.sec;
            for (var i = nav.idx + 1; i < nav.steps.length; i++) { remM += nav.steps[i].m; remSec += nav.steps[i].sec; }
            var nxt = nav.steps[nav.idx];
            arrow = arrowFor(nxt.man); text = nxt.text || 'Continue';
            if (nav.idx === nav.steps.length - 1) { text = 'Arrive at ' + nav.st.name; arrow = '🏁'; }
            if (nextM <= 300 && !nav.spoken[nav.idx + 'a']) { nav.spoken[nav.idx + 'a'] = 1; say('In ' + fmtDist(nextM) + ', ' + text); }
            if (nextM <= 45 && !nav.spoken[nav.idx + 'b']) { nav.spoken[nav.idx + 'b'] = 1; say(text); }
            // off-route detection
            try {
                if (nav.poly && google.maps.geometry && !google.maps.geometry.poly.isLocationOnEdge(new google.maps.LatLng(me.lat, me.lng), nav.poly, 0.0004)) { nav.off++; if (nav.off >= 3) reroute(); }
                else nav.off = 0;
            } catch (e) {}
        } else {
            nextM = dDest; remM = dDest; remSec = dDest / 8.3; // ~30 km/h
            arrow = arrowFor('', nav.heading != null ? (bearing(me, dest) - nav.heading + 360) % 360 : bearing(me, dest));
            text = 'Head to ' + nav.st.name;
        }
        var eta = new Date(Date.now() + remSec * 1000);
        top.innerHTML = '<div class="stn-nav-arrow">' + arrow + '</div><div class="stn-nav-txt"><b>' + fmtDist(nextM) + '</b><span>' + esc(text) + '</span></div>';
        bot.innerHTML = '<div class="stn-nav-stat"><b>' + Math.max(1, Math.round(remSec / 60)) + ' min</b><span>' + fmtDist(remM) + ' &middot; ' + eta.getHours() + ':' + ('0' + eta.getMinutes()).slice(-2) + '</span></div>' +
            '<div class="stn-nav-btns"><button type="button" class="stn-btn ghost" id="stnMuteBtn" onclick="stnNavMute()">' + (nav.muted ? '🔇 Voice off' : '🔊 Voice on') + '</button><button type="button" class="stn-btn ghost" onclick="stnNavRecenter()">◎</button><button type="button" class="stn-btn stn-end" onclick="stnNavEnd()">✕ End</button></div>';
        // follow the driver
        if (engine === 'google') { map.panTo(me); if (nav.first) { map.setZoom(17); nav.first = false; } }
        else if (engine === 'leaflet') { map.setView([me.lat, me.lng], nav.first ? 17 : map.getZoom()); nav.first = false; }
    }


    // ── list ────────────────────────────────────────────────────────
    function regionOf(s) { return s.region || '__other'; }
    function fillRegions() {
        var sel = document.getElementById('stnRegion'); if (!sel) return;
        var counts = {}, order = [];
        allData.forEach(function (s) { var r = regionOf(s); if (!counts[r]) { counts[r] = 0; order.push(r); } counts[r]++; });
        order.sort(function (a, b) { if (a === '__other') return 1; if (b === '__other') return -1; return a.localeCompare(b); });
        if (region && !counts[region]) region = '';
        var html = '<option value="">All regions (' + allData.length + ')</option>' + order.map(function (r) {
            return '<option value="' + esc(r) + '"' + (r === region ? ' selected' : '') + '>' + (r === '__other' ? 'Other' : esc(r)) + ' (' + counts[r] + ')</option>';
        }).join('');
        if (sel.getAttribute('data-sig') !== html) { sel.innerHTML = html; sel.setAttribute('data-sig', html); }
        sel.value = region;
    }
    window.stnRegionPick = function (v) {
        region = v || ''; try { localStorage.setItem('mad_stn_region', region); } catch (e) {}
        if (nav.on) endNav(false);
        selected = 0; routeRes = null; clearRoute(); var rt = document.getElementById('stnRoute'); if (rt) rt.style.display = 'none';
        fitted = false; render(allData, false);
    };
    function render(list, noFit) {
        if (list !== lastData) allData = list;
        fillRegions();
        list = region ? allData.filter(function (s) { return regionOf(s) === region; }) : allData.slice();
        Object.keys(markers).forEach(function (id) {
            if (list.some(function (s) { return String(s.id) === id; })) return;
            try { if (engine === 'google') markers[id].setMap(null); else if (engine === 'leaflet') map.removeLayer(markers[id]); } catch (e) {}
            delete markers[id];
        });
        lastData = list;
        var grid = document.getElementById('stnGrid'); if (!grid) return;
        var rows = list.slice();
        rows.forEach(function (s) { s._d = (me && s.lat != null) ? km(me.lat, me.lng, s.lat, s.lng) : null; });
        rows.sort(function (a, b) {
            if (a.is_open !== b.is_open) return b.is_open - a.is_open;
            if (a._d != null && b._d != null) return a._d - b._d;
            return a.queued - b.queued;
        });
        var openN = rows.filter(function (s) { return s.is_open; }).length;
        var sum = document.getElementById('stnSummary');
        if (sum) sum.innerHTML = '<span class="live-dot"></span> LIVE · <b>' + openN + '</b> open of ' + rows.length + ' stations' + (region ? ' in ' + (region === '__other' ? 'Other' : esc(region)) : '') + ' · tap a station to see the route · updated ' + new Date().toLocaleTimeString();
        grid.innerHTML = rows.length ? rows.map(function (s) {
            var chips = (s.types || []).map(function (t) {
                return '<span class="stn-chip">' + (window.vehPic ? vehPic(t.icon, t.name, 22) : '') + esc(t.name) + ' <b>' + t.count + '</b></span>';
            }).join('');
            var can = s.lat != null && s.lng != null;
            return '<div class="stn-card ' + (s.is_open ? '' : 'closed') + (can ? ' tap' : '') + (selected == s.id ? ' sel' : '') + '"' + (can ? ' onclick="stnSelect(' + s.id + ')"' : '') + '>' +
                '<div class="stn-top"><div><div class="stn-name">' + esc(s.name) + '</div><div class="stn-addr">' + esc(s.address || '') + (s.open_hours ? ' · ' + esc(s.open_hours) : '') + '</div></div>' +
                '<span class="stn-pill ' + (s.is_open ? 'open' : 'closed') + '">' + (s.is_open ? 'OPEN TODAY' : 'CLOSED') + '</span></div>' +
                '<div class="stn-nums"><div class="stn-num"><b>' + s.queued + '</b><span>Cars waiting</span></div><div class="stn-num"><b>' + s.served_today + '</b><span>Served today</span></div>' +
                (s._d != null ? '<div class="stn-num"><b>' + s._d.toFixed(1) + '</b><span>km away</span></div>' : '') + '</div>' +
                (chips ? '<div class="stn-chips">' + chips + '</div>' : '') +
                (s.fuel_status === 'short' || s.fuel_status === 'out' ? '<div class="stn-fuel bad">⛽ ' + (s.fuel_status === 'out' ? 'Fuel finished' : 'Fuel may run out before the queue is served') + '</div>' : (s.fuel_status === 'low' ? '<div class="stn-fuel low">⛽ Fuel is running low</div>' : '')) +
                '<div class="stn-actions">' + (can ? '<button type="button" class="stn-btn" onclick="event.stopPropagation();stnSelect(' + s.id + ')">🧭 Navigate on map</button>' : '<span class="stn-dist">Location not set yet</span>') + '</div></div>';
        }).join('') : '<div class="card">No stations yet.</div>';

        if (map) {
            var pts = [];
            list.forEach(function (s) { if (s.lat != null && s.lng != null) { pts.push([s.lat, s.lng]); setMarker(s); } });
            if (me) pts.push([me.lat, me.lng]);
            if (!fitted && !noFit && pts.length) {
                fitted = true;
                if (engine === 'google') { var b = new google.maps.LatLngBounds(); pts.forEach(function (p) { b.extend({ lat: p[0], lng: p[1] }); }); map.fitBounds(b, 50); google.maps.event.addListenerOnce(map, 'idle', function () { if (map.getZoom() > 16) map.setZoom(16); }); }
                else map.fitBounds(pts, { padding: [40, 40], maxZoom: 15 });
            }
        }
    }

    function poll() {
        if (!document.getElementById('stnGrid')) { stop(); return; }
        fetch(API + '&action=public_stations', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
            if (window.madHandleAuth && madHandleAuth(d)) { stop(); var mc = document.getElementById('mainContent'); if (mc && window.madLockCard) mc.innerHTML = madLockCard(); return; }
            if (d && d.success) render(d.stations || [], true);
        }).catch(function () {});
    }
    function stop() {
        if (timer) clearInterval(timer); timer = null;
        if (watchId != null && navigator.geolocation) navigator.geolocation.clearWatch(watchId); watchId = null;
        if (nav.on) { try { endNav(false); } catch (e) {} }
        map = null; markers = {}; meMarker = null; fitted = false; selected = 0; engine = ''; dirSvc = dirRen = infoWin = routeLine = null; routeRes = routeSt = null;
    }

    window.initStationsPage = function () {
        stop();
        var el = document.getElementById('stnMap'); if (!el) return;
        el.innerHTML = '<div style="padding:30px;text-align:center;opacity:.7">Loading map…</div>';
        loadGoogle(function (ok) {
            el = document.getElementById('stnMap'); if (!el) return;
            if (ok && !window.__madMapsFailed) { el.innerHTML = ''; initGoogle(el); setMe(); render(lastData); poll(); }
            else loadLeaflet(function (ok2) {
                el = document.getElementById('stnMap'); if (!el) return;
                if (ok2) { el.innerHTML = ''; initLeaflet(el); setMe(); render(lastData); poll(); }
                else { el.innerHTML = '<div style="padding:30px;text-align:center">Map could not load. The list below is still live.</div>'; poll(); }
            });
        });
        poll();
        timer = setInterval(poll, 10000);
        if (navigator.geolocation) {
            watchId = navigator.geolocation.watchPosition(function (p) {
                var first = !me, prev = me; me = { lat: p.coords.latitude, lng: p.coords.longitude };
                if (p.coords.heading != null && !isNaN(p.coords.heading) && (p.coords.speed || 0) > 1) nav.heading = p.coords.heading;
                else if (prev && dm(prev, me) > 8) nav.heading = bearing(prev, me);
                setMe(); if (nav.on) navTick(); else { if (first) { fitted = false; } render(lastData, !first); }
            }, function () {}, { enableHighAccuracy: true, maximumAge: 10000 });
        }
    };
})();
