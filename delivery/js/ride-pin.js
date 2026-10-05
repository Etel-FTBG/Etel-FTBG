/* ETEL trip start PIN - additive script for ride/index.html (rider) and driver/index.html (driver).

   Server side (api/lib/ride_pin.php) is optional and off by default. When an admin turns it on:
   - RIDER: after a driver accepts, a card shows a 4-digit PIN to give the driver when they meet.
   - DRIVER: tapping "Start ride" is answered "ask the rider for the PIN"; this script catches that
     answer, asks for the PIN in a small box, and repeats the same request with the PIN added.
   No bundle changes: it wraps window.fetch for driver_start_ride and polls ride_pin_get (rider). */
(function () {
    'use strict';
    if (window.EtelRidePin) return;
    var doc = document;
    var realFetch = window.fetch;
    if (!realFetch) return;

    function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function apiUrl() {
        var path = window.location.pathname, mk = '/delivery/', i = path.indexOf(mk);
        var base = i >= 0 ? path.substring(0, i + mk.length) : path.substring(0, path.lastIndexOf('/') + 1);
        return window.location.protocol + '//' + window.location.host + base + 'api/boot.php';
    }
    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }

    /* ---------- driver: ask for the PIN and retry ---------- */
    var host = null, shadow = null;
    var CSS = ':host{all:initial}*{box-sizing:border-box}' +
        '.bg{position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:18px;font-family:Outfit,system-ui,-apple-system,sans-serif}' +
        '.card{width:100%;max-width:360px;background:#11131C;color:#F7F5EE;border:1px solid rgba(212,175,55,.4);border-radius:20px;padding:20px 18px;box-shadow:0 20px 60px rgba(0,0,0,.6);text-align:center;line-height:1.5}' +
        'h2{margin:0 0 4px;font-size:19px;font-weight:800}p{margin:0 0 12px;color:#C4C6D0;font-size:14px}' +
        'input{width:100%;height:60px;border-radius:14px;border:1px solid rgba(212,175,55,.5);background:#0B0C12;color:#F4D56A;font:800 32px/1 Outfit,system-ui,sans-serif;letter-spacing:.4em;text-align:center;padding-left:.4em;outline:none}' +
        '.err{min-height:20px;margin:8px 0 0;color:#FF9C98;font-size:13px}' +
        '.row{display:flex;gap:8px;margin-top:12px}' +
        'button{flex:1;min-height:48px;border-radius:12px;border:1px solid rgba(212,175,55,.45);background:transparent;color:#F7F5EE;font:700 15px Outfit,system-ui,sans-serif;cursor:pointer}' +
        'button.p{border:0;background:linear-gradient(135deg,#F4D56A,#C89A24);color:#16120A;font-weight:800}';
    function askPin(message) {
        return new Promise(function (resolve) {
            if (!host) {
                host = doc.createElement('div');
                host.style.cssText = 'all:initial;position:fixed;inset:0;z-index:2147483000';
                shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
                doc.body.appendChild(host);
            }
            host.style.display = 'block';
            shadow.innerHTML = '<style>' + CSS + '</style><div class="bg"><div class="card"><h2>Trip PIN</h2>' +
                '<p>' + esc(message || 'Ask the rider for the 4-digit PIN shown on their screen.') + '</p>' +
                '<input id="pin" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="one-time-code" aria-label="Trip PIN">' +
                '<div class="err" id="err"></div><div class="row"><button id="no" type="button">Cancel</button><button class="p" id="go" type="button">Start trip</button></div></div></div>';
            var inp = shadow.getElementById('pin');
            function done(v) { host.style.display = 'none'; shadow.innerHTML = ''; resolve(v); }
            shadow.getElementById('no').onclick = function () { done(null); };
            shadow.getElementById('go').onclick = function () {
                var v = (inp.value || '').replace(/[^0-9]/g, '');
                if (v.length !== 4) { shadow.getElementById('err').textContent = 'Enter all 4 digits.'; return; }
                done(v);
            };
            inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') shadow.getElementById('go').click(); });
            setTimeout(function () { try { inp.focus(); } catch (e) {} }, 50);
        });
    }
    function bodyOf(init) { try { return init && typeof init.body === 'string' ? JSON.parse(init.body) : null; } catch (e) { return null; } }

    window.fetch = function (input, init) {
        var p = realFetch.apply(this, arguments);
        var b = bodyOf(init);
        if (!b || b.action !== 'driver_start_ride' || b.pin) return p;
        return p.then(function (r) {
            if (!r || r.status !== 403) return r;
            return r.clone().json().then(function (d) {
                if (!d || (d.code !== 'pin_required' && d.code !== 'pin_wrong')) return r;
                function attempt(msg) {
                    return askPin(msg).then(function (pin) {
                        if (pin === null) return r; // cancelled: the caller sees the original "ask the rider" message
                        var nb = {}; for (var k in b) nb[k] = b[k]; nb.pin = pin;
                        var ni = {}; for (var k2 in init) ni[k2] = init[k2]; ni.body = JSON.stringify(nb);
                        return realFetch.call(window, input, ni).then(function (r2) {
                            if (r2.status === 403) {
                                return r2.clone().json().then(function (d2) {
                                    if (d2 && d2.code === 'pin_wrong') return attempt('Wrong PIN. Check it with the rider and try again.');
                                    return r2;
                                }).catch(function () { return r2; });
                            }
                            return r2;
                        });
                    });
                }
                return attempt(d.code === 'pin_wrong' ? d.message : null);
            }).catch(function () { return r; });
        });
    };

    /* ---------- rider: show the PIN while a driver is on the way ---------- */
    var card = null, timer = 0;
    function accepted() {
        var R = window.RIDE;
        var rd = R && R.activeRideData;
        return !!(rd && rd.status === 'accepted');
    }
    function cardCss() {
        if (doc.getElementById('etel-pin-css')) return;
        var s = doc.createElement('style'); s.id = 'etel-pin-css';
        s.textContent = '#etel-pin-card{position:fixed;left:50%;transform:translateX(-50%);top:calc(env(safe-area-inset-top,0px) + 74px);z-index:9400;display:none;align-items:center;gap:12px;padding:10px 16px;border-radius:16px;' +
            'background:rgba(17,19,28,.96);border:1px solid rgba(212,175,55,.55);color:#F7F5EE;font-family:Outfit,system-ui,sans-serif;box-shadow:0 6px 18px rgba(0,0,0,.45)}' +
            '#etel-pin-card b{font-size:28px;letter-spacing:.25em;color:#F4D56A;font-weight:800}#etel-pin-card span{font-size:12px;line-height:1.3;color:#C4C6D0;max-width:150px}';
        doc.head.appendChild(s);
    }
    function showPin(pin) {
        cardCss();
        if (!card) { card = doc.createElement('div'); card.id = 'etel-pin-card'; doc.body.appendChild(card); }
        if (!pin) { card.style.display = 'none'; return; }
        card.innerHTML = '<div style="font-size:22px">&#128274;</div><b>' + esc(pin) + '</b><span>Trip PIN. Tell your driver when they arrive.</span>';
        card.style.display = 'flex';
    }
    function poll() {
        if (!window.RIDE) return;
        if (!accepted()) { if (card) card.style.display = 'none'; return; }
        realFetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify({ action: 'ride_pin_get' }) })
            .then(function (r) { return r.json(); })
            .then(function (d) { showPin(d && d.success && d.data && d.data.required ? d.data.pin : null); })
            .catch(function () {});
    }

    window.EtelRidePin = { _poll: poll };
    function start() { timer = setInterval(poll, 12000); setTimeout(poll, 3000); } // RIDE appears later (bundle loads async); poll() checks for it
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start); else start();
})();
