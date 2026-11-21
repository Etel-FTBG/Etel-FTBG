/* ETEL - the two things Etel Pay asks for before it sends money, in the Ride and Customer apps ("Ethiopian Wallet" button).

   1. The TWO-STEP CODE. Etel Pay signs the person in with their EtelID before it sends money. An account that has two-step sign-in ON (every admin and
      staff account, and any member who turned it on) is answered with "enter the 6-digit code" at sign-in (ep_login_etelid), and the payment screens had no
      box to type it. This script shows the box, sends the same sign-in again WITH the code (Etel Pay already accepts "totp_code") and hands the final
      answer back to the app as if nothing had happened.
   2. The WALLET PIN. Every money action of Etel Pay also needs the 4-digit wallet PIN (field "pin"), which the payment screens never ask for, so after
      the sign-in the payment ended with "Enter your PIN to continue." Now, when Etel Pay answers pin_required or pin_wrong, a PIN box is shown and the
      same payment is sent again WITH the PIN. When the wallet has no PIN yet (pin_not_set) a "Create your Etel Pay PIN" box takes the Etel-ID password and
      the new PIN twice (and the two-step code when it is asked for), saves it with ep_set_wallet_pin, and then sends the payment.
   2b. THE CHOICE. Whether Etel Pay asks for the 6-digit code when a person pays is the person's own decision (EtelID: service/pay_2fa_mode, kept per
      member, OFF by default). In the code box a tick "Don't ask me for this code when I pay" saves it (needs the code just typed) and signs in without it;
      in the PIN box "Payment security" lets the person switch the code back on (password only) or off again (password and a code). The wallet PIN is
      asked for every payment either way. Other apps and the EtelID portal keep asking for the code.
   3. The WALLET SWITCH. A ride is paid by sending the money to Etel Pay FIRST and only then confirming it with ETEL Delivery, which refuses it while paying
      rides from the wallet is switched off (pricing_rules ride_wallet_enabled), which left the rider charged and the ride unpaid. So before a ride payment
      (ep_send with the note "ETEL Ride #...") this script asks ETEL Delivery (ride_wallet_status); if the answer is no, or cannot be had, the money is
      not sent and the app shows the message instead. Nothing else is checked.
   - Accounts that are not asked anything are never touched: their answer passes straight through.
   - Up to 3 tries per box; a wrong code or PIN shows the reason Etel Pay gave. Cancel (or Escape) gives the app the original answer, so it shows its usual
     message. "Too many wrong PINs" (pin_locked) is left to the app: it shows Etel Pay's own message.
   - Only calls to Etel.Pay are watched. The code, PIN and password are typed here, are kept only until the call is sent, and go only to Etel Pay.
   - English and Amharic (the same language setting as every ETEL app, localStorage "etel_lang").
   Loaded as its own script (ride/index.html, customer.html), so it works with whichever Ride or Customer release is live. */
