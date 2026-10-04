/* ETEL two-step sign-in settings panel, shared by every app (customer, rider,
   driver, courier, service giver, admin).

   Public API:  window.Etel2FASettings.open() / .close()
   Any element with the attribute  data-etel-2fa-open  opens the panel when
   clicked (same idea as data-etel-display-open), so a profile menu only needs
   a row such as  <div data-etel-2fa-open>Two-step sign-in</div>.

   The panel lives in a Shadow DOM so the host page's CSS (and the Display
   size scaler) cannot distort it. It talks to the user_2fa_* actions in
   api/controllers/AuthController.php with the signed-in token. */
(function () {
    'use strict';
    if (window.Etel2FASettings) return;
    var doc = document, host = null, shadow = null, body = null;

    function apiUrl() {
        try { if (typeof ETEL !== 'undefined' && ETEL && ETEL.api) return ETEL.api; } catch (e) {}
        var loc = window.location.pathname, mk = '/delivery/', i = loc.indexOf(mk);
        var dir = i >= 0 ? loc.substring(0, i + mk.length) : loc.substring(0, loc.lastIndexOf('/') + 1);
        return window.location.protocol + '//' + window.location.host + dir + 'api/boot.php';
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
    function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

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
        '.sub{color:#C4C6D0;font-size:14px;margin:0 0 14px}' +
        '.dim{color:#9A9AAE;font-size:13px}' +
        '.row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:12px}' +
        '.chip{display:inline-block;padding:3px 12px;border-radius:999px;font-size:13px;font-weight:700}' +
        '.on{background:rgba(53,209,124,.16);color:#35D17C}.off{background:rgba(255,119,115,.16);color:#FF7773}' +
        'button.b,a.b{min-height:46px;padding:0 18px;border-radius:14px;border:1px solid rgba(212,175,55,.45);background:transparent;color:#F7F5EE;font:600 15px Outfit,system-ui,sans-serif;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}' +
        'button.p,a.p{border:0;background:linear-gradient(135deg,#F4D56A,#C89A24);color:#16120A;font-weight:800}' +
        'button.d{color:#FF7773;border-color:rgba(255,119,115,.5)}' +
        'button:disabled{opacity:.5}' +
        'input{width:100%;min-height:52px;border-radius:14px;border:1px solid rgba(212,175,55,.5);background:#0B0C12;color:#F7F5EE;font-size:22px;letter-spacing:.25em;text-align:center}' +
        '.secret{font:700 17px/1.6 ui-monospace,Menlo,monospace;letter-spacing:.06em;word-break:break-all;background:rgba(212,175,55,.08);border:1px dashed rgba(212,175,55,.45);border-radius:12px;padding:12px;text-align:center}' +
        '.codes{display:grid;grid-template-columns:1fr 1fr;gap:8px;font:700 15px ui-monospace,Menlo,monospace;margin:12px 0}' +
        '.codes span{background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.35);border-radius:10px;padding:8px;text-align:center}' +
        '.warn{background:rgba(255,119,115,.12);color:#FF9C98;border-radius:12px;padding:10px 12px;font-size:14px;margin:10px 0}' +
        '.msg{margin:10px 0 0;font-size:14px;min-height:20px}.err{color:#FF7773}.ok{color:#35D17C}' +
        'b.step{display:block;margin:4px 0 6px}';

    function build() {
        if (host) return;
        host = doc.createElement('div');
        host.setAttribute('data-etel-2fa-host', '');
        host.style.cssText = 'all:initial;position:fixed;inset:0;z-index:2147483000;display:none';
        shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
        shadow.innerHTML = '<style>' + CSS + '</style><div class="bg" part="bg"><div class="card" role="dialog" aria-modal="true" aria-label="Two-step sign-in">' +
            '<div class="top"><h2>Two-step sign-in</h2><button class="x" aria-label="Close">&#10005;</button></div><div id="body"></div></div></div>';
        doc.body.appendChild(host);
        body = shadow.getElementById('body');
        shadow.querySelector('.x').onclick = close;
        shadow.querySelector('.bg').addEventListener('click', function (e) { if (e.target === e.currentTarget) close(); });
        doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && host.style.display !== 'none') close(); });
    }
    function $(id) { return shadow.getElementById(id); }
    function msg(text, kind) { var m = $('msg'); if (m) { m.textContent = text || ''; m.className = 'msg ' + (kind || 'err'); } }
    function ok(res) { if (res && res.success) return true; msg((res && res.message) || 'Something went wrong. Try again.'); return false; }

    function load() {
        if (!token()) { body.innerHTML = '<p class="sub">Please sign in first.</p>'; return; }
        body.innerHTML = '<p class="sub">Loading&hellip;</p>';
        call('user_2fa_status').then(function (res) {
            if (!res.success) { body.innerHTML = '<p class="msg err" id="msg">' + esc(res.message || 'Could not load.') + '</p>'; return; }
            render(res.data);
        });
    }

    function render(s) {
        if (!s.enabled) {
            body.innerHTML = '<p class="sub">Add a 6-digit code from your phone on top of your password, so a stolen password alone cannot open your account.</p>' +
                '<div class="row"><div><b>Status</b><br><span class="chip off">Off</span></div><button class="b p" id="go">Turn on</button></div>' +
                '<p class="dim" style="margin:14px 0 0">You need an authenticator app (Google Authenticator, Microsoft Authenticator, Authy or Aegis). It takes about a minute.</p><div id="msg" class="msg"></div>';
            $('go').onclick = begin;
            return;
        }
        body.innerHTML = '<div class="row" style="margin-top:0"><div><b>Status</b><br><span class="chip on">On</span> <span class="dim">' + s.backup_codes_left + ' backup codes left</span></div></div>' +
            '<p class="sub" style="margin-top:12px">To make changes, enter a current code from your app (or a backup code).</p>' +
            '<input id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="123456" aria-label="Verification code">' +
            '<div class="row"><button class="b" id="regen">New backup codes</button><button class="b d" id="off">Turn off</button></div><div id="msg" class="msg"></div>';
        $('regen').onclick = function () {
            var c = $('code').value.trim(); if (!c) { msg('Enter a code first.'); return; }
            call('user_2fa_backup_codes', { code: c }).then(function (r) { if (ok(r)) showCodes(r.data.backup_codes, false); });
        };
        $('off').onclick = function () {
            var c = $('code').value.trim(); if (!c) { msg('Enter a code first.'); return; }
            if (!window.confirm('Turn off two-step sign-in? Your account will be protected by the password only.')) return;
            call('user_2fa_disable', { code: c }).then(function (r) { if (ok(r)) load(); });
        };
    }

    function begin() {
        var b = $('go'); b.disabled = true;
        call('user_2fa_begin').then(function (r) {
            b.disabled = false;
            if (!ok(r)) return;
            var d = r.data;
            body.innerHTML = '<b class="step">Step 1. Add it to your authenticator app</b>' +
                '<p class="dim" style="margin:0 0 12px">On this phone, tap <b>Open authenticator app</b>. On a computer, choose &ldquo;Enter a setup key&rdquo; in the app and type the key.</p>' +
                '<div class="secret">' + esc(d.secret) + '</div>' +
                '<div class="row"><a class="b p" href="' + esc(d.otpauth) + '">Open authenticator app</a><button class="b" id="cp">Copy key</button></div>' +
                '<b class="step" style="margin-top:18px">Step 2. Type the 6-digit code it shows</b>' +
                '<input id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="123456" aria-label="Verification code">' +
                '<div class="row"><button class="b" id="cancel">Cancel</button><button class="b p" id="ok">Turn on</button></div><div id="msg" class="msg"></div>';
            $('cp').onclick = function () { try { navigator.clipboard.writeText(d.secret_raw); msg('Key copied.', 'ok'); } catch (e) { msg('Copy it by hand.'); } };
            $('cancel').onclick = load;
            $('ok').onclick = function () {
                var c = $('code').value.trim(); if (!c) { msg('Enter the code from the app.'); return; }
                call('user_2fa_enable', { code: c }).then(function (r2) { if (ok(r2)) showCodes(r2.data.backup_codes, true); });
            };
        });
    }

    function showCodes(codes, first) {
        body.innerHTML = '<b class="step">' + (first ? 'Two-step sign-in is on.' : 'Your new backup codes') + '</b>' +
            '<div class="warn">Save these backup codes now. Each works once if you lose your phone. They are not shown again, and any older codes stop working.</div>' +
            '<div class="codes">' + codes.map(function (c) { return '<span>' + esc(c) + '</span>'; }).join('') + '</div>' +
            '<div class="row"><button class="b" id="cp">Copy all</button><button class="b p" id="done">I saved them</button></div><div id="msg" class="msg"></div>';
        $('cp').onclick = function () { try { navigator.clipboard.writeText(codes.join('\n')); msg('Copied.', 'ok'); } catch (e) { msg('Copy them by hand.'); } };
        $('done').onclick = load;
    }

    function open() { build(); host.style.display = 'block'; load(); }
    function close() { if (host) host.style.display = 'none'; }

    window.Etel2FASettings = { open: open, close: close };
    doc.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-etel-2fa-open]') : null;
        if (t) { e.preventDefault(); open(); }
    });
})();
