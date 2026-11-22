/* ETEL Ride / Customer - pay with Etel Pay by typing only the wallet PIN (additive script, no bundle release; load it AFTER etelpay-2fa.js).

   The pay button used to ask twice: first the Etel-ID password (the box was wrongly called "PIN"), then the wallet PIN. The password only
   opens an Etel Pay session (it lasts 7 days on the server); the wallet PIN is what really allows the payment. So:
     - the first payment on a phone asks the Etel-ID password once (the box now says so), then this script keeps the session in the phone;
     - every payment after that skips the password box and the sign-in call, and only the wallet PIN box shows.
   Nothing is weakened: ep_send still needs the wallet PIN every time. If Etel Pay says the saved session is no longer good (401), it is thrown
   away and the next payment asks for the password again. A different phone number on this phone also drops it. */
(function () {
    'use strict';
    if (window.__etelPaySession) return;
    window.__etelPaySession = true;

    var KEY = 'etel_pay_session_v1', LIFE = 6 * 24 * 3600 * 1000, SENTINEL = '__etel_saved_session__';
    function last9(p) { var d = String(p || '').replace(/\D/g, ''); return d.length > 9 ? d.slice(-9) : d; }
    function me() { try { return last9(JSON.parse(localStorage.getItem('etel_user') || '{}').phone); } catch (e) { return ''; } }
    function saved() {
        try {
            var s = JSON.parse(localStorage.getItem(KEY) || 'null');
            if (s && s.token && s.who && s.who === me() && s.exp > Date.now()) return s;
        } catch (e) {}
        return null;
    }
    function save(token) { try { var w = me(); if (w && token) localStorage.setItem(KEY, JSON.stringify({ who: w, token: String(token), exp: Date.now() + LIFE })); } catch (e) {} }
    function drop() { try { localStorage.removeItem(KEY); } catch (e) {} }
    function urlOf(input) { return typeof input === 'string' ? input : (input && input.url) || ''; }
    function isPay(input, act) { var u = urlOf(input); return u.indexOf('/Etel.Pay/') >= 0 && u.indexOf('action=' + act) >= 0; }
    window.__etelPaySessionTools = { KEY: KEY, SENTINEL: SENTINEL, save: save, drop: drop, saved: saved };

    // the password box of the pay button (ride.js and customer.js): "Enter your ... PIN to pay ETB 12"
    var origPrompt = window.prompt;
    window.prompt = function (msg, def) {
        var m = String(msg || '');
        if (/^Enter your .{0,12}Pay PIN to pay/.test(m)) {
            if (saved()) return SENTINEL;                                                     // already signed in: no password, only the wallet PIN follows
            var amount = m.replace(/^.*to pay\s*/, '');
            msg = 'Type your Etel-ID password to pay ' + amount + '. We ask this only once on this phone; after that you type only your wallet PIN.';
        }
        return origPrompt.call(window, msg, def);
    };

    var origFetch = window.fetch;
    window.fetch = function (input, init) {
        try {
            if (isPay(input, 'ep_login_etelid')) {
                var body = {}; try { body = JSON.parse(init && init.body); } catch (e) {}
                var s = saved();
                if (body && body.pin === SENTINEL) {
                    if (s) return Promise.resolve(new Response(JSON.stringify({ success: true, ok: true, data: { token: s.token, via: 'saved_session' } }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
                    return Promise.resolve(new Response(JSON.stringify({ success: false, data: { message: 'Please tap Pay again and type your Etel-ID password.' } }), { status: 401, headers: { 'Content-Type': 'application/json' } }));
                }
                return origFetch.apply(this, arguments).then(function (res) {
                    try { res.clone().json().then(function (j) { if (j && (j.success || j.ok) && j.data && j.data.token) save(j.data.token); }).catch(function () {}); } catch (e) {}
                    return res;
                });
            }
            if (urlOf(input).indexOf('/Etel.Pay/') >= 0 && init && init.headers) {
                var a = init.headers.Authorization || init.headers.authorization, s2 = saved();
                if (a && s2 && a === 'Bearer ' + s2.token) {
                    return origFetch.apply(this, arguments).then(function (res) { if (res.status === 401) drop(); return res; });
                }
            }
        } catch (e) {}
        return origFetch.apply(this, arguments);
    };
})();