(function () {
    'use strict';
    if (window.__etelPay2fa || typeof window.fetch !== 'function') return;
    window.__etelPay2fa = true;
    var realFetch = window.fetch;
    var MAX_TRIES = 3;

    var AM = {
        'Two-step code': 'ባለ ሁለት ደረጃ ኮድ', 'Cancel': 'ተው', 'Confirm': 'አረጋግጥ', 'Two-step code field': 'ባለ ሁለት ደረጃ ኮድ',
        'Etel Pay needs your two-step code to continue. Open your authenticator app and type the 6-digit code (or use a backup code).': 'ኢቴል ፔይ ለመቀጠል ባለ ሁለት ደረጃ ኮድዎን ይፈልጋል። የማረጋገጫ መተግበሪያዎን ከፍተው ባለ 6 አሃዝ ኮዱን ይጻፉ (ወይም የመጠባበቂያ ኮድ ይጠቀሙ)።',
        'Wallet payment is coming soon. Please pay with cash or Chapa.': 'የቦርሳ ክፍያ በቅርቡ ይመጣል። እባክዎ በጥሬ ገንዘብ ወይም በቻፓ ይክፈሉ።', 'Could not check wallet payment. Please try again, or pay cash.': 'የቦርሳ ክፍያን መፈተሽ አልተቻለም። እባክዎ እንደገና ይሞክሩ ወይም በጥሬ ገንዘብ ይክፈሉ።',
        'Do not ask me for this code when I pay with Etel Pay. My PIN protects each payment.': 'በኢቴል ፔይ ስከፍል ይህን ኮድ አትጠይቁኝ። ፒኔ እያንዳንዱን ክፍያ ይጠብቃል።',
        'Payment security': 'የክፍያ ደህንነት', 'How should Etel Pay check you when you pay? Your PIN is always asked.': 'ኢቴል ፔይ ሲከፍሉ እንዴት ያረጋግጥዎ? ፒንዎ ሁልጊዜ ይጠየቃል።',
        'PIN only (fast)': 'ፒን ብቻ (ፈጣን)', 'PIN and 6-digit code (safer)': 'ፒንና ባለ 6 አሃዝ ኮድ (ይበልጥ አስተማማኝ)', '6-digit code (needed for "PIN only")': 'ባለ 6 አሃዝ ኮድ ("ፒን ብቻ" ለመምረጥ ያስፈልጋል)', 'Back': 'ተመለስ',
        'Saved. Etel Pay will ask for your PIN only.': 'ተቀምጧል። ኢቴል ፔይ ፒንዎን ብቻ ይጠይቃል።', 'Saved. Etel Pay will ask for the 6-digit code too.': 'ተቀምጧል። ኢቴል ፔይ ባለ 6 አሃዝ ኮዱንም ይጠይቃል።',
        'You have no two-step sign-in, so there is no code to ask.': 'ባለ ሁለት ደረጃ መግቢያ ስለሌለዎት የሚጠየቅ ኮድ የለም።', 'Could not find your phone number.': 'ስልክ ቁጥርዎን ማግኘት አልተቻለም።',
        'Wallet PIN': 'የቦርሳ ፒን', 'Type your 4-digit Etel Pay PIN to send the payment.': 'ክፍያውን ለመላክ ባለ 4 አሃዝ የኢቴል ፔይ ፒንዎን ይጻፉ።', 'Wallet PIN field': 'የቦርሳ ፒን',
        'I have no PIN yet': 'ፒን የለኝም', 'Create your Etel Pay PIN': 'የኢቴል ፔይ ፒንዎን ይፍጠሩ', 'Your wallet has no PIN yet. Type your Etel-ID password and choose a 4-digit PIN. You will use it for every payment.': 'የቦርሳዎ ፒን ገና አልተፈጠረም። የኢቴል-መታወቂያ የይለፍ ቃልዎን ይጻፉና ባለ 4 አሃዝ ፒን ይምረጡ። ለእያንዳንዱ ክፍያ ይጠቀሙበታል።',
        'Etel-ID password': 'የኢቴል-መታወቂያ የይለፍ ቃል', 'New PIN (4 digits)': 'አዲስ ፒን (4 አሃዝ)', 'Repeat the PIN': 'ፒኑን ድገሙ', 'Save PIN and pay': 'ፒን አስቀምጥና ክፈል', 'Continue': 'ቀጥል',
        'The PIN must be 4 digits and both PINs must match.': 'ፒኑ 4 አሃዝ መሆን አለበት፣ ሁለቱም ፒኖች አንድ መሆን አለባቸው።', 'Type your Etel-ID password.': 'የኢቴል-መታወቂያ የይለፍ ቃልዎን ይጻፉ።'
    };
    function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
    function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }

    function urlOf(input) { return typeof input === 'string' ? input : (input && input.url) || ''; }
    function isEtelPay(input) { return urlOf(input).indexOf('/Etel.Pay/') >= 0; }
    function isLogin(input) { return isEtelPay(input) && urlOf(input).indexOf('ep_login_etelid') >= 0; }

    var CSS =
        ':host{all:initial}*{box-sizing:border-box}' +
        '.bg{position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:16px;font-family:Outfit,system-ui,-apple-system,Segoe UI,sans-serif}' +
        '.card{width:100%;max-width:400px;background:#11131C;color:#F7F5EE;border:1px solid rgba(212,175,55,.35);border-radius:22px;padding:20px 18px;max-height:92vh;overflow:auto}' +
        'h2{margin:0 0 6px;font-size:19px;font-weight:800}p{margin:0 0 12px;color:#C4C6D0;font-size:14px;line-height:1.5}label{display:block;margin:10px 0 4px;color:#C4C6D0;font-size:13px}' +
        'input{width:100%;min-height:50px;border-radius:14px;border:1px solid rgba(212,175,55,.45);background:#0b0c12;color:#F7F5EE;font:600 17px Outfit,system-ui,sans-serif;padding:0 14px}' +
        'input.num{font-weight:700;font-size:22px;letter-spacing:.2em;text-align:center}' +
        '.warn{background:rgba(255,119,115,.12);color:#FF9C98;border-radius:12px;padding:10px 12px;font-size:14px;margin:0 0 10px}' +
        '.row{display:flex;gap:10px;margin-top:14px}button{flex:1;min-height:46px;border-radius:14px;border:1px solid rgba(212,175,55,.45);background:transparent;color:#F7F5EE;font:600 15px Outfit,system-ui,sans-serif;cursor:pointer}' +
        'button.go{border:0;background:linear-gradient(135deg,#F2CC6B,#D4AF37);color:#1a1200;font-weight:800}button:disabled{opacity:.5}' +
        '.info{background:rgba(76,175,80,.14);color:#9BE3A0;border-radius:12px;padding:10px 12px;font-size:14px;margin:0 0 10px}' +
        '.ck{display:flex;gap:10px;align-items:flex-start;margin:12px 0 2px;color:#C4C6D0;font-size:13px;line-height:1.4;cursor:pointer}.ck input{width:20px;min-height:20px;margin-top:1px;accent-color:#D4AF37;flex:none}' +
        '.stack{display:flex;flex-direction:column;gap:8px;margin-top:14px}.stack button{flex:none}' +
        'button.alt{display:block;width:100%;flex:none;min-height:40px;margin-top:10px;border:0;background:none;color:#F2CC6B;text-decoration:underline;font-weight:600}';

    /* One small dialog for every box. cfg = { title, text, warn, go, fields: [{ id, label, type, num, maxlength, autocomplete }] }.
       Resolves with { id: typed value, ... } or null when the person cancels. The first field is focused; Enter sends. */
    function ask(cfg) {
        return new Promise(function (resolve) {
            var host = document.createElement('div');
            host.setAttribute('data-etelpay-2fa-host', '');
            var shadow = host.attachShadow({ mode: 'open' });
            var html = '<style>' + CSS + '</style><div class="bg"><div class="card" role="dialog" aria-modal="true" aria-labelledby="t"><h2 id="t"></h2><p id="m"></p><div id="i" class="info" hidden></div><div id="w" class="warn" hidden></div>';
            cfg.fields.forEach(function (f, i) {
                html += (f.label ? '<label for="f' + i + '" id="l' + i + '"></label>' : '') +
                    '<input id="' + f.id + '" type="' + (f.type || 'text') + '"' + (f.num ? ' class="num" inputmode="numeric"' : '') + ' autocomplete="' + (f.autocomplete || 'off') + '" maxlength="' + (f.maxlength || 40) + '">';
            });
            if (cfg.check) html += '<label class="ck"><input id="' + cfg.check.id + '" type="checkbox"><span id="ckl"></span></label>';
            if (cfg.buttons) {
                html += '<div class="stack">'; cfg.buttons.forEach(function (b, i) { html += '<button id="b' + i + '" type="button" class="go"></button>'; }); html += '<button id="no" type="button"></button></div>';
            } else {
                html += '<div class="row"><button id="no" type="button"></button><button id="go" type="button" class="go"></button></div>';
            }
            html += (cfg.alt ? '<button id="alt" type="button" class="alt"></button>' : '') + (cfg.alt2 ? '<button id="alt2" type="button" class="alt"></button>' : '') + '</div></div>';
            shadow.innerHTML = html;
            shadow.getElementById('t').textContent = T(cfg.title);
            shadow.getElementById('m').textContent = T(cfg.text);
            shadow.getElementById('no').textContent = T('Cancel');
            if (cfg.buttons) cfg.buttons.forEach(function (b, i) { shadow.getElementById('b' + i).textContent = T(b.label); }); else shadow.getElementById('go').textContent = T(cfg.go || 'Confirm');
            if (cfg.check) shadow.getElementById('ckl').textContent = T(cfg.check.label);
            if (cfg.info) { var inf = shadow.getElementById('i'); inf.textContent = cfg.info; inf.hidden = false; }
            if (cfg.alt) shadow.getElementById('alt').textContent = T(cfg.alt);
            if (cfg.alt2) shadow.getElementById('alt2').textContent = T(cfg.alt2);
            var w = shadow.getElementById('w');
            if (cfg.warn) { w.textContent = cfg.warn; w.hidden = false; }
            var inputs = cfg.fields.map(function (f, i) {
                var el = shadow.getElementById(f.id); el.setAttribute('aria-label', T(f.aria || f.label || cfg.title));
                if (f.label) { shadow.getElementById('l' + i).textContent = T(f.label); el.id = f.id; shadow.getElementById('l' + i).setAttribute('for', f.id); }
                return el;
            });
            var done = function (v) { document.removeEventListener('keydown', onKey, true); if (host.parentNode) host.parentNode.removeChild(host); resolve(v); };
            var submit = function (which) {
                var out = {}, empty = null;
                cfg.fields.forEach(function (f, i) { var v = inputs[i].value; out[f.id] = f.strip ? v.replace(/\s+/g, '') : v; if (!out[f.id] && !f.optional && empty === null) empty = i; });
                if (empty !== null) { inputs[empty].focus(); return; }
                if (cfg.check) out[cfg.check.id] = !!shadow.getElementById(cfg.check.id).checked;
                if (typeof which === 'string') out.choice = which;
                done(out);
            };
            var onKey = function (e) { if (e.key === 'Escape') { e.preventDefault(); done(null); } };
            shadow.getElementById('no').addEventListener('click', function () { done(null); });
            if (cfg.buttons) cfg.buttons.forEach(function (b, i) { shadow.getElementById('b' + i).addEventListener('click', function () { submit(b.id); }); });
            else shadow.getElementById('go').addEventListener('click', function () { submit(); });
            if (cfg.alt) shadow.getElementById('alt').addEventListener('click', function () { done({ alt: true }); });
            if (cfg.alt2) shadow.getElementById('alt2').addEventListener('click', function () { done({ alt2: true }); });
            inputs.forEach(function (el) { el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(cfg.buttons ? cfg.buttons[0].id : undefined); } }); });
            document.addEventListener('keydown', onKey, true);
            (document.body || document.documentElement).appendChild(host);
            setTimeout(function () { inputs[0].focus(); }, 30);
        });
    }

    /* Resolves with { code, skip } ('skip' = the person ticked "do not ask me for this code"), or null when cancelled. offerChoice shows the tick. */
    function askCode(message, offerChoice) {
        return ask({ title: 'Two-step code', text: 'Etel Pay needs your two-step code to continue. Open your authenticator app and type the 6-digit code (or use a backup code).', warn: message,
            check: offerChoice ? { id: 'skip', label: 'Do not ask me for this code when I pay with Etel Pay. My PIN protects each payment.' } : null,
            fields: [{ id: 'c', num: true, type: 'text', autocomplete: 'one-time-code', maxlength: 20, strip: true, aria: 'Two-step code field' }] })
            .then(function (v) { return v ? { code: v.c, skip: !!v.skip } : null; });
    }
    function askPin(message, info) {
        return ask({ title: 'Wallet PIN', text: 'Type your 4-digit Etel Pay PIN to send the payment.', warn: message, info: info || '', go: 'Continue', alt: 'I have no PIN yet', alt2: 'Payment security',
            fields: [{ id: 'p', num: true, type: 'password', autocomplete: 'off', maxlength: 4, strip: true, aria: 'Wallet PIN field' }] })
            .then(function (v) { return v ? (v.alt ? 'ALT' : (v.alt2 ? 'SEC' : v.p)) : ''; });
    }
    function askNewPin(message) {
        return ask({ title: 'Create your Etel Pay PIN', text: 'Your wallet has no PIN yet. Type your Etel-ID password and choose a 4-digit PIN. You will use it for every payment.', warn: message, go: 'Save PIN and pay',
            fields: [{ id: 'pw', label: 'Etel-ID password', type: 'password', autocomplete: 'current-password', maxlength: 100 },
                     { id: 'p1', label: 'New PIN (4 digits)', num: true, type: 'password', maxlength: 4, strip: true },
                     { id: 'p2', label: 'Repeat the PIN', num: true, type: 'password', maxlength: 4, strip: true }] });
    }

    /* What Etel Pay answered, as { message, code, twofa } (all empty when it is not JSON or not an error). The answer itself is left unread. */
    function peek(res) {
        return res.clone().json().then(function (j) {
            var d = (j && j.data && typeof j.data === 'object') ? j.data : {};
            return { message: typeof d.message === 'string' ? d.message : '', code: typeof d.code === 'string' ? d.code : '', twofa: d.requires_2fa === true, ok: !!(j && (j.success || j.ok)) };
        }).catch(function () { return { message: '', code: '', twofa: false, ok: false }; });
    }

    /* The same call again, with these fields added to its JSON body. null when the body is not JSON (nothing to add to). */
    function again(input, init, patch) {
        var body;
        try { body = JSON.parse(init.body); } catch (e) { return null; }
        for (var k in patch) { if (Object.prototype.hasOwnProperty.call(patch, k)) body[k] = patch[k]; }
        var init2 = {}; for (var i in init) { if (Object.prototype.hasOwnProperty.call(init, i)) init2[i] = init[i]; }
        init2.body = JSON.stringify(body);
        return realFetch.call(window, input, init2);
    }

    /* Tells Etel Pay (and so EtelID) the person's choice: mode 'skip' (PIN only, needs the code) or 'ask' (PIN and code).
       Resolves with { ok, code, message, pay_skip, twofa }; never rejects. likeUrl is any Etel Pay URL of this page (its action is swapped). */
    function choiceCall(likeUrl, headers, phone, password, mode, totp) {
        var u = urlOf(likeUrl);
        var url = /([?&])action=[^&]*/.test(u) ? u.replace(/([?&])action=[^&]*/, '$1action=ep_pay_code_mode') : u + (u.indexOf('?') >= 0 ? '&' : '?') + 'action=ep_pay_code_mode';
        var body = { action: 'ep_pay_code_mode', phone: phone, pin: password, mode: mode };
        if (totp) body.totp_code = totp;
        return realFetch.call(window, url, { method: 'POST', headers: headers || { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (res) { return res.json(); })
            .then(function (j) {
                var d = (j && j.data && typeof j.data === 'object') ? j.data : {};
                return { ok: !!(j && j.success), code: typeof d.code === 'string' ? d.code : '', message: typeof d.message === 'string' ? d.message : '', pay_skip: d.pay_skip === true, twofa: d.twofa_enabled === true };
            })
            .catch(function () { return { ok: false, code: 'offline', message: '' }; });
    }

    /* The two-step code for the sign-in. With the tick the choice "PIN only" is saved first (that uses the code), and the sign-in is then sent WITHOUT a code. */
    function codeFlow(input, init, first, st, msg) {
        if (st.code >= MAX_TRIES) return Promise.resolve(first);
        var b0 = bodyOf(init);
        var offer = !!(b0 && b0.phone && b0.pin) && isLogin(input);            // the person can only choose when we know who is signing in
        return askCode(st.code === 0 ? '' : msg, offer).then(function (got) {
            if (!got || !got.code) return first;                               // cancelled: the app shows its usual message
            st.code++;
            var normal = function () {
                var p = again(input, init, { totp_code: got.code });
                if (!p) return first;
                return p.then(function (res2) { return handle(input, init, res2, st); });
            };
            if (!(offer && got.skip)) return normal();
            return choiceCall(input, { 'Content-Type': 'application/json' }, b0.phone, b0.pin, 'skip', got.code).then(function (r) {
                if (r.ok && r.pay_skip) {                                      // saved: the code was used up, so sign in again without it
                    var p = again(input, init, {});
                    if (!p) return first;
                    return p.then(function (res2) { return handle(input, init, res2, st); });
                }
                if (r.code === 'bad_2fa' || r.code === 'requires_2fa') return codeFlow(input, init, first, st, r.message);   // wrong code: ask again
                return normal();                                               // could not save (switched off, not available yet, offline): sign in with the code as usual
            });
        });
    }

    /* The wallet PIN for a payment. */
    function pinFlow(input, init, first, st, msg, info, force) {
        if (st.pin >= MAX_TRIES) return Promise.resolve(first);
        return askPin(st.pin === 0 && !force ? '' : msg, info).then(function (pin) {
            if (!pin) return first;
            if (pin === 'ALT') return createFlow(input, init, first, st, '');   // "I have no PIN yet": go straight to creating one
            if (pin === 'SEC') return securityFlow(input, init, first, st, '');  // "Payment security": choose PIN only, or PIN and code
            var p = again(input, init, { pin: pin });
            if (!p) return first;
            st.pin++;
            return p.then(function (res2) { return handle(input, init, res2, st); });
        });
    }

    function myPhone() {
        try { var u = JSON.parse(localStorage.getItem('etel_user') || '{}'); return (u && u.phone) ? String(u.phone) : ''; } catch (e) { return ''; }
    }

    /* "Payment security": the person chooses how Etel Pay checks them when they pay. Needs the Etel-ID password; "PIN only" also needs a code
       (EtelID checks both). Goes back to the PIN box afterwards (with a note), or stays here showing the reason. */
    function securityFlow(input, init, first, st, warn) {
        if (st.sec >= MAX_TRIES * 2) return pinFlow(input, init, first, st, '', '');
        var phone = myPhone();
        if (!phone) return pinFlow(input, init, first, st, T('Could not find your phone number.'), '', true);
        return ask({ title: 'Payment security', text: 'How should Etel Pay check you when you pay? Your PIN is always asked.', warn: warn,
            buttons: [{ id: 'skip', label: 'PIN only (fast)' }, { id: 'ask', label: 'PIN and 6-digit code (safer)' }],
            fields: [{ id: 'pw', label: 'Etel-ID password', type: 'password', autocomplete: 'current-password', maxlength: 100 },
                     { id: 'c', label: '6-digit code (needed for "PIN only")', num: true, type: 'text', autocomplete: 'one-time-code', maxlength: 20, strip: true, optional: true }] })
            .then(function (v) {
                if (!v) return pinFlow(input, init, first, st, '', '');             // Back: the PIN box again
                st.sec++;
                return choiceCall(input, init.headers, phone, v.pw, v.choice, v.c).then(function (r) {
                    if (r.ok) {
                        var info = !r.twofa ? T('You have no two-step sign-in, so there is no code to ask.') : (r.pay_skip ? T('Saved. Etel Pay will ask for your PIN only.') : T('Saved. Etel Pay will ask for the 6-digit code too.'));
                        return pinFlow(input, init, first, st, '', info);
                    }
                    return securityFlow(input, init, first, st, r.message || T('Could not save. Check your connection.'));
                });
            });
    }

    /* Saves the first wallet PIN (ep_set_wallet_pin: needs the session, the Etel-ID password and, for two-step accounts, the code). Resolves with
       { ok } on success, { fail: message } when it was refused, or { cancelled: true } when the person closed the code box. */
    function savePin(input, init, v, totp, st) {
        var u = urlOf(input);
        var url = /([?&])action=[^&]*/.test(u) ? u.replace(/([?&])action=[^&]*/, '$1action=ep_set_wallet_pin') : u + (u.indexOf('?') >= 0 ? '&' : '?') + 'action=ep_set_wallet_pin';
        var body = { action: 'ep_set_wallet_pin', etelid_pin: v.pw, new_pin: v.p1, confirm_pin: v.p2 };
        if (totp) body.totp_code = totp;
        var init2 = { method: 'POST', headers: init.headers, body: JSON.stringify(body) };
        return realFetch.call(window, url, init2).then(function (res) {
            return peek(res).then(function (r) {
                if (r.ok) return { ok: true };
                if (r.twofa) {
                    if (st.code >= MAX_TRIES) return { fail: r.message };
                    return askCode(st.code === 0 ? '' : r.message, false).then(function (got) {
                        if (!got || !got.code) return { cancelled: true };
                        st.code++;
                        return savePin(input, init, v, got.code, st);
                    });
                }
                return { fail: r.message || 'Could not save the PIN.' };
            });
        }).catch(function () { return { fail: 'Could not save the PIN. Check your connection.' }; });
    }

    /* The wallet has no PIN yet: create it, then send the payment with it. */
    function createFlow(input, init, first, st, warn) {
        if (st.create >= MAX_TRIES) return Promise.resolve(first);
        return askNewPin(warn).then(function (v) {
            if (!v) return first;
            if (!v.pw) return createFlow(input, init, first, st, T('Type your Etel-ID password.'));          // a typing mistake is not a try
            if (!/^\d{4}$/.test(v.p1) || v.p1 !== v.p2) return createFlow(input, init, first, st, T('The PIN must be 4 digits and both PINs must match.'));
            st.create++;                                                                                      // only a real save counts
            st.code = 0;
            return savePin(input, init, v, '', st).then(function (r) {
                if (r.cancelled) return first;
                if (!r.ok) return createFlow(input, init, first, st, r.fail);
                var p = again(input, init, { pin: v.p1 });
                if (!p) return first;
                return p.then(function (res2) { return handle(input, init, res2, st); });
            });
        });
    }

    function handle(input, init, res, st) {
        return peek(res).then(function (r) {
            if (r.twofa && isLogin(input)) return codeFlow(input, init, res, st, r.message);
            if (r.code === 'pin_required' || r.code === 'pin_wrong') return pinFlow(input, init, res, st, r.message);
            if (r.code === 'pin_not_set') return createFlow(input, init, res, st, '');
            return res;                                                        // nothing was asked for (or pin_locked): hand it back as it is
        });
    }

    /* ---- the wallet switch: ask ETEL Delivery before a ride payment moves any money ---- */
    var openUntil = 0;   // an answer "yes" is remembered for a minute; a "no" is never remembered, so switching it on works at once
    function bodyOf(init) { try { return JSON.parse(init.body); } catch (e) { return null; } }
    function isRidePayment(input, init) {
        if (!isEtelPay(input) || !init || typeof init.body !== 'string') return false;
        var b = bodyOf(init); if (!b) return false;
        var m = /[?&]action=([^&]*)/.exec(urlOf(input));
        var act = b.action || (m ? m[1] : '');
        return act === 'ep_send' && typeof b.note === 'string' && b.note.indexOf('ETEL Ride #') === 0;
    }
    function deliveryApi() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
    function deliveryToken() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
    /* Resolves with '' when the payment may go ahead, else the message to show instead. */
    function walletBlock() {
        if (Date.now() < openUntil) return Promise.resolve('');
        var tok = deliveryToken();
        if (!tok) return Promise.resolve(T('Could not check wallet payment. Please try again, or pay cash.'));
        return realFetch.call(window, deliveryApi(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tok }, body: JSON.stringify({ action: 'ride_wallet_status' }) })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.success && j.data && j.data.enabled === true) { openUntil = Date.now() + 60000; return ''; }
                if (j && j.success && j.data && j.data.enabled === false) return T('Wallet payment is coming soon. Please pay with cash or Chapa.');
                return T('Could not check wallet payment. Please try again, or pay cash.');
            })
            .catch(function () { return T('Could not check wallet payment. Please try again, or pay cash.'); });
    }
    function refused(msg) {
        return new Response(JSON.stringify({ success: false, data: { message: msg } }), { status: 400, headers: { 'Content-Type': 'application/json' } });
    }

    window.fetch = function (input, init) {
        var self = this, args = arguments;
        if (isRidePayment(input, init)) {
            return walletBlock().then(function (msg) {
                if (msg) return refused(msg);                                  // switched off (or cannot be checked): no money is sent
                return realFetch.apply(self, args).then(function (res) { return handle(input, init, res, { code: 0, pin: 0, create: 0, sec: 0 }); });
            });
        }
        var p = realFetch.apply(this, arguments);
        if (!isEtelPay(input) || !init || typeof init.body !== 'string') return p;
        return p.then(function (res) { return handle(input, init, res, { code: 0, pin: 0, create: 0, sec: 0 }); });
    };
})();
