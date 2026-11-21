/* ETEL - two-step code for paying with Etel Pay (the "Ethiopian Wallet" button in the Ride and Customer apps).

   Etel Pay signs the person in with their EtelID before it sends money. An account that has two-step sign-in ON (every admin and staff account, and any
   member who turned it on) is answered with "enter the 6-digit code", and the payment screens had no box to type it, so such an account could never pay with
   the wallet. This small script adds the box: when Etel Pay's sign-in (ep_login_etelid) asks for the code, it shows a small dialog, sends the same sign-in
   again WITH the code (Etel Pay already accepts "totp_code"), and hands the final answer back to the app as if nothing had happened.
   - Accounts without two-step sign-in are never asked: their answer passes straight through, untouched.
   - Up to 3 tries; a wrong code shows the reason Etel Pay gave. Cancel (or Escape) gives the app the original answer, so it shows its usual message.
   - Nothing else is touched: only calls to Etel.Pay with action ep_login_etelid are watched. The code is typed here and goes only to Etel Pay.
   Loaded as its own script (ride/index.html, customer.html), so it works with whichever Ride or Customer release is live. */
(function () {
    'use strict';
    if (window.__etelPay2fa || typeof window.fetch !== 'function') return;
    window.__etelPay2fa = true;
    var realFetch = window.fetch;

    function urlOf(input) { return typeof input === 'string' ? input : (input && input.url) || ''; }
    function isLogin(input) { var u = urlOf(input); return u.indexOf('/Etel.Pay/') >= 0 && u.indexOf('ep_login_etelid') >= 0; }

    var CSS =
        ':host{all:initial}*{box-sizing:border-box}' +
        '.bg{position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:16px;font-family:Outfit,system-ui,-apple-system,Segoe UI,sans-serif}' +
        '.card{width:100%;max-width:400px;background:#11131C;color:#F7F5EE;border:1px solid rgba(212,175,55,.35);border-radius:22px;padding:20px 18px}' +
        'h2{margin:0 0 6px;font-size:19px;font-weight:800}p{margin:0 0 12px;color:#C4C6D0;font-size:14px;line-height:1.5}' +
        'input{width:100%;min-height:50px;border-radius:14px;border:1px solid rgba(212,175,55,.45);background:#0b0c12;color:#F7F5EE;font:700 22px Outfit,system-ui,sans-serif;letter-spacing:.2em;text-align:center}' +
        '.warn{background:rgba(255,119,115,.12);color:#FF9C98;border-radius:12px;padding:10px 12px;font-size:14px;margin:0 0 10px}' +
        '.row{display:flex;gap:10px;margin-top:14px}button{flex:1;min-height:46px;border-radius:14px;border:1px solid rgba(212,175,55,.45);background:transparent;color:#F7F5EE;font:600 15px Outfit,system-ui,sans-serif;cursor:pointer}' +
        'button.go{border:0;background:linear-gradient(135deg,#F2CC6B,#D4AF37);color:#1a1200;font-weight:800}button:disabled{opacity:.5}';

    /* Asks for the code. Resolves with the typed code, or '' when the person cancels. */
    function askCode(message) {
        return new Promise(function (resolve) {
            var host = document.createElement('div');
            host.setAttribute('data-etelpay-2fa-host', '');
            var shadow = host.attachShadow({ mode: 'open' });
            shadow.innerHTML = '<style>' + CSS + '</style><div class="bg"><div class="card" role="dialog" aria-modal="true" aria-labelledby="t"><h2 id="t">Two-step code</h2><p id="m"></p><div id="w" class="warn" hidden></div>' +
                '<input id="c" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="20" aria-label="Two-step code"><div class="row"><button id="no" type="button">Cancel</button><button id="go" type="button" class="go">Confirm</button></div></div></div>';
            var m = shadow.getElementById('m'), w = shadow.getElementById('w'), c = shadow.getElementById('c');
            m.textContent = 'Etel Pay needs your two-step code to continue. Open your authenticator app and type the 6-digit code (or use a backup code).';
            if (message) { w.textContent = message; w.hidden = false; }
            var done = function (v) { document.removeEventListener('keydown', onKey, true); if (host.parentNode) host.parentNode.removeChild(host); resolve(v); };
            var submit = function () { var v = c.value.replace(/\s+/g, ''); if (v) done(v); else c.focus(); };
            var onKey = function (e) { if (e.key === 'Escape') { e.preventDefault(); done(''); } };
            shadow.getElementById('no').addEventListener('click', function () { done(''); });
            shadow.getElementById('go').addEventListener('click', submit);
            c.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
            document.addEventListener('keydown', onKey, true);
            (document.body || document.documentElement).appendChild(host);
            setTimeout(function () { c.focus(); }, 30);
        });
    }

    function needsCode(res) {
        return res.clone().json().then(function (j) { return j && j.data && j.data.requires_2fa === true ? (j.data.message || '') : null; }).catch(function () { return null; });
    }

    function attempt(input, init, first, tries, lastMsg) {
        return needsCode(first).then(function (msg) {
            if (msg === null || tries >= 3) return first;                       // not asked for a code (or too many tries): hand it back as it is
            return askCode(tries === 0 ? '' : msg).then(function (code) {
                if (!code) return first;                                         // cancelled: the app shows its usual message
                var body = {};
                try { body = JSON.parse(init.body); } catch (e) { return first; }
                body.totp_code = code;
                var init2 = {}; for (var k in init) { if (Object.prototype.hasOwnProperty.call(init, k)) init2[k] = init[k]; }
                init2.body = JSON.stringify(body);
                return realFetch.call(window, input, init2).then(function (res2) { return attempt(input, init, res2, tries + 1, msg); });
            });
        });
    }

    window.fetch = function (input, init) {
        var p = realFetch.apply(this, arguments);
        if (!isLogin(input) || !init || typeof init.body !== 'string') return p;
        return p.then(function (res) { return attempt(input, init, res, 0, ''); });
    };
})();
