/* ETEL "Delete my account" panel, shared by every app (customer, rider,
   driver, courier, service giver).

   Google Play requires an in-app way to ask for account deletion plus a public
   page that explains it (delivery/delete-account.html). This panel is the
   in-app part.

   Public API:  window.EtelDeleteAccount.open() / .close()
   Any element with the attribute  data-etel-delete-account-open  opens the
   panel when clicked, so a profile menu only needs a row such as
   <div data-etel-delete-account-open>Delete my account</div>.

   Deleting is a request, not an instant wipe: it is stored as a support
   message (customer_send_message) for the ETEL team, who confirm the person's
   identity and delete the account, keeping only what the law requires (see
   delete-account.html). The panel says so plainly instead of pretending the
   account is already gone.

   The panel lives in a Shadow DOM so the host page's CSS cannot distort it. */
(function () {
    'use strict';
    if (window.EtelDeleteAccount) return;
    var doc = document, host = null, shadow = null, body = null;

    function dir() {
        var loc = window.location.pathname, mk = '/delivery/', i = loc.indexOf(mk);
        return window.location.protocol + '//' + window.location.host +
            (i >= 0 ? loc.substring(0, i + mk.length) : loc.substring(0, loc.lastIndexOf('/') + 1));
    }
    function apiUrl() {
        try { if (typeof ETEL !== 'undefined' && ETEL && ETEL.api) return ETEL.api; } catch (e) {}
        return dir() + 'api/boot.php';
    }
    function token() { try { return localStorage.getItem('etel_token') || ''; } catch (e) { return ''; } }
    function call(action, extra) {
        var payload = { action: action };
        for (var k in (extra || {})) payload[k] = extra[k];
        return fetch(apiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.text(); }).then(function (t) {
            try { return JSON.parse(t); } catch (e) { return { success: false, message: 'Server error. Try again.' }; }
        }).catch(function () { return { success: false, message: 'Cannot reach the server. Check your connection.' }; });
    }
    function appName() {
        var p = window.location.pathname;
        if (/\/driver\//.test(p)) return 'ETEL Drive (driver)';
        if (/\/courier\//.test(p)) return 'ETEL Courier';
        if (/\/ride\//.test(p)) return 'ETEL Ride (rider)';
        if (/servicegiver/.test(p)) return 'ETEL Service';
        return 'ETEL Delivery (customer)';
    }

    var CSS =
        ':host{all:initial}' +
        '*{box-sizing:border-box}' +
        '.bg{position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.72);display:flex;align-items:flex-end;justify-content:center;font-family:Outfit,system-ui,-apple-system,Segoe UI,sans-serif;-webkit-text-size-adjust:100%}' +
        '@media(min-width:560px){.bg{align-items:center}}' +
        '.card{width:100%;max-width:440px;max-height:92vh;overflow:auto;background:#11131C;color:#F7F5EE;border:1px solid rgba(212,175,55,.35);border-radius:22px 22px 0 0;padding:20px 18px calc(18px + env(safe-area-inset-bottom,0px));box-shadow:0 20px 60px rgba(0,0,0,.6);line-height:1.5}' +
        '@media(min-width:560px){.card{border-radius:22px}}' +
        '.top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px}' +
        'h2{margin:0;font-size:19px;font-weight:800}' +
        '.x{width:40px;height:40px;border-radius:50%;border:1px solid rgba(255,255,255,.18);background:transparent;color:#C4C6D0;font-size:18px;cursor:pointer}' +
        '.sub{color:#C4C6D0;font-size:14px;margin:0 0 12px}' +
        'ul{margin:0 0 12px;padding-left:20px;color:#C4C6D0;font-size:14px}li{margin-bottom:5px}' +
        '.warn{background:rgba(255,119,115,.12);color:#FF9C98;border-radius:12px;padding:10px 12px;font-size:14px;margin:10px 0}' +
        '.ok{background:rgba(53,209,124,.12);color:#35D17C;border-radius:12px;padding:10px 12px;font-size:14px;margin:10px 0}' +
        '.links{font-size:13px;color:#9A9AAE;margin:10px 0}.links a{color:#E5C76B}' +
        '.row{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}' +
        'button.b{flex:1;min-height:46px;padding:0 16px;border-radius:14px;border:1px solid rgba(212,175,55,.45);background:transparent;color:#F7F5EE;font:600 15px Outfit,system-ui,sans-serif;cursor:pointer}' +
        'button.d{border:0;background:#C62828;color:#fff;font-weight:800}' +
        'button:disabled{opacity:.5}';

    function build() {
        if (host) return;
        host = doc.createElement('div');
        host.setAttribute('data-etel-delete-account-host', '');
        host.style.display = 'none';
        shadow = host.attachShadow({ mode: 'open' });
        shadow.innerHTML = '<style>' + CSS + '</style><div class="bg"><div class="card" role="dialog" aria-modal="true" aria-label="Delete my account"><div id="b"></div></div></div>';
        body = shadow.getElementById('b');
        shadow.querySelector('.bg').addEventListener('click', function (e) { if (e.target === e.currentTarget) close(); });
        doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && host.style.display !== 'none') close(); });
        (doc.body || doc.documentElement).appendChild(host);
    }

    function render(state, msg) {
        var base = dir();
        var h = '<div class="top"><h2>Delete my account</h2><button class="x" id="x" aria-label="Close">✕</button></div>';
        if (state === 'sent') {
            h += '<div class="ok">Request received. The ETEL team will contact you within 5 business days to confirm it is you, then delete your account.</div>' +
                 '<div class="row"><button class="b" id="x2">Close</button></div>';
        } else {
            h += '<p class="sub">You are asking ETEL to permanently delete your <b>' + appName() + '</b> account.</p>' +
                 '<ul><li>We delete your profile, saved places, and sign-in details.</li>' +
                 '<li>We may keep order, trip, and payment records for as long as the law or a dispute requires, with your personal details removed where possible.</li>' +
                 '<li>You will be signed out and will not be able to use the account again.</li></ul>' +
                 (msg ? '<div class="warn">' + msg.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }) + '</div>' : '') +
                 '<div class="links"><a href="' + base + 'delete-account.html" target="_blank" rel="noopener">What happens to my data</a> · ' +
                 '<a href="' + base + 'privacy.html" target="_blank" rel="noopener">Privacy Policy</a></div>' +
                 '<div class="row"><button class="b" id="x2">Cancel</button><button class="b d" id="go">Send deletion request</button></div>';
        }
        body.innerHTML = h;
        var x = shadow.getElementById('x'), x2 = shadow.getElementById('x2'), go = shadow.getElementById('go');
        if (x) x.addEventListener('click', close);
        if (x2) x2.addEventListener('click', close);
        if (go) go.addEventListener('click', submit);
    }

    function submit() {
        var go = shadow.getElementById('go');
        if (go) { go.disabled = true; go.textContent = 'Sending…'; }
        if (!token()) { render('form', 'Please sign in first, then try again. You can also use the deletion page linked below.'); return; }
        call('customer_send_message', {
            subject: 'Account deletion request',
            body: 'I would like to permanently delete my ' + appName() + ' account and associated personal data.'
        }).then(function (res) {
            if (res && res.success) render('sent');
            else render('form', (res && res.message) || 'Could not send the request. Try again, or use the deletion page below.');
        });
    }

    function open() { build(); render('form'); host.style.display = 'block'; }
    function close() { if (host) host.style.display = 'none'; }

    doc.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-etel-delete-account-open]') : null;
        if (t) { e.preventDefault(); open(); }
    });

    window.EtelDeleteAccount = { open: open, close: close };
})();
