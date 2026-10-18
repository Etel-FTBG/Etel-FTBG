/* ETEL Ride - first-time welcome and loading skeletons (additive script, no bundle release).

   1. Welcome: three short cards the first time the rider opens the app on a device (what to tap, see the price
      first, ride safely). Skip at any time; remembered per device (localStorage etel_ride_welcome_v1). Never shown
      over a live trip, and never again once skipped or finished.
   2. Skeletons: the plain "Loading..." text that lists show while their data loads becomes a few softly pulsing
      placeholder rows, so the screen looks like it is arriving rather than empty. The app's own code replaces the
      placeholder when the data comes, so nothing else changes.
   All text here is translated by ride-i18n.js (Amharic) like the rest of the app. Fails quietly. */
(function () {
    'use strict';
    if (window.__etelRideOnboarding) return;
    window.__etelRideOnboarding = true;

    var FLAG = 'etel_ride_welcome_v1';
    function seen() { try { return localStorage.getItem(FLAG) === '1'; } catch (e) { return true; } }   // no storage: do not nag
    function markSeen() { try { localStorage.setItem(FLAG, '1'); } catch (e) {} }

    /* ---------- skeletons ---------- */
    var LOADING = /^(loading(\.\.\.|…)?|በመጫን ላይ(\.\.\.|…)?)$/i;
    function skel(el) {
        if (el.getAttribute('data-etel-skel')) return;
        var t = (el.textContent || '').replace(/\s+/g, ' ').trim();
        if (!LOADING.test(t) || el.children.length) return;
        // only the small "Loading" fillers: centred blocks, not headings or the profile name
        var st = el.getAttribute('style') || '';
        if (st.indexOf('text-align:center') === -1) return;
        el.setAttribute('data-etel-skel', '1');
        el.setAttribute('role', 'status');
        el.setAttribute('aria-busy', 'true');
        el.setAttribute('aria-label', 'Loading');
        el.setAttribute('data-no-i18n', '1');
        el.textContent = '';
        el.className = (el.className ? el.className + ' ' : '') + 'etel-skel';
        for (var i = 0; i < 3; i++) { var r = document.createElement('div'); r.className = 'etel-skel-row'; el.appendChild(r); }
    }
    function scan(root) {
        if (!root || root.nodeType !== 1) return;
        if (root.matches && root.matches('div[style*="text-align:center"]')) skel(root);
        var list = root.querySelectorAll ? root.querySelectorAll('div[style*="text-align:center"]') : [];
        for (var i = 0; i < list.length; i++) skel(list[i]);
    }

    /* ---------- welcome ---------- */
    var CARDS = [
        { icon: '<path d="M12 21s-7-6.1-7-11a7 7 0 0 1 14 0c0 4.9-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
          title: 'Set your trip', body: "Tap the map or type where you are and where you're going. No GPS? Use the Quick zones." },
        { icon: '<path d="M3 12l9-9h8v8l-9 9z"/><circle cx="16" cy="8" r="1.5"/>',
          title: 'See the price first', body: 'Pick Bajaj, Moto or Car and see the fare for your trip before you book. Pay cash, wallet or Chapa.' },
        { icon: '<path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
          title: 'Ride safely', body: "Check the driver's name and plate, share your trip, and use SOS any time." }
    ];
    var host = null, idx = 0, lastFocus = null;

    function liveTrip() {
        // a trip is on screen: do not interrupt it
        var ids = ['view-searching', 'view-active', 'view-arrived', 'view-rating'];
        for (var i = 0; i < ids.length; i++) { var v = document.getElementById(ids[i]); if (v && v.style.display === 'block') return true; }
        return false;
    }
    function close(done) {
        if (done !== false) markSeen();
        if (host && host.parentNode) host.parentNode.removeChild(host);
        host = null;
        document.removeEventListener('keydown', onKey, true);
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }
    function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(); }
        else if (e.key === 'Tab' && host) {
            var f = host.querySelectorAll('button'); if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    }
    function render() {
        var c = CARDS[idx], lastCard = idx === CARDS.length - 1;
        var dots = '';
        for (var i = 0; i < CARDS.length; i++) dots += '<span class="etel-wel-dot' + (i === idx ? ' on' : '') + '"></span>';
        host.querySelector('.etel-wel-card').innerHTML =
            '<button type="button" class="etel-wel-skip" data-act="skip">Skip</button>' +
            '<div class="etel-wel-ico"><svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + c.icon + '</svg></div>' +
            '<h2 class="etel-wel-title" id="etel-wel-title">' + c.title + '</h2>' +
            '<p class="etel-wel-body">' + c.body + '</p>' +
            '<div class="etel-wel-dots" aria-hidden="true">' + dots + '</div>' +
            '<button type="button" class="etel-wel-next" data-act="' + (lastCard ? 'done' : 'next') + '">' + (lastCard ? 'Get started' : 'Next') + '</button>';
        var b = host.querySelector('.etel-wel-next'); if (b) b.focus();
    }
    function show() {
        if (host || seen() || liveTrip()) return;
        lastFocus = document.activeElement;
        host = document.createElement('div');
        host.className = 'etel-wel';
        host.setAttribute('role', 'dialog'); host.setAttribute('aria-modal', 'true'); host.setAttribute('aria-labelledby', 'etel-wel-title');
        host.innerHTML = '<div class="etel-wel-card"></div>';
        host.addEventListener('click', function (e) {
            var a = e.target && e.target.getAttribute && e.target.getAttribute('data-act');
            if (a === 'next') { idx++; render(); }
            else if (a === 'done' || a === 'skip') close();
        });
        document.body.appendChild(host);
        idx = 0; render();
        document.addEventListener('keydown', onKey, true);
    }

    function start() {
        scan(document.body);
        try {
            new MutationObserver(function (recs) {
                for (var i = 0; i < recs.length; i++) for (var j = 0; j < recs[i].addedNodes.length; j++) scan(recs[i].addedNodes[j]);
            }).observe(document.body, { childList: true, subtree: true });
        } catch (e) { /* no observer: the static placeholders above are still done */ }
        // after the app has settled and the rider is signed in on the booking screen
        setTimeout(function () {
            var signedIn = false;
            try { signedIn = !!(localStorage.getItem('etel_token')); } catch (e) {}
            if (signedIn) show();
        }, 1800);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    window.EtelRideWelcome = { show: function () { try { localStorage.removeItem(FLAG); } catch (e) {} show(); } };
})();
