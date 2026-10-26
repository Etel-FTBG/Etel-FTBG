/* ETEL Ride - real 3D vehicle pictures (additive script, no bundle release).

   Looks for one picture per vehicle type in img/vehicles/ (bajaj, qute, moto, car, ev_car, minibus .png) and, for each type that is
   really there:
     - EtelVehicleArt.html(type, px)    an <img> for the vehicle list on the Book screen (ride-vehicle-list.js uses it)
     - EtelUI.vehicleMarker(type, opts) is wrapped so every map marker (nearby drivers, the driver on the way, the driver's own marker)
                                        shows the picture instead of the drawn badge
   A type with no picture (or one that fails to load) keeps exactly what the app showed before, so adding or removing a file can never
   leave an empty icon. ev_car uses car.png when it has no picture of its own.

   Pictures: transparent PNG, 3/4 front view, the vehicle facing RIGHT, centred, about 448 x 448 (see img/vehicles/README.md).
   Optional: <type>_left.png, the same vehicle facing LEFT. On the map a vehicle that moves west uses it; without one the right-facing
   picture is mirrored, so it always faces the way it travels. Nothing here uses inline handlers. */
(function () {
    'use strict';
    if (window.EtelVehicleArt) return;

    var TYPES = ['bajaj', 'qute', 'moto', 'car', 'ev_car', 'minibus'];
    var src = (document.currentScript && document.currentScript.src) || '';
    var BASE = src ? src.split('?')[0].replace(/js\/[^\/]*$/, 'img/vehicles/') : '../img/vehicles/';
    var ok = {};        // type -> loaded Image (facing right)
    var IMG_V = '2';    // change when the picture files change, so phones do not keep showing the old ones
    var okL = {};       // type -> loaded Image (facing left, optional)
    var cache = {};     // "type|size|flip" -> icon
    var byUrl = {};     // icon url -> { type, size, flip } (so a marker's icon can be recognised again)

    /* the same loose matching the app already uses for vehicle names; null = not one of ours */
    function norm(t) {
        t = String(t == null ? '' : t).toLowerCase();
        if (!t) return null;
        if (TYPES.indexOf(t) !== -1) return t;
        if (t.indexOf('qute') !== -1) return 'qute';
        if (t.indexOf('moto') !== -1 || t.indexOf('bike') !== -1 || t.indexOf('scoot') !== -1) return 'moto';
        if (t.indexOf('mini') !== -1 || t.indexOf('van') !== -1 || t.indexOf('bus') !== -1) return 'minibus';
        if (t.indexOf('ev') === 0 || t.indexOf('electric') !== -1) return 'ev_car';
        if (t.indexOf('car') !== -1 || t.indexOf('sedan') !== -1) return 'car';
        if (t.indexOf('bajaj') !== -1 || t.indexOf('tuk') !== -1 || t.indexOf('rickshaw') !== -1) return 'bajaj';
        return null;
    }
    function pick(type) {
        var n = norm(type);
        if (!n) return null;
        if (ok[n]) return n;
        return (n === 'ev_car' && ok.car) ? 'car' : null;
    }
    /* the picture to draw: the left-facing one when asked for and there is one, else the right-facing one (mirrored when left was asked for) */
    function pickImage(type, left) {
        var n = pick(type); if (!n) return null;
        if (left && okL[n]) return { img: okL[n], mirror: false };
        return { img: ok[n], mirror: !!left };
    }
    function ready(type) { return !!pick(type); }
    function url(type) { var n = pick(type); return n ? ok[n].src : null; }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function html(type, w, h) {
        var n = pick(type); if (!n) return '';
        return '<img class="etel-vimg" src="' + esc(ok[n].src) + '" alt="" width="' + (w || 64) + '" height="' + (h || Math.round((w || 64) * 0.75)) + '" decoding="async" draggable="false">';
    }

    /* A Google Maps icon from the picture: drawn on a canvas (twice the size, for sharp screens) with a soft shadow, mirrored if asked. */
    function marker(type, opts) {
        var n = pick(type); if (!n || !window.google || !google.maps) return null;
        opts = opts || {};
        if (opts.flip) wantLeft();
        var size = Math.max(32, Math.round(opts.size || 60)), flip = !!opts.flip, key = n + '|' + size + '|' + (flip ? 1 : 0);
        if (cache[key]) return cache[key];
        try {
            var pk = pickImage(n, flip), img = pk.img, S = size * 2, c = document.createElement('canvas'); c.width = S; c.height = S;
            var g = c.getContext('2d'), pad = S * 0.08, box = S - pad * 2, k = Math.min(box / img.naturalWidth, box / img.naturalHeight);
            var w = img.naturalWidth * k, h = img.naturalHeight * k;
            g.shadowColor = 'rgba(0,0,0,.45)'; g.shadowBlur = S * 0.05; g.shadowOffsetY = S * 0.02;
            if (pk.mirror) { g.translate(S, 0); g.scale(-1, 1); }
            g.drawImage(img, (S - w) / 2, (S - h) / 2, w, h);
            var u = c.toDataURL('image/png');
            byUrl[u] = { type: n, size: size, flip: flip };
            return (cache[key] = { url: u, scaledSize: new google.maps.Size(size, size), anchor: new google.maps.Point(size / 2, size / 2) });
        } catch (e) { return null; }   // a canvas that cannot be read: the drawn badge is used instead
    }

    /* ---- hooks, set up as soon as the things they wrap exist (the app's bundle and Google Maps load after this file) ---- */
    function wrapMarkerFactory() {
        var U = window.EtelUI;
        if (!U || typeof U.vehicleMarker !== 'function' || U.vehicleMarker.__art) return !!(U && U.vehicleMarker && U.vehicleMarker.__art);
        var orig = U.vehicleMarker;
        U.vehicleMarker = function (type, opts) {
            var o = opts || {}, m = ready(type) ? marker(type, { size: Math.round((o.size || 44) * 1.35), flip: !!o.flip }) : null;
            return m || orig.apply(this, arguments);
        };
        U.vehicleMarker.__art = true;
        return true;
    }
    /* Mirror the picture when the marker travels west, so the vehicle looks where it is going. */
    function wrapMapsMarker() {
        if (!window.google || !google.maps || !google.maps.Marker) return false;
        var P = google.maps.Marker.prototype;
        if (P.__etelArt) return true;
        var orig = P.setPosition;
        P.setPosition = function (pos) {
            try {
                var ic = this.getIcon && this.getIcon(), meta = ic && ic.url && byUrl[ic.url], old = this.getPosition && this.getPosition();
                if (meta && old && pos) {
                    var lng = typeof pos.lng === 'function' ? pos.lng() : pos.lng, dx = lng - old.lng();
                    if (Math.abs(dx) > 0.00002 && (dx < 0) !== meta.flip) { var m = marker(meta.type, { size: meta.size, flip: dx < 0 }); if (m) this.setIcon(m); }
                }
            } catch (e) {}
            return orig.apply(this, arguments);
        };
        P.__etelArt = true;
        return true;
    }
    /* The driver app puts its own marker on the map as soon as it has the driver's position, which can be before these pictures have
       loaded (or before the wrap above is in place). That marker was then left as the drawn icon - for a Qute, the blue car - until the
       next time the app happened to rebuild it. So whenever the pictures become usable, ask the app to rebuild its marker now. */
    function refreshDriverMarker() {
        try { if (typeof window.refreshSelfMarkerIcon === 'function') window.refreshSelfMarkerIcon(); } catch (e) {}
    }
    var tries = 0, wrapped = false;
    (function hook() {
        var a = wrapMarkerFactory(), b = wrapMapsMarker();
        if (a && !wrapped) { wrapped = true; refreshDriverMarker(); }
        if ((!a || !b) && ++tries < 240) setTimeout(hook, 500);
    })();

    /* ---- the pictures themselves ---- */
    /* The right-facing pictures load once the page itself has finished (they are the ones the list and the map need). The left-facing ones are
       bigger in total than they are useful, so they are fetched only when a vehicle first moves west; until they arrive it is mirrored. */
    var leftStarted = false;
    function load(t, left) {
        var im = new Image(), reg = left ? okL : ok;
        im.onload = function () {
            if (!im.naturalWidth) return;
            reg[t] = im;
            if (!left) refreshDriverMarker();
            if (left) { Object.keys(cache).forEach(function (k) { if (/\|1$/.test(k)) delete cache[k]; }); }   // mirrored icons made meanwhile are replaced next time
            try { document.dispatchEvent(new CustomEvent('etel:vehicle-art', { detail: { type: t, left: left } })); } catch (e) {}
        };
        im.onerror = function () { delete reg[t]; };
        im.src = BASE + t + (left ? '_left' : '') + '.png?v=' + IMG_V;
    }
    function probe() { TYPES.forEach(function (t) { load(t, false); }); }
    function wantLeft() { if (leftStarted) return; leftStarted = true; TYPES.forEach(function (t) { if (ok[t]) load(t, true); }); }
    function css() {
        if (document.getElementById('etel-vart-css')) return;
        var s = document.createElement('style'); s.id = 'etel-vart-css';
        s.textContent = '.etel-vimg{display:block;width:100%;height:100%;object-fit:contain;pointer-events:none;filter:drop-shadow(0 4px 6px rgba(0,0,0,.35))}' +
            '#view-book[data-step="2"] .vtype-chip.vt-art{grid-template-columns:104px minmax(0,1fr) auto}' +
            '#view-book[data-step="2"] .vtype-chip.vt-art .vt-icon,#view-book[data-step="2"] .vtype-chip.vt-art.active .vt-icon{width:104px;height:70px;border:0!important;background:none!important;border-radius:0}' +
            '#view-book[data-step="2"] .vtype-chip.vt-art.vtype-chip-disabled .vt-icon{filter:none}' +   // a type with no driver online stays in colour: the dimmed row and "No drivers online" already say it
            
            '.vtype-chip.vt-art .vt-icon{width:64px;height:48px}';
        (document.head || document.documentElement).appendChild(s);
    }
    css();
    if (document.readyState === 'complete') setTimeout(probe, 300);
    else window.addEventListener('load', function () { setTimeout(probe, 300); });

    window.EtelVehicleArt = { ready: ready, url: url, html: html, marker: marker, types: TYPES.slice() };
})();
