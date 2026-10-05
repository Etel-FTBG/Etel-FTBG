/* ETEL - refresh button for every app (rider, driver, courier, customer, service giver, admin).

   Installed web apps (home-screen icons) have no browser reload button, so a stuck or out-of-date
   screen had no easy way out. This adds one small round button:
     - TAP        reloads the page (you stay signed in; the service worker is told to look for updates).
     - HOLD (0.7 s) "full update": re-downloads this page and its scripts and styles past the cache,
                  clears the offline cache and service worker, then reloads. Still signed in.
                  (Profile > "Fix app not updating" remains the heavy option that also signs you out.)
   The button picks a spot on the screen that is not on top of any other button or floating control,
   and moves if something else appears there. Set window.ETEL_NO_REFRESH_FAB = true to turn it off on a page. */
(function () {
    'use strict';
    if (window.EtelRefresh || window.ETEL_NO_REFRESH_FAB) return;
    var doc = document, SIZE = 42, btn = null, busy = false, pos = null, hold = 0, held = false;

    function css() {
        if (doc.getElementById('etel-refresh-css')) return;
        var s = doc.createElement('style'); s.id = 'etel-refresh-css';
        s.textContent = '#etel-refresh-btn{position:fixed;z-index:9300;width:' + SIZE + 'px;height:' + SIZE + 'px;border-radius:50%;border:1px solid rgba(255,255,255,.55);' +
            'background:linear-gradient(135deg,#F4D56A,#C89A24);color:#16120A;display:flex;align-items:center;justify-content:center;padding:0;cursor:pointer;opacity:.92;' +
            'box-shadow:0 3px 10px rgba(0,0,0,.4);-webkit-tap-highlight-color:transparent;touch-action:manipulation;transition:opacity .2s,transform .15s}' +
            '#etel-refresh-btn:active{transform:scale(.92)}#etel-refresh-btn svg{width:22px;height:22px;display:block}' +
            '#etel-refresh-btn.spin svg{animation:etel-refresh-spin .8s linear infinite}' +
            '#etel-refresh-btn.full{box-shadow:0 0 0 4px rgba(244,213,106,.45),0 3px 10px rgba(0,0,0,.4)}' +
            '@keyframes etel-refresh-spin{to{transform:rotate(360deg)}}@media print{#etel-refresh-btn{display:none!important}}';
        doc.head.appendChild(s);
    }

    /* ---------- finding a free spot ---------- */
    var INTERACTIVE = 'button,a,input,select,textarea,label,[role="button"],[onclick],[data-etel-2fa-open]';
    function blocked(x, y) {
        var stack = doc.elementsFromPoint ? doc.elementsFromPoint(x, y) : [];
        for (var i = 0; i < stack.length; i++) {
            var el = stack[i];
            if (el === btn || el === doc.documentElement || el === doc.body) continue;
            if (el.id === 'etel-refresh-btn') continue;
            if (el.closest && el.closest(INTERACTIVE)) return true;
            // a small floating control (round FAB, chip, badge) that is not itself a button
            var cs = null; try { cs = getComputedStyle(el); } catch (e) {}
            if (cs && (cs.position === 'fixed' || cs.position === 'sticky')) {
                var r = el.getBoundingClientRect();
                if (r.width < 190 && r.height < 190 && r.width > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && parseFloat(cs.opacity) > 0.05) return true;
            }
        }
        return false;
    }
    function spotFree(left, top) {
        var pts = [[0.5, 0.5], [0, 0], [1, 0], [0, 1], [1, 1], [0.5, 0], [0.5, 1], [0, 0.5], [1, 0.5]];
        for (var i = 0; i < pts.length; i++) {
            var x = Math.round(left + pts[i][0] * SIZE), y = Math.round(top + pts[i][1] * SIZE);
            x = Math.min(window.innerWidth - 1, Math.max(0, x)); y = Math.min(window.innerHeight - 1, Math.max(0, y));
            if (blocked(x, y)) return false;
        }
        return true;
    }
    function candidates() {
        var W = window.innerWidth, H = window.innerHeight, m = 12, out = [], f, i;
        var rightX = W - SIZE - m, leftX = m;
        // right edge first (thumb), from lower-middle upwards, then the left edge
        for (f = 0.72; f >= 0.2; f -= 0.06) out.push([rightX, Math.round(H * f)]);
        for (f = 0.72; f >= 0.2; f -= 0.06) out.push([leftX, Math.round(H * f)]);
        for (i = 0; i < out.length; i++) out[i][1] = Math.max(70, Math.min(H - SIZE - 70, out[i][1]));
        return out;
    }
    function place(force) {
        if (!btn) return;
        if (!force && pos && spotFree(pos[0], pos[1])) return; // current spot still fine: do not move
        var c = candidates(), i;
        btn.style.visibility = 'hidden';
        for (i = 0; i < c.length; i++) { if (spotFree(c[i][0], c[i][1])) { pos = c[i]; break; } }
        if (i === c.length) pos = c[0]; // nothing free: right edge, lower-middle
        btn.style.left = pos[0] + 'px'; btn.style.top = pos[1] + 'px'; btn.style.right = 'auto'; btn.style.bottom = 'auto';
        btn.style.visibility = 'visible';
    }

    /* ---------- actions ---------- */
    function swUpdate() {
        try { if (navigator.serviceWorker && navigator.serviceWorker.getRegistrations) return navigator.serviceWorker.getRegistrations().then(function (rs) { return Promise.all(rs.map(function (r) { return r.update().catch(function () {}); })); }); } catch (e) {}
        return Promise.resolve();
    }
    function sameOrigin(u) { try { return new URL(u, location.href).origin === location.origin; } catch (e) { return false; } }
    function fullUpdate() {
        var urls = [location.href];
        [].forEach.call(doc.querySelectorAll('script[src],link[rel="stylesheet"][href]'), function (n) { var u = n.src || n.href; if (u && sameOrigin(u)) urls.push(u); });
        var fetches = urls.map(function (u) { return fetch(u, { cache: 'reload', credentials: 'same-origin' }).catch(function () {}); });
        var cleanup = Promise.resolve();
        try { if (window.caches && caches.keys) cleanup = caches.keys().then(function (ks) { return Promise.all(ks.map(function (k) { return caches.delete(k); })); }); } catch (e) {}
        var sw = Promise.resolve();
        try { if (navigator.serviceWorker && navigator.serviceWorker.getRegistrations) sw = navigator.serviceWorker.getRegistrations().then(function (rs) { return Promise.all(rs.map(function (r) { return r.unregister(); })); }); } catch (e) {}
        var timeout = new Promise(function (r) { setTimeout(r, 8000); });
        return Promise.race([Promise.all(fetches.concat([cleanup, sw])), timeout]);
    }
    function run(full) {
        if (busy) return; busy = true; btn.classList.add('spin');
        try { window.dispatchEvent(new CustomEvent('etel:refresh', { detail: { full: !!full } })); } catch (e) {}
        (full ? fullUpdate() : swUpdate()).then(function () { return new Promise(function (r) { setTimeout(r, 250); }); })
            .then(function () { location.reload(); }, function () { location.reload(); });
        setTimeout(function () { busy = false; if (btn) btn.classList.remove('spin'); }, 12000); // never leave it spinning
    }

    function build() {
        if (btn || !doc.body) return;
        css();
        btn = doc.createElement('button'); btn.id = 'etel-refresh-btn'; btn.type = 'button';
        btn.setAttribute('aria-label', 'Refresh'); btn.title = 'Tap to refresh. Hold to fully update.';
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.3-4.6L4 8.5"/><path d="M4 4v4.5h4.5"/><path d="M4 13a8 8 0 0 0 14.3 4.6L20 15.5"/><path d="M20 20v-4.5h-4.5"/></svg>';
        function down() { held = false; clearTimeout(hold); hold = setTimeout(function () { held = true; btn.classList.add('full'); if (navigator.vibrate) try { navigator.vibrate(30); } catch (e) {} }, 700); }
        function up() { clearTimeout(hold); }
        btn.addEventListener('touchstart', down, { passive: true }); btn.addEventListener('mousedown', down);
        btn.addEventListener('touchend', up); btn.addEventListener('touchcancel', up); btn.addEventListener('mouseup', up); btn.addEventListener('mouseleave', up);
        btn.addEventListener('click', function (e) { e.preventDefault(); var f = held; held = false; btn.classList.remove('full'); run(f); });
        btn.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        doc.body.appendChild(btn);
        place(true);
    }
    function start() {
        build();
        // other floating controls appear a little after load (SOS, theme, display size, map controls)
        setTimeout(function () { place(true); }, 1500); setTimeout(function () { place(false); }, 4000);
        setInterval(function () { if (!doc.hidden) place(false); }, 5000);
        window.addEventListener('resize', function () { place(true); });
        window.addEventListener('orientationchange', function () { setTimeout(function () { place(true); }, 300); });
    }
    window.EtelRefresh = { refresh: function () { run(false); }, fullUpdate: function () { run(true); }, _place: place };
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start); else start();
})();
