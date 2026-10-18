/* ETEL Ride - light / dark theme (additive script, loaded in <head> so the right theme is set before first paint).

   The rider app was dark only, which is hard to read in strong daylight. This sets
   <html data-etel-theme="light|dark"> (css/ride-light.css does the rest), remembers the rider's choice on this
   device (localStorage key etel_ride_theme), follows the phone's own light/dark setting until the rider picks one,
   adds a sun/moon button to the header, and switches the Google map between its dark and light styles
   (EtelUI.mapStyles). Fails quietly: with no storage it just follows the phone setting. */
(function () {
    'use strict';
    if (window.__etelRideTheme) return;
    window.__etelRideTheme = true;

    var KEY = 'etel_ride_theme';
    var root = document.documentElement;

    function stored() { try { var v = localStorage.getItem(KEY); return (v === 'light' || v === 'dark') ? v : null; } catch (e) { return null; } }
    function systemLight() { try { return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches); } catch (e) { return false; } }
    function current() { return stored() || (systemLight() ? 'light' : 'dark'); }

    function applyMeta(t) {
        var m = document.querySelector('meta[name="theme-color"]');
        if (m) m.setAttribute('content', t === 'light' ? '#F6F3EC' : '#0A0B10');
    }
    function applyMap(t) {
        try {
            var r = window.RIDE;
            if (r && r.map && window.EtelUI && EtelUI.mapStyles && r.__mapTheme !== t) {
                r.map.setOptions({ styles: EtelUI.mapStyles(t === 'light') });
                r.__mapTheme = t;
            }
        } catch (e) { /* map not ready yet - the timer below retries */ }
    }
    function updateButton(t) {
        var b = document.getElementById('ride-theme-btn');
        if (!b) return;
        var light = t === 'light';
        b.setAttribute('aria-label', light ? 'Switch to dark theme' : 'Switch to light theme');
        b.setAttribute('aria-pressed', light ? 'true' : 'false');
        b.title = light ? 'Dark theme' : 'Light theme';
        b.innerHTML = (window.EtelUI && EtelUI.icon) ? EtelUI.icon(light ? 'moon' : 'sun', 20) : (light ? '☾' : '☀');
    }
    function apply() {
        var t = current();
        root.setAttribute('data-etel-theme', t);
        applyMeta(t); applyMap(t); updateButton(t);
    }

    apply(); // before first paint

    function addButton() {
        if (document.getElementById('ride-theme-btn')) return;
        var host = document.querySelector('.ride-header-user');
        if (!host) return;
        var b = document.createElement('button');
        b.type = 'button'; b.id = 'ride-theme-btn'; b.className = 'ride-theme-btn';
        b.addEventListener('click', function () {
            var next = current() === 'light' ? 'dark' : 'light';
            try { localStorage.setItem(KEY, next); } catch (e) { /* not saved: still applies for this visit */ }
            root.setAttribute('data-etel-theme', next);
            applyMeta(next); applyMap(next); updateButton(next);
        });
        host.insertBefore(b, host.firstChild);
        updateButton(current());
    }

    function start() {
        addButton();
        // icons/EtelUI and the map arrive later (release loader): keep the button icon and map style in sync.
        var tries = 0;
        var timer = setInterval(function () {
            tries++; addButton(); updateButton(current()); applyMap(current());
            if (tries > 40) clearInterval(timer);
        }, 750);
        window.addEventListener('etel:bundle-ready', function () { updateButton(current()); applyMap(current()); });
        try {
            var mq = window.matchMedia('(prefers-color-scheme: light)');
            var onChange = function () { if (!stored()) apply(); };
            if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
        } catch (e) { /* older browser: no live following */ }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
