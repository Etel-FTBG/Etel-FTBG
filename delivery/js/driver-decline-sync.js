/* ETEL Driver - tell the server when a request is declined (additive script, same idea as driver-demand.js).

   Tapping Decline on a request card used to only hide that card on the driver's own phone, so the same
   request came back on the next refresh and kept ringing. This listens for the Decline button and sends
   driver_decline_ride, which makes the server stop listing that ride for this driver (other drivers still
   see it). It does not depend on when driver.js finishes loading: it watches clicks on the page.
   Fails quietly - if the call does not go through, the card is still hidden locally as before. */
(function () {
    'use strict';
    if (window.__etelDeclineSync) return;
    window.__etelDeclineSync = true;

    function apiUrl() {
        var path = window.location.pathname, mk = '/delivery/', i = path.indexOf(mk);
        var base = i >= 0 ? path.substring(0, i + mk.length) : path.substring(0, path.lastIndexOf('/') + 1);
        return window.location.protocol + '//' + window.location.host + base + 'api/boot.php';
    }
    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }

    document.addEventListener('click', function (ev) {
        var el = ev.target && ev.target.closest ? ev.target.closest('[onclick^="declineRequest("]') : null;
        if (!el) return;
        var m = /declineRequest\(\s*['"]?(\d+)['"]?\s*\)/.exec(el.getAttribute('onclick') || '');
        if (!m) return;
        try {
            fetch(apiUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() },
                body: JSON.stringify({ action: 'driver_decline_ride', request_id: parseInt(m[1], 10) }),
                keepalive: true
            }).catch(function () {});
        } catch (e) { /* best effort */ }
    }, true);
})();
