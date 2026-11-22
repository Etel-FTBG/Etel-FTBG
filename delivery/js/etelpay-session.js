/* ETEL Ride / Customer - how Etel Pay checks YOU when you pay, and the profile switch to choose it (additive script, no bundle release; load it AFTER etelpay-2fa.js).

   Three choices (kept by Etel Pay, see Etel.Pay/wallet/api/ep_r5.php):
     PIN only (the default)    one full sign-in on this phone (Etel-ID password, and the code if you have one), then NEVER again: a payment asks only the wallet PIN.
     Password and PIN          the Etel-ID password is typed for each payment, no code.
     Password, code and PIN    the password and the 6-digit code are typed for each payment.
   "PIN only" works like this: the first full sign-in asks Etel Pay to remember this phone (a long random token kept in the phone, 90 days). A payment then
   swaps that token for a pay-only session (it can do nothing but pay ETEL services) and the wallet PIN is still checked for every payment.
   Profile > "Payment security" changes the choice; it needs the Etel-ID password (and the code if the account has one) because it is a security setting. */
(function () {
    'use strict';
    if (window.__etelPaySession) return;
    window.__etelPaySession = true;

    var DEV = 'etel_pay_device_v2', MODE = 'etel_pay_mode_v2', SENTINEL = '__etel_pay_device__';
    var API = (typeof ETEL_PAY_API === 'string' && ETEL_PAY_API) ? ETEL_PAY_API : 'https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php';
    function last9(p) { var d = String(p || '').replace(/\D/g, ''); return d.length > 9 ? d.slice(-9) : d; }
    function myPhone() { try { return String(JSON.parse(localStorage.getItem('etel_user') || '{}').phone || ''); } catch (e) { return ''; } }
    function me() { return last9(myPhone()); }
    function read(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
    function write(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
    function del(k) { try { localStorage.removeItem(k); } catch (e) {} }
    function device() { var d = read(DEV); return (d && d.token && d.who && d.who === me()) ? d : null; }
    function saveDevice(t) { var w = me(); if (w && /^[0-9a-f]{64}$/.test(String(t || ''))) write(DEV, { who: w, token: t }); }
    function mode() { var m = read(MODE); return (m && m.who === me() && /^(pin|password|code)$/.test(m.mode)) ? m.mode : 'pin'; }
    function saveMode(m) { var w = me(); if (w && /^(pin|password|code)$/.test(m)) write(MODE, { who: w, mode: m }); }
    function urlOf(input) { return typeof input === 'string' ? input : (input && input.url) || ''; }
    function isPay(input, act) { var u = urlOf(input); return u.indexOf('/Etel.Pay/') >= 0 && u.indexOf('action=' + act) >= 0; }
    function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
    var AM = {
        'Payment security': 'የክፍያ ደህንነት', 'How should Etel Pay check you when you pay?': 'ኢቴል ፔይ ሲከፍሉ እንዴት ያረጋግጥዎ?',
        'PIN only': 'ፒን ብቻ', 'Fastest. After one sign-in on this phone, you type only your wallet PIN.': 'ፈጣን። በዚህ ስልክ አንድ ጊዜ ከገቡ በኋላ የቦርሳ ፒንዎን ብቻ ይጽፋሉ።',
        'Password and PIN': 'የይለፍ ቃል እና ፒን', 'You type your Etel-ID password and your PIN for each payment.': 'ለእያንዳንዱ ክፍያ የኢቴል-መታወቂያ የይለፍ ቃልዎንና ፒንዎን ይጽፋሉ።',
        'Password, code and PIN': 'የይለፍ ቃል፣ ኮድ እና ፒን', 'Safest. You also type the 6-digit code for each payment.': 'በጣም አስተማማኝ። ለእያንዳንዱ ክፍያ ባለ 6 አሃዝ ኮዱንም ይጽፋሉ።',
        'Etel-ID password': 'የኢቴል-መታወቂያ የይለፍ ቃል', '6-digit code (if you have two-step sign-in)': 'ባለ 6 አሃዝ ኮድ (ባለ ሁለት ደረጃ መግቢያ ካለዎት)', 'Save': 'አስቀምጥ', 'Cancel': 'ሰርዝ',
        'Saved.': 'ተቀምጧል።', 'Type your Etel-ID password.': 'የኢቴል-መታወቂያ የይለፍ ቃልዎን ይጻፉ።', 'Could not save. Check your connection.': 'ማስቀመጥ አልተቻለም። ግንኙነትዎን ያረጋግጡ።',
        'Choose how Etel Pay checks you': 'ኢቴል ፔይ እንዴት እንደሚያረጋግጥዎ ይምረጡ', 'Now:': 'አሁን፦'
    };
    function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
    var NAME = { pin: 'PIN only', password: 'Password and PIN', code: 'Password, code and PIN' };
    window.__etelPaySessionTools = { DEV: DEV, MODE: MODE, SENTINEL: SENTINEL, device: device, mode: mode, saveDevice: saveDevice, saveMode: saveMode };

    // ---- the password box of the pay button (ride.js and customer.js): "Enter your ... PIN to pay ETB 12" ----
    var origPrompt = window.prompt;
    window.prompt = function (msg, def) {
        var m = String(msg || '');
        if (/^Enter your .{0,12}Pay PIN to pay/.test(m)) {
            var md = mode();
            if (md === 'pin' && device()) return SENTINEL;                                   // PIN only and this phone is known: no password, no code
            var amount = m.replace(/^.*to pay\s*/, '');
            msg = md === 'pin'
                ? 'Type your Etel-ID password to pay ' + amount + '. We ask this only once on this phone; after that you type only your wallet PIN.'
                : 'Type your Etel-ID password to pay ' + amount + '.';
        }
        return origPrompt.call(window, msg, def);
    };

    function json(o, s) { return new Response(JSON.stringify(o), { status: s, headers: { 'Content-Type': 'application/json' } }); }
    var origFetch = window.fetch;
    window.fetch = function (input, init) {
        try {
            if (isPay(input, 'ep_login_etelid')) {
                var body = {}; try { body = JSON.parse(init && init.body); } catch (e) {}
                if (body && body.pin === SENTINEL) {                                            // PIN only: swap the remembered phone for a pay-only session
                    var d = device();
                    if (!d) return Promise.resolve(json({ success: false, data: { message: 'Please tap Pay again and type your Etel-ID password.' } }, 401));
                    var u = urlOf(input).replace(/([?&])action=[^&]*/, '$1action=ep_login_device');
                    return origFetch.call(this, u, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'ep_login_device', device_token: d.token }) }).then(function (res) {
                        if (res.status === 401 || res.status === 403) { del(DEV); return json({ success: false, data: { message: 'Please tap Pay again and type your Etel-ID password.' } }, 401); }
                        return res;
                    });
                }
                var init2 = init;
                if (body && body.phone && body.pin) { var b2 = {}; for (var k in body) b2[k] = body[k]; b2.remember_device = '1'; init2 = {}; for (var k2 in init) init2[k2] = init[k2]; init2.body = JSON.stringify(b2); }
                return origFetch.call(this, input, init2).then(function (res) {
                    try {
                        res.clone().json().then(function (j) {
                            if (j && (j.success || j.ok) && j.data) {
                                if (j.data.pay_mode) { saveMode(j.data.pay_mode); if (j.data.pay_mode !== 'pin') del(DEV); }
                                if (j.data.device_token) saveDevice(j.data.device_token);
                            }
                        }).catch(function () {});
                    } catch (e) {}
                    return res;
                });
            }
        } catch (e) {}
        return origFetch.apply(this, arguments);
    };

    // ---- Profile > Payment security ----
    var CSS = ':host{all:initial}*{box-sizing:border-box}.bg{position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:16px;font-family:Outfit,system-ui,-apple-system,Segoe UI,sans-serif}' +
        '.card{width:100%;max-width:420px;background:#11131C;color:#F7F5EE;border:1px solid rgba(212,175,55,.35);border-radius:22px;padding:20px 18px;max-height:92vh;overflow:auto}h2{margin:0 0 6px;font-size:19px;font-weight:800}p{margin:0 0 12px;color:#C4C6D0;font-size:14px;line-height:1.5}' +
        'label.opt{display:flex;gap:12px;align-items:flex-start;padding:12px;margin:0 0 8px;border:1px solid rgba(212,175,55,.3);border-radius:14px;cursor:pointer}label.opt.on{border-color:#F2CC6B;background:rgba(212,175,55,.12)}label.opt input{width:20px;height:20px;margin-top:2px;accent-color:#D4AF37;flex:none}' +
        'label.opt b{display:block;font-size:15px}label.opt span{display:block;color:#C4C6D0;font-size:13px;line-height:1.4;margin-top:2px}label.f{display:block;margin:10px 0 4px;color:#C4C6D0;font-size:13px}' +
        'input.t{width:100%;min-height:48px;border-radius:14px;border:1px solid rgba(212,175,55,.45);background:#0b0c12;color:#F7F5EE;font:600 16px Outfit,system-ui,sans-serif;padding:0 14px}.warn{background:rgba(255,119,115,.12);color:#FF9C98;border-radius:12px;padding:10px 12px;font-size:14px;margin:0 0 10px}' +
        '.ok{background:rgba(76,175,80,.14);color:#9BE3A0;border-radius:12px;padding:10px 12px;font-size:14px;margin:0 0 10px}.row{display:flex;gap:10px;margin-top:14px}button{flex:1;min-height:46px;border-radius:14px;border:1px solid rgba(212,175,55,.45);background:transparent;color:#F7F5EE;font:600 15px Outfit,system-ui,sans-serif;cursor:pointer}button.go{border:0;background:linear-gradient(135deg,#F2CC6B,#D4AF37);color:#1a1200;font-weight:800}button:disabled{opacity:.5}';
    var OPTS = [['pin', 'PIN only', 'Fastest. After one sign-in on this phone, you type only your wallet PIN.'], ['password', 'Password and PIN', 'You type your Etel-ID password and your PIN for each payment.'], ['code', 'Password, code and PIN', 'Safest. You also type the 6-digit code for each payment.']];
    function openSettings() {
        if (document.querySelector('[data-etelpay-session-host]')) return;
        var phone = myPhone(); if (!phone) return;
        var chosen = mode(), host = document.createElement('div'); host.setAttribute('data-etelpay-session-host', '');
        var sh = host.attachShadow({ mode: 'open' });
        var h = '<style>' + CSS + '</style><div class="bg"><div class="card" role="dialog" aria-modal="true"><h2 id="t"></h2><p id="m"></p><div id="msg" class="warn" hidden></div>';
        OPTS.forEach(function (o) { h += '<label class="opt" data-m="' + o[0] + '"><input type="radio" name="m" value="' + o[0] + '"><div><b></b><span></span></div></label>'; });
        h += '<label class="f" for="pw" id="lpw"></label><input class="t" id="pw" type="password" autocomplete="current-password" maxlength="100"><label class="f" for="cd" id="lcd"></label><input class="t" id="cd" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="20">';
        h += '<div class="row"><button id="no" type="button"></button><button id="go" type="button" class="go"></button></div></div></div>';
        sh.innerHTML = h;
        var q = function (s) { return sh.querySelector(s); };
        q('#t').textContent = T('Payment security'); q('#m').textContent = T('How should Etel Pay check you when you pay?');
        q('#lpw').textContent = T('Etel-ID password'); q('#lcd').textContent = T('6-digit code (if you have two-step sign-in)'); q('#no').textContent = T('Cancel'); q('#go').textContent = T('Save');
        sh.querySelectorAll('label.opt').forEach(function (l, i) { l.querySelector('b').textContent = T(OPTS[i][1]); l.querySelector('span').textContent = T(OPTS[i][2]); });
        function paint() { sh.querySelectorAll('label.opt').forEach(function (l) { var on = l.getAttribute('data-m') === chosen; l.className = 'opt' + (on ? ' on' : ''); l.querySelector('input').checked = on; }); }
        sh.querySelectorAll('label.opt').forEach(function (l) { l.addEventListener('click', function () { chosen = l.getAttribute('data-m'); paint(); }); });
        paint();
        function done() { if (host.parentNode) host.parentNode.removeChild(host); document.removeEventListener('keydown', onKey, true); }
        function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); done(); } }
        function say(text, good) { var w = q('#msg'); w.textContent = text; w.className = good ? 'ok' : 'warn'; w.hidden = !text; }
        q('#no').addEventListener('click', done);
        q('#go').addEventListener('click', function () {
            var pw = q('#pw').value, cd = q('#cd').value.replace(/\s+/g, '');
            if (!pw) { say(T('Type your Etel-ID password.')); q('#pw').focus(); return; }
            var btn = q('#go'); btn.disabled = true; say('');
            origFetch.call(window, API + '?action=ep_pay_auth_mode', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'ep_pay_auth_mode', phone: phone, pin: pw, mode: chosen, totp_code: cd }) })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    var d = (j && j.data && typeof j.data === 'object') ? j.data : {};
                    if (j && j.success) {
                        saveMode(chosen);
                        if (chosen === 'pin') { if (d.device_token) saveDevice(d.device_token); } else del(DEV);
                        say(T('Saved.') + ' ' + T('Now:') + ' ' + T(NAME[chosen]), true);
                        setTimeout(done, 1400); return;
                    }
                    btn.disabled = false; say(d.message || T('Could not save. Check your connection.'));
                })
                .catch(function () { btn.disabled = false; say(T('Could not save. Check your connection.')); });
        });
        document.addEventListener('keydown', onKey, true);
        (document.body || document.documentElement).appendChild(host);
    }
    window.etelPaySecurityOpen = openSettings;
    document.addEventListener('click', function (e) { var t = e.target && e.target.closest ? e.target.closest('[data-etel-paysec-open]') : null; if (t) { e.preventDefault(); openSettings(); } });

    // The Customer page builds its profile panel in script: add the row once it is drawn. (The Ride page has the row in its HTML.)
    function addRow() {
        var box = document.getElementById('profile-info');
        if (!box || box.querySelector('[data-etel-paysec-open]') || !box.firstElementChild) return;
        var menu = box.querySelector('div[style*="flex-direction:column"]') || box.firstElementChild;
        var row = document.createElement('div');
        row.setAttribute('data-etel-paysec-open', ''); row.setAttribute('role', 'button'); row.setAttribute('tabindex', '0');
        row.style.cssText = 'display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;margin:6px 14px 0;border:1px solid rgba(255,215,0,.18);border-radius:14px;color:#FFF8E7;font-family:inherit';
        row.innerHTML = '<span style="font-size:20px">🔐</span><span style="flex:1"><span style="display:block;font-size:14px;font-weight:600"></span><span style="display:block;font-size:12px;opacity:.55;margin-top:2px"></span></span><span style="opacity:.5">›</span>';
        var s = row.querySelectorAll('span span'); s[0].textContent = T('Payment security'); s[1].textContent = T('Choose how Etel Pay checks you');
        row.addEventListener('keydown', function (e) { if (e.key === 'Enter') openSettings(); });
        menu.parentNode === box ? box.appendChild(row) : menu.parentNode.insertBefore(row, menu.nextSibling);
    }
    if (document.getElementById('profile-info') || document.readyState === 'loading') {
        var go = function () { var b = document.getElementById('profile-info'); if (b && window.MutationObserver) { new MutationObserver(addRow).observe(b, { childList: true }); addRow(); } };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
    }
})();
