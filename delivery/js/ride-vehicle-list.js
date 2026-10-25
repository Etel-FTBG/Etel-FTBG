/* ETEL Ride - vehicle list on the Book screen, step 2 (additive script, no bundle release).

   The vehicle chips (built by ride.js loadVehicleTypeChips, re-drawn every 20 s) only showed a name and a
   "fr ETB <base fare>" that has nothing to do with the trip, and their photos (img/vehicles/*) are not part of
   the app, so they showed as broken images. This script decorates those same chips in place:
     - a drawn vehicle icon (EtelUI.vehicleGlyph, always available)
     - seats, and "~N min" to the nearest driver of that type (api: ride_get_vehicle_types with the pickup point)
     - the price of THIS trip for each vehicle (api: ride_estimate_fare, the same engine that bills the ride)
     - "Cheapest" / "Fastest" tags when more than one vehicle is available
     - keyboard + screen-reader support (radio group)
   Selecting still goes through ride.js selectVtype(), so the fare card, the price-lock quote token and booking are
   untouched. Fails quietly: with no data the chips keep their original text. */
(function () {
    'use strict';
    if (window.__etelVehicleList) return;
    window.__etelVehicleList = true;

    var SEATS = { bajaj: 3, qute: 3, moto: 1, car: 4, ev_car: 4, minibus: 12 };
    var info = { eta: {}, rates: {}, loaded: 0 };
    var fares = {};      // vehicle type -> fare for fareKey
    var fareKey = '';
    var decorating = false;

    function apiBase() { return (window.ETEL && ETEL.api) ? ETEL.api : '/delivery/api/boot.php'; }
    function headers() {
        var t = '';
        try { t = (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) {}
        return t ? { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + t } : { 'Content-Type': 'application/json' };
    }
    function R() { return window.RIDE || null; }
    function step2() { var v = document.getElementById('view-book'); return v && v.getAttribute('data-step') === '2'; }
    function money(n) { return 'ETB ' + Math.round(parseFloat(n)); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function getJson(url) {
        return fetch(url, { headers: headers() }).then(function (r) { return r.json(); }).then(function (d) { return d && d.success ? d.data : null; }).catch(function () { return null; });
    }

    function loadInfo() {
        var r = R(), q = '';
        if (r && r.pickupLatLng && r.pickupLatLng.lat) q = '&pickup_lat=' + encodeURIComponent(r.pickupLatLng.lat) + '&pickup_lng=' + encodeURIComponent(r.pickupLatLng.lng);
        return getJson(apiBase() + '?action=ride_get_vehicle_types' + q).then(function (d) {
            if (!d) return;
            info.eta = d.eta_min || {}; info.rates = d.rates || {}; info.loaded = Date.now();
            decorate();
        });
    }

    function routeKey() {
        var r = R();
        if (!r || r.lastRouteKm === null || r.lastRouteKm === undefined) return '';
        return Number(r.lastRouteKm).toFixed(2) + '|' + (r.lastRouteIsManual ? 1 : 0);
    }
    function loadFares() {
        var key = routeKey(), r = R();
        if (!key || key === fareKey) return;
        fareKey = key; fares = {};
        var types = [].map.call(document.querySelectorAll('#vtype-row .vtype-chip'), function (c) { return c.getAttribute('data-vtype'); }).filter(Boolean);
        var sel = r.selectedVtype || 'bajaj';
        types.sort(function (a, b) { return (a === sel ? -1 : 0) - (b === sel ? -1 : 0); });
        // One at a time, the selected vehicle first: seven requests at once can trip the host's connection limit,
        // and a refused request used to leave the old guess on screen.
        (function next(i) {
            if (i >= types.length || fareKey !== key) return;
            var t = types[i];
            getJson(apiBase() + '?action=ride_estimate_fare&distance_km=' + encodeURIComponent(r.lastRouteKm) + '&vehicle_type=' + encodeURIComponent(t) + (r.lastRouteIsManual ? '&open_trip=1' : '')).then(function (d) {
                if (d && fareKey === key) { fares[t] = d.fare; decorate(); syncFareCard(); }
                next(i + 1);
            });
        })(0);
    }

    /* The big "Estimated Fare" card is first filled with a quick guess from a price table built into ride.js, and replaced
       by the server's number when that request comes back. If that request is refused or slow, the guess stays, and it
       can disagree with the vehicle rows (which always show the server's number). Keep the card equal to the selected
       vehicle's server fare. */
    function syncFareCard() {
        var r = R(); if (!r) return;
        var t = r.selectedVtype || 'bajaj';
        if (!routeKey() || routeKey() !== fareKey || fares[t] == null) return;
        var el = document.getElementById('fare-amount');
        if (!el) return;
        var want = 'ETB ' + Number(fares[t]).toFixed(2);
        if (el.textContent !== want) el.textContent = want;
    }

    /* Open meter: show the selected vehicle's real rate instead of the fixed text in the page. */
    function syncMeterRate() {
        var r = R(), el = document.getElementById('bykm-rate');
        if (!r || !el) return;
        var rt = info.rates[r.selectedVtype || 'bajaj'];
        if (rt && rt.base != null && rt.per_km_open != null) {
            var txt = 'ETB ' + Math.round(parseFloat(rt.base)) + ' + ' + (Math.round(parseFloat(rt.per_km_open) * 10) / 10) + '/km';
            if (el.textContent !== txt) el.textContent = txt;
        }
    }

    function decorate() {
        var row = document.getElementById('vtype-row');
        if (!row || decorating) return;
        var chips = row.querySelectorAll('.vtype-chip');
        if (!chips.length) return;
        decorating = true;
        try {
            var avail = [], i;
            for (i = 0; i < chips.length; i++) { if (!chips[i].classList.contains('vtype-chip-disabled')) avail.push(chips[i].getAttribute('data-vtype')); }
            var cheapest = null, fastest = null;
            if (avail.length > 1) {
                avail.forEach(function (t) {
                    if (fares[t] != null && (cheapest === null || fares[t] < fares[cheapest])) cheapest = t;
                    if (info.eta[t] != null && (fastest === null || info.eta[t] < info.eta[fastest])) fastest = t;
                });
                if (cheapest !== null && avail.every(function (t) { return fares[t] === fares[cheapest]; })) cheapest = null;
                if (fastest !== null && avail.every(function (t) { return info.eta[t] === info.eta[fastest]; })) fastest = null;
            }
            for (i = 0; i < chips.length; i++) {
                var c = chips[i], t = c.getAttribute('data-vtype');
                if (!t) continue;
                var disabled = c.classList.contains('vtype-chip-disabled');
                var nameEl = c.querySelector('.vt-name');
                var name = c.getAttribute('data-vt-name') || (nameEl ? nameEl.textContent : t);
                name = name.replace(/^Ev\b/, 'EV');
                var base = c.getAttribute('data-vt-base') || (c.querySelector('.vt-price') ? c.querySelector('.vt-price').textContent : '');
                c.setAttribute('data-vt-name', name); c.setAttribute('data-vt-base', base);
                var seats = SEATS[t] || 4;
                var eta = info.eta[t];
                var sub = disabled ? 'No drivers online' : (eta != null ? '~' + eta + ' min away' : 'Drivers available');
                sub += ' · ' + seats + (seats === 1 ? ' seat' : ' seats');
                var price = fares[t] != null ? money(fares[t]) : (base || '');
                var tag = (t === cheapest) ? '<span class="vt-tag vt-tag-cheap">Cheapest</span>' : (t === fastest ? '<span class="vt-tag vt-tag-fast">Fastest</span>' : '');
                // a real 3D picture (js/etel-vehicle-art.js, img/vehicles/*.png) when there is one for this type, else the drawn icon
                var art = (window.EtelVehicleArt && EtelVehicleArt.ready(t)) ? EtelVehicleArt.html(t, 104, 70) : '';
                var glyph = art || ((window.EtelUI && EtelUI.vehicleGlyph) ? EtelUI.vehicleGlyph(t, 30) : '');
                c.classList.toggle('vt-art', !!art);
                var sig = [t, name, sub, price, tag, disabled ? 1 : 0, art || (glyph ? 'g' : 'n')].join('~');   // the picture's own markup (it names the file), so a type that first borrowed another picture is redrawn when its own arrives
                if (c.getAttribute('data-vt-sig') !== sig) {
                    c.innerHTML = '<div class="vt-icon">' + glyph + '</div>' +
                        '<div class="vt-main"><div class="vt-name">' + esc(name) + tag + '</div><div class="vt-eta">' + esc(sub) + '</div></div>' +
                        '<div class="vt-price">' + esc(price) + '</div>';
                    c.setAttribute('data-vt-sig', sig);
                }
                c.setAttribute('role', 'radio');
                c.setAttribute('aria-checked', c.classList.contains('active') ? 'true' : 'false');
                c.setAttribute('aria-disabled', disabled ? 'true' : 'false');
                c.setAttribute('aria-label', name + ', ' + sub + (price ? ', ' + price : ''));
                c.setAttribute('tabindex', disabled ? '-1' : (c.classList.contains('active') ? '0' : '-1'));
            }
            syncMeterRate(); syncFareCard();
            row.setAttribute('role', 'radiogroup');
            row.setAttribute('aria-label', 'Choose a vehicle');
        } finally { decorating = false; }
    }

    // Keyboard: Enter / Space selects, arrows move between available vehicles.
    document.addEventListener('keydown', function (ev) {
        var c = ev.target && ev.target.closest ? ev.target.closest('#vtype-row .vtype-chip') : null;
        if (!c) return;
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); if (!c.classList.contains('vtype-chip-disabled')) c.click(); return; }
        var dir = (ev.key === 'ArrowDown' || ev.key === 'ArrowRight') ? 1 : (ev.key === 'ArrowUp' || ev.key === 'ArrowLeft') ? -1 : 0;
        if (!dir) return;
        ev.preventDefault();
        var all = [].filter.call(document.querySelectorAll('#vtype-row .vtype-chip'), function (x) { return !x.classList.contains('vtype-chip-disabled'); });
        var n = all[(all.indexOf(c) + dir + all.length) % all.length];
        if (n) { n.click(); setTimeout(function () { var again = document.querySelector('#vtype-row .vtype-chip.active'); if (again) again.focus(); }, 0); }
    });

    /* Payment options and the sheet tabs are clickable divs/buttons: give them radio / tab semantics that follow
       the same "selected" / "active" classes ride.js already toggles, and make them work from the keyboard. */
    function syncAria() {
        [].forEach.call(document.querySelectorAll('.pay-opt'), function (p) {
            p.setAttribute('aria-checked', (p.classList.contains('selected') || p.classList.contains('active')) ? 'true' : 'false');
        });
        var tabs = document.querySelector('.ride-tabs');
        if (tabs) tabs.setAttribute('role', 'tablist');
        [].forEach.call(document.querySelectorAll('.ride-tab'), function (t) {
            t.setAttribute('role', 'tab');
            t.setAttribute('aria-selected', t.classList.contains('active') ? 'true' : 'false');
        });
    }
    document.addEventListener('keydown', function (ev) {
        var p = ev.target && ev.target.closest ? ev.target.closest('.pay-opt') : null;
        if (p && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); p.click(); }
    });
    document.addEventListener('click', function () { setTimeout(syncAria, 0); }, true);

    function start() {
        syncAria();
        setInterval(syncAria, 1500);
        var row = document.getElementById('vtype-row');
        if (!row) return;
        // ride.js re-draws the chips every 20 s and on every selection: decorate each new draw.
        new MutationObserver(function () { if (!decorating) { decorate(); } }).observe(row, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
        var view = document.getElementById('view-book');
        if (view) new MutationObserver(function () { if (step2()) { loadInfo(); loadFares(); } }).observe(view, { attributes: true, attributeFilter: ['data-step'] });
        setInterval(function () { if (step2() && !document.hidden) { loadInfo(); loadFares(); } }, 20000);
        var fa = document.getElementById('fare-amount');
        if (fa) new MutationObserver(function () { syncFareCard(); }).observe(fa, { childList: true, characterData: true, subtree: true });
        loadInfo();
        decorate();
    }
    // a vehicle picture finished loading after the chips were first drawn: redraw them with it
    document.addEventListener('etel:vehicle-art', function () { decorate(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
