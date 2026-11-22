// How Etel Pay checks you: PIN only after one sign-in on the phone, and the profile switch (js/etelpay-session.js, the real file, after the real etelpay-2fa.js).
// Needs Playwright (node). No server: fetch is a fake Etel Pay.   node delivery/tests/ui_etelpay_session.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const F2 = fs.readFileSync(path.join(__dirname, '../js/etelpay-2fa.js'), 'utf8'), SS = fs.readFileSync(path.join(__dirname, '../js/etelpay-session.js'), 'utf8');
const DEVTOK = 'a'.repeat(64);
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 390, height: 844 } }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.route('http://etel.test/**', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><body><div id="profile-info"></div>pay</body>' }));
  await p.goto('http://etel.test/');
  await p.evaluate((DEVTOK) => {
    localStorage.setItem('etel_user', JSON.stringify({ phone: '0911000001' }));
    localStorage.setItem('etel_token', 'DT'); window.CALLS = []; window.PROMPTS = []; window.W = { mode: 'pin', deviceOk: true, modeRefuse: null };
    window.prompt = function (m) { window.PROMPTS.push(m); return 'pw123'; };
    window.fetch = function (input, init) {
      const url = typeof input === 'string' ? input : input.url; let body = {}; try { body = JSON.parse(init && init.body); } catch (e) {}
      window.CALLS.push({ url, body, auth: init && init.headers && init.headers.Authorization });
      const j = (o, s) => Promise.resolve(new Response(JSON.stringify(o), { status: s, headers: { 'Content-Type': 'application/json' } }));
      const W = window.W;
      if (body.action === 'ride_wallet_status' || url.indexOf('ride_wallet_status') >= 0) return j({ success: true, data: { enabled: true } }, 200);
      if (url.indexOf('action=ep_login_etelid') >= 0) {
        if (body.pin !== 'pw123') return j({ success: false, data: { message: 'Incorrect EtelID password.' } }, 401);
        const d = { token: 'FULLTOK', pay_mode: W.mode }; if (body.remember_device === '1' && W.mode === 'pin') d.device_token = DEVTOK;
        return j({ success: true, data: d }, 200);
      }
      if (url.indexOf('action=ep_login_device') >= 0) return (W.deviceOk && body.device_token === DEVTOK && W.mode === 'pin') ? j({ success: true, data: { token: 'PAYTOK', via: 'device', pay_mode: 'pin' } }, 200) : j({ success: false, data: { message: 'unknown', code: 'device_unknown' } }, 401);
      if (url.indexOf('action=ep_send') >= 0) return j({ success: true, data: { tx_ref: 'EP-1' } }, 200);
      if (url.indexOf('action=ep_pay_auth_mode') >= 0) {
        if (W.modeRefuse) return j({ success: false, data: { message: W.modeRefuse, requires_2fa: true } }, 401);
        if (body.pin !== 'pw123') return j({ success: false, data: { message: 'Incorrect EtelID password.' } }, 401);
        W.mode = body.mode; const d = { mode: body.mode }; if (body.mode === 'pin') d.device_token = DEVTOK; return j({ success: true, data: d }, 200);
      }
      return j({ success: true, data: {} }, 200);
    };
    window.payFlow = async function () {
      const pin = prompt('Enter your ቢE Pay PIN to pay ETB 100'); if (!pin) return null;
      const login = await (await fetch('https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php?action=ep_login_etelid', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'ep_login_etelid', phone: '0911000001', pin }) })).json();
      if (!(login.success || login.ok)) return 'LOGIN_FAILED:' + (login.data && login.data.message);
      const r = await fetch('https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php?action=ep_send', { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + login.data.token }, body: JSON.stringify({ action: 'ep_send', note: 'ETEL Ride #1' }) });
      return r.status;
    };
  }, DEVTOK);
  await p.addScriptTag({ content: F2 }); await p.addScriptTag({ content: SS }); await p.waitForTimeout(100);
  const run = async () => { await p.evaluate(() => { window.CALLS = []; window.PROMPTS = []; }); const out = await p.evaluate(() => window.payFlow()); const st = await p.evaluate(() => ({ calls: window.CALLS.map(c => c.url.replace(/.*action=/, '')), bodies: window.CALLS.map(c => c.body), prompts: window.PROMPTS.slice(), auth: window.CALLS.map(c => c.auth).filter(Boolean) })); return Object.assign({ out }, st); };
  const store = (k) => p.evaluate((k) => JSON.parse(localStorage.getItem(k) || 'null'), k);

  let r = await run();
  t('first payment on this phone: the password box, in plain words ("only once")', r.prompts.length === 1 && /Etel-ID password/.test(r.prompts[0]) && /only once/.test(r.prompts[0]) && /ETB 100/.test(r.prompts[0]), JSON.stringify(r.prompts));
  t('... the sign-in asks Etel Pay to remember this phone', r.bodies[0].remember_device === '1' && r.calls.filter(c => !/boot/.test(c)).join() === 'ep_login_etelid,ep_send' && r.out === 200, JSON.stringify(r));
  let dev = await store('etel_pay_device_v2');
  t('the phone keeps the device token (for this phone number only) and the choice', dev && dev.token === DEVTOK && dev.who === '911000001' && (await store('etel_pay_mode_v2')).mode === 'pin', JSON.stringify(dev));

  r = await run();
  t('second payment: NO password box and no code', r.prompts.length === 0, JSON.stringify(r.prompts));
  t('... it signs in with the device token (pay-only session) and goes straight to the payment', r.calls.join() === 'ep_login_device,ep_send' && r.bodies[0].device_token === DEVTOK && r.auth[0] === 'Bearer PAYTOK' && r.out === 200, JSON.stringify(r));
  t('... the password is never sent', !JSON.stringify(r.bodies).includes('pw123'));

  await p.evaluate(() => { window.W.deviceOk = false; });
  r = await run();
  t('Etel Pay forgot this phone (401): the payment says so and the token is thrown away', /LOGIN_FAILED/.test(String(r.out)) && /type your Etel-ID password/.test(String(r.out)) && (await store('etel_pay_device_v2')) === null, JSON.stringify(r));
  await p.evaluate(() => { window.W.deviceOk = true; });
  r = await run();
  t('the next payment asks the password again, and remembers the phone again', r.prompts.length === 1 && r.out === 200 && (await store('etel_pay_device_v2')) && (await store('etel_pay_device_v2')).token === DEVTOK, JSON.stringify(r));

  await p.evaluate(() => localStorage.setItem('etel_user', JSON.stringify({ phone: '0922000002' })));
  r = await run();
  t('another phone number on this phone: the device is NOT used (password box)', r.prompts.length === 1 && r.calls[0] === 'ep_login_etelid', JSON.stringify(r));
  await p.evaluate(() => localStorage.setItem('etel_user', JSON.stringify({ phone: '0911000001' })));
  await run();   // this phone number signs in again (the phone keeps one device at a time)

  // the member chose "password" on another phone: the sign-in answer says so, and this phone stops using PIN only
  await p.evaluate(() => { window.W.mode = 'password'; });
  r = await run();   // device exists -> sentinel -> device login refused -> asks again next time
  t('chosen "password" elsewhere: the old device is refused and dropped', /LOGIN_FAILED/.test(String(r.out)) && (await store('etel_pay_device_v2')) === null, JSON.stringify(r));
  r = await run(); await p.waitForTimeout(50);
  t('then the password sign-in works and tells this phone the choice is now "password"', r.prompts.length === 1 && (await store('etel_pay_mode_v2')).mode === 'password' && r.out === 200 && (await store('etel_pay_device_v2')) === null, JSON.stringify(r));
  r = await run();
  t('from then on: every payment asks the password in plain words (no "only once"); no device is kept', r.prompts.length === 1 && !/only once/.test(r.prompts[0]) && (await store('etel_pay_device_v2')) === null && r.calls[0] === 'ep_login_etelid', JSON.stringify(r));

  // ---- the profile switch ----
  await p.evaluate(() => { document.body.insertAdjacentHTML('beforeend', '<div data-etel-paysec-open id="row">Payment security</div>'); window.W.mode = 'password'; });
  const dlg = () => p.evaluate(() => { const h = document.querySelector('[data-etelpay-session-host]'); if (!h) return null; const s = h.shadowRoot; return { checked: (s.querySelector('input[type=radio]:checked') || {}).value, text: s.textContent, opts: s.querySelectorAll('label.opt').length }; });
  await p.click('#row'); await p.waitForTimeout(50);
  let d = await dlg();
  t('the row opens "Payment security" with the 3 choices and the current one marked', d && d.opts === 3 && d.checked === 'password' && /Payment security/.test(d.text) && /PIN only/.test(d.text) && /Password, code and PIN/.test(d.text), JSON.stringify(d));
  const act = (js) => p.evaluate(js);
  await act(() => { const s = document.querySelector('[data-etelpay-session-host]').shadowRoot; s.querySelector('label[data-m=pin]').click(); s.querySelector('#go').click(); });
  await p.waitForTimeout(50);
  d = await dlg();
  t('saving without the password is refused on the spot and asks for it', d && /Etel-ID password/.test(d.text) && (await p.evaluate(() => window.CALLS.filter(c => /ep_pay_auth_mode/.test(c.url)).length)) === 0);
  await act(() => { const s = document.querySelector('[data-etelpay-session-host]').shadowRoot; s.querySelector('#pw').value = 'nope'; s.querySelector('#go').click(); });
  await p.waitForTimeout(80);
  d = await dlg();
  t('a wrong password: the reason is shown, the dialog stays, nothing is saved', d && /Incorrect EtelID password/.test(d.text) && (await store('etel_pay_mode_v2')).mode === 'password');
  await act(() => { const s = document.querySelector('[data-etelpay-session-host]').shadowRoot; s.querySelector('#pw').value = 'pw123'; s.querySelector('#cd').value = ' 123 456 '; s.querySelector('#go').click(); });
  await p.waitForTimeout(120);
  const sent = await p.evaluate(() => window.CALLS.filter(c => /ep_pay_auth_mode/.test(c.url)).pop().body);
  t('PIN only chosen: sends the phone, password, choice and the code (spaces removed)', sent.mode === 'pin' && sent.pin === 'pw123' && sent.totp_code === '123456' && sent.phone === '0911000001', JSON.stringify(sent));
  t('... saved: the choice and the device token are kept, so the next payment asks only the PIN', (await store('etel_pay_mode_v2')).mode === 'pin' && (await store('etel_pay_device_v2')).token === DEVTOK);
  await p.waitForTimeout(1500);
  t('... and the dialog closes by itself', (await dlg()) === null);
  r = await run();
  t('right after choosing PIN only: payment with no password box', r.prompts.length === 0 && r.calls.join() === 'ep_login_device,ep_send' && r.out === 200, JSON.stringify(r));

  await p.click('#row'); await p.waitForTimeout(50);
  await act(() => { const s = document.querySelector('[data-etelpay-session-host]').shadowRoot; s.querySelector('label[data-m=code]').click(); s.querySelector('#pw').value = 'pw123'; s.querySelector('#go').click(); });
  await p.waitForTimeout(1700);
  t('choosing "Password, code and PIN" forgets the device on this phone', (await store('etel_pay_mode_v2')).mode === 'code' && (await store('etel_pay_device_v2')) === null);
  await p.click('#row'); await p.waitForTimeout(50);
  t('reopened: the saved choice is the one marked', (await dlg()).checked === 'code');
  await act(() => { document.querySelector('[data-etelpay-session-host]').shadowRoot.querySelector('#no').click(); });
  t('Cancel closes it', (await dlg()) === null);
  await p.click('#row'); await p.waitForTimeout(50);
  await act(() => { window.W.modeRefuse = 'Enter the 6-digit code from your authenticator app.'; const s = document.querySelector('[data-etelpay-session-host]').shadowRoot; s.querySelector('label[data-m=pin]').click(); s.querySelector('#pw').value = 'pw123'; s.querySelector('#go').click(); });
  await p.waitForTimeout(80);
  d = await dlg();
  t('the code is needed: its message is shown and nothing changes', d && /6-digit code/.test(d.text) && (await store('etel_pay_mode_v2')).mode === 'code');
  await p.keyboard.press('Escape'); await p.waitForTimeout(30);

  // ---- the Customer page draws its profile panel in script: the row is added by us ----
  await p.evaluate(() => { document.getElementById('row').remove(); document.getElementById('profile-info').innerHTML = '<div><div class="hero"></div><div style="padding:8px 14px;display:flex;flex-direction:column;gap:1px"><div>My Orders</div></div></div>'; });
  await p.waitForTimeout(80);
  t('Customer profile: the "Payment security" row appears once', await p.evaluate(() => document.querySelectorAll('#profile-info [data-etel-paysec-open]').length) === 1);
  await p.evaluate(() => { document.getElementById('profile-info').innerHTML = '<div><div style="padding:8px 14px;display:flex;flex-direction:column;gap:1px"><div>My Orders</div></div></div>'; });
  await p.waitForTimeout(80);
  t('... and again after the panel is drawn again (not twice)', await p.evaluate(() => document.querySelectorAll('#profile-info [data-etel-paysec-open]').length) === 1);
  await p.click('#profile-info [data-etel-paysec-open]'); await p.waitForTimeout(50);
  t('... clicking it opens the same dialog', (await dlg()) !== null);
  t('no page errors', errs.length === 0, errs.join('|'));
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
