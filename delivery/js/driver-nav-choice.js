/* ETEL Driver - choose the navigation app (additive script, no bundle release).
   The "Open Maps" button on the navigation strip used to open Google Maps only. This script lets the driver pick Google
   Maps, Waze, or the phone's own map app (Android: any app that handles geo: links; iPhone: Apple Maps) and remembers the
   choice, so the next tap goes straight to it. A small button next to "Open Maps" changes the app later.
   The destination is the trip's pickup (driver on the way) or drop-off (trip started), read from the active trip.
   Text is translated by driver-i18n.js (Amharic). Fails quietly: with no known destination the original button is used. */
(function () {
    'use strict';
    if (window.__etelNavChoice) return;
    window.__etelNavChoice = true;

    var KEY = 'etel_nav_app';
    var orig = null;
    var ios = /iPad|iPhone|iPod/.test(navigator.userAgent || '') || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    function saved() { try { return localStorage.getItem(KEY) || ''; } catch (e) { return ''; } }
    function save(v) { try { localStorage.setItem(KEY, v); } catch (e) { /* private mode: ask next time */ } }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    // where the driver is heading now, from the active trip
    function dest() {
        try {
            var r = window.DRV && DRV.activeRide; if (!r) return null;
            var toDrop = r.status === 'in_progress';
            var lat = parseFloat(toDrop ? (r.dropoff_lat || r.to_lat) : (r.pickup_lat || r.from_lat));
            var lng = parseFloat(toDrop ? (r.dropoff_lng || r.to_lng) : (r.pickup_lng || r.from_lng));
            if (!lat || !lng) return null;
            return { lat: lat, lng: lng, label: toDrop ? 'Drop-off' : 'Pickup' };
        } catch (e) { return null; }
    }
    function urlFor(app, d) {
        var ll = d.lat + ',' + d.lng;
        if (app === 'waze') return 'https://waze.com/ul?ll=' + ll + '&navigate=yes';
        if (app === 'apple') return 'maps://?daddr=' + ll + '&dirflg=d';
        if (app === 'geo') return 'geo:' + ll + '?q=' + ll + '(' + encodeURIComponent(d.label) + ')';
        return 'https://www.google.com/maps/dir/?api=1&destination=' + ll + '&travelmode=driving';
    }
    function launch(app, d) {
        var u = urlFor(app, d);
        if (app === 'google') window.open(u, '_blank'); else window.location.href = u;
    }
    function choices() {
        var c = [{ id: 'google', name: 'Google Maps' }, { id: 'waze', name: 'Waze' }];
        c.push(ios ? { id: 'apple', name: 'Apple Maps' } : { id: 'geo', name: 'Other map app' });
        return c;
    }
    function nameOf(id) { var c = choices(); for (var i = 0; i < c.length; i++) if (c[i].id === id) return c[i].name; return ''; }

    function closeSheet() { var o = document.getElementById('dnc-dlg'); if (o) o.parentNode.removeChild(o); }
    function sheet(d) {
        closeSheet();
        var o = document.createElement('div'); o.id = 'dnc-dlg'; o.className = 'rp-dlg';
        o.setAttribute('role', 'dialog'); o.setAttribute('aria-modal', 'true'); o.setAttribute('aria-label', 'Navigate with');
        o.onclick = function (e) { if (e.target === o) closeSheet(); };
        var cur = saved();
        o.innerHTML = '<div class="rp-card"><button type="button" class="rp-close" id="dnc-close" aria-label="Close">&times;</button>' +
            '<h3 class="rp-h">Navigate with</h3><div class="dnc-list">' +
            choices().map(function (c) { return '<button type="button" class="dnc-app' + (c.id === cur ? ' on' : '') + '" data-app="' + c.id + '" data-no-i18n>' + esc(c.name) + '</button>'; }).join('') +
            '</div><label class="dnc-keep"><input type="checkbox" id="dnc-keep" checked> <span>Always use this app</span></label></div>';
        document.body.appendChild(o);
        document.getElementById('dnc-close').onclick = closeSheet;
        var btns = o.querySelectorAll('.dnc-app');
        for (var i = 0; i < btns.length; i++) btns[i].onclick = function () {
            var app = this.getAttribute('data-app');
            if (document.getElementById('dnc-keep').checked) save(app);
            closeSheet();
            if (d) launch(app, d);
        };
    }

    function open() {
        var d = dest();
        if (!d) { if (typeof orig === 'function') return orig.apply(this, arguments); return; }
        var app = saved();
        if (app) { launch(app, d); return; }
        // the app also calls this by itself when a trip is accepted: only ask when the driver tapped
        var tapped = !navigator.userActivation || navigator.userActivation.isActive;
        if (tapped) sheet(d);
    }

    // the navigation script assigns window.dnOpenMaps when the app release loads: keep theirs as the fallback, use ours
    try {
        Object.defineProperty(window, 'dnOpenMaps', {
            configurable: true,
            get: function () { return open; },
            set: function (fn) { if (typeof fn === 'function' && fn !== open) orig = fn; }
        });
        if (typeof window.dnOpenMaps === 'function' && window.dnOpenMaps !== open) orig = window.dnOpenMaps;
    } catch (e) { /* keep the original button */ }

    // a small "choose app" button next to Open Maps
    function addChange() {
        var b = document.getElementById('dn-open');
        if (!b || document.getElementById('dnc-change')) return;
        var c = document.createElement('button');
        c.type = 'button'; c.id = 'dnc-change'; c.className = 'dnc-change';
        c.setAttribute('aria-label', 'Choose navigation app'); c.innerHTML = '&#9662;';
        c.onclick = function (e) { e.stopPropagation(); sheet(dest()); };
        b.parentNode.insertBefore(c, b.nextSibling);
        var sync = function () { c.style.display = b.style.display === 'none' ? 'none' : 'flex'; };
        sync();
        try { new MutationObserver(sync).observe(b, { attributes: true, attributeFilter: ['style'] }); } catch (x) {}
    }
    try {
        new MutationObserver(addChange).observe(document.documentElement, { childList: true, subtree: true });
    } catch (e) {}
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addChange); else addChange();
})();
