// Driver "Stations" page: live Leaflet map + open/closed + cars queued, polled every 10s.
(function () {
    var map = null, markers = {}, me = null, meMarker = null, timer = null, watchId = null, fitted = false, lastData = [];

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function km(a, b, c, d) {
        var R = 6371, t = Math.PI / 180, dLa = (c - a) * t, dLo = (d - b) * t;
        var x = Math.sin(dLa / 2) * Math.sin(dLa / 2) + Math.cos(a * t) * Math.cos(c * t) * Math.sin(dLo / 2) * Math.sin(dLo / 2);
        return 2 * R * Math.asin(Math.sqrt(x));
    }
    function loadLeaflet(cb) {
        if (window.L) return cb();
        var css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(css);
        var js = document.createElement('script'); js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; js.onload = cb; js.onerror = function () { cb(true); }; document.head.appendChild(js);
    }
    function pin(open) {
        return L.divIcon({ className: '', iconSize: [34, 42], iconAnchor: [17, 40],
            html: '<div style="width:34px;height:34px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:' + (open ? '#d4af37' : '#8a8a8a') + ';border:3px solid #fff;box-shadow:0 3px 8px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center"><span style="transform:rotate(45deg);font-size:16px">⛽</span></div>' });
    }
    function navUrl(s) { return 'https://www.google.com/maps/dir/?api=1&destination=' + s.lat + ',' + s.lng + '&travelmode=driving'; }

    function popup(s) {
        return '<b>' + esc(s.name) + '</b><br>' + (s.is_open ? '🟢 Open' : '🔴 Closed') + ' · 🚗 ' + s.queued + ' in queue<br><a href="' + navUrl(s) + '" target="_blank" rel="noopener">Navigate</a>';
    }

    function render(list) {
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
        if (sum) sum.innerHTML = '<span class="live-dot"></span> LIVE · <b>' + openN + '</b> open of ' + rows.length + ' stations · updated ' + new Date().toLocaleTimeString();
        grid.innerHTML = rows.length ? rows.map(function (s) {
            var chips = (s.types || []).map(function (t) {
                return '<span class="stn-chip">' + (window.vehPic ? vehPic(t.icon, t.name, 22) : '') + esc(t.name) + ' <b>' + t.count + '</b></span>';
            }).join('');
            return '<div class="stn-card ' + (s.is_open ? '' : 'closed') + '">' +
                '<div class="stn-top"><div><div class="stn-name">' + esc(s.name) + '</div><div class="stn-addr">' + esc(s.address || '') + (s.open_hours ? ' · ' + esc(s.open_hours) : '') + '</div></div>' +
                '<span class="stn-pill ' + (s.is_open ? 'open' : 'closed') + '">' + (s.is_open ? 'OPEN TODAY' : 'CLOSED') + '</span></div>' +
                '<div class="stn-nums"><div class="stn-num"><b>' + s.queued + '</b><span>Cars waiting</span></div><div class="stn-num"><b>' + s.served_today + '</b><span>Served today</span></div>' +
                (s._d != null ? '<div class="stn-num"><b>' + s._d.toFixed(1) + '</b><span>km away</span></div>' : '') + '</div>' +
                (chips ? '<div class="stn-chips">' + chips + '</div>' : '') +
                '<div class="stn-actions">' + (s.lat != null ? '<a class="stn-btn" target="_blank" rel="noopener" href="' + navUrl(s) + '">🧭 Navigate</a><button class="stn-btn ghost" onclick="stnFocus(' + s.id + ')">Show on map</button>' : '<span class="stn-dist">Location not set yet</span>') + '</div></div>';
        }).join('') : '<div class="card">No stations yet.</div>';

        if (map && window.L) {
            var pts = [];
            list.forEach(function (s) {
                if (s.lat == null || s.lng == null) return;
                pts.push([s.lat, s.lng]);
                if (markers[s.id]) { markers[s.id].setIcon(pin(s.is_open)).setLatLng([s.lat, s.lng]).setPopupContent(popup(s)); }
                else markers[s.id] = L.marker([s.lat, s.lng], { icon: pin(s.is_open) }).addTo(map).bindPopup(popup(s));
            });
            if (me) pts.push([me.lat, me.lng]);
            if (!fitted && pts.length) { map.fitBounds(pts, { padding: [40, 40], maxZoom: 15 }); fitted = true; }
        }
    }

    function poll() {
        if (!document.getElementById('stnGrid')) { stop(); return; }
        fetch(API + '&action=public_stations', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
            if (d && d.success) render(d.stations || []);
        }).catch(function () {});
    }
    function stop() { if (timer) clearInterval(timer); timer = null; if (watchId != null && navigator.geolocation) navigator.geolocation.clearWatch(watchId); watchId = null; map = null; markers = {}; meMarker = null; fitted = false; }

    window.stnFocus = function (id) { var m = markers[id]; if (m && map) { map.setView(m.getLatLng(), 16); m.openPopup(); document.getElementById('stnMap').scrollIntoView({ behavior: 'smooth', block: 'center' }); } };

    window.initStationsPage = function () {
        stop();
        loadLeaflet(function (err) {
            var el = document.getElementById('stnMap');
            if (!el) return;
            if (err || !window.L) { el.innerHTML = '<div style="padding:30px;text-align:center">Map could not load. The list below is still live.</div>'; }
            else {
                map = L.map(el, { zoomControl: true }).setView([7.0621, 38.4763], 13); // Hawassa
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
            }
            poll();
        });
        timer = setInterval(poll, 10000);
        if (navigator.geolocation) {
            watchId = navigator.geolocation.watchPosition(function (p) {
                me = { lat: p.coords.latitude, lng: p.coords.longitude };
                if (map && window.L) {
                    if (meMarker) meMarker.setLatLng([me.lat, me.lng]);
                    else meMarker = L.circleMarker([me.lat, me.lng], { radius: 9, color: '#fff', weight: 3, fillColor: '#1a73e8', fillOpacity: 1 }).addTo(map).bindPopup('You are here');
                }
                render(lastData);
            }, function () {}, { enableHighAccuracy: true, maximumAge: 10000 });
        }
    };
})();
