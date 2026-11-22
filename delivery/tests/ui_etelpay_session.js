// Pay with the wallet PIN only (js/etelpay-session.js, the real file, loaded after the real etelpay-2fa.js). Needs Playwright (node). No server: fetch is a fake Etel Pay.
//   node delivery/tests/ui_etelpay_session.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const F2 = fs.readFileSync(path.join(__dirname, '../js/etelpay-2fa.js'), 'utf8'), SS = fs.readFileSync(path.join(__dirname, '../js/etelpay-session.js'), 'utf8');
const API = 'https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php';
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 390, height: 844 } }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.route('http://etel.test/**', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><body>pay</body>' }));
  await p.goto('http://etel.test/');
  await p.evaluate((API) => {
    localStorage.setItem('etel_user', JSON.stringify({ phone: '0911000001' }));
    window.CALLS = []; window.PROMPTS = []; window.W = { expired: false };
    window.prompt = function (m) { window.PROMPTS.push(m); return 'pw123'; };
    window.fetch = function (input, init) {
      const url = typeof input === 'string' ? input : input.url; let body = {}; try { body = JSON.parse(init && init.body); } catch (e) {}
      window.CALLS.push({ url, body, auth: init && init.headers && init.headers.Authorization });
      const j = (o, s) => Promise.resolve(new Response(JSON.stringify(o), { status: s, headers: { 'Content-Type': 'application/json' } }));
      if (url.indexOf('action=ep_login_etelid') >= 0) return body.pin === 'pw123' ? j({ success: true, data: { token: 'TOK1' } }, 200) : j({ success: false, data: { message: 'Incorrect EtelID password.' } }, 401);
      if (url.indexOf('action=ep_send') >= 0) return window.W.expired ? j({ success: false, data: { message: 'Session expired' } }, 401) : j({ success: true, data: { tx_ref: 'EP-1' } }, 200);
      return j({ success: true, data: {} }, 200);
    };
    // what ride.js does when the pay button is tapped
    window.payFlow = async function () {
      const pin = prompt('Enter your ቢE Pay PIN to pay ETB 100'); if (!pin) return null;
      const login = await (await fetch('https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php?action=ep_login_etelid', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'ep_login_etelid', phone: '0911000001', pin }) })).json();
      if (!(login.success || login.ok)) return 'LOGIN_FAILED:' + (login.data && login.data.message);
      const r = await fetch('https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php?action=ep_send', { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + login.data.token }, body: JSON.stringify({ action: 'ep_send', note: 'x' }) });
      return r.status;
    };
  }, API);
  await p.addScriptTag({ content: F2 }); await p.addScriptTag({ content: SS }); await p.waitForTimeout(100);
  const run = async () => { await p.evaluate(() => { window.CALLS = []; window.PROMPTS = []; }); const out = await p.evaluate(() => window.payFlow()); const st = await p.evaluate(() => ({ calls: window.CALLS.map(c => c.url.replace(/.*action=/, '')), prompts: window.PROMPTS.slice(), auth: window.CALLS.map(c => c.auth).filter(Boolean) })); return Object.assign({ out }, st); };

  let r = await run();
  t('first payment on this phone: asks the Etel-ID password once, in plain words', r.prompts.length === 1 && /Etel-ID password/.test(r.prompts[0]) && /only once/.test(r.prompts[0]) && /ETB 100/.test(r.prompts[0]), JSON.stringify(r.prompts));
  t('... signs in, then sends the payment', r.calls.join() === 'ep_login_etelid,ep_send' && r.out === 200, JSON.stringify(r));
  const kept = await p.evaluate(() => JSON.parse(localStorage.getItem('etel_pay_session_v1') || 'null'));
  t('the Etel Pay session is kept for next time (for this phone number only)', kept && kept.token === 'TOK1' && kept.who === '911000001' && kept.exp > Date.now(), JSON.stringify(kept));

  r = await run();
  t('second payment: NO password box at all', r.prompts.length === 0, JSON.stringify(r.prompts));
  t('... and no sign-in call: it goes straight to the payment with the kept session', r.calls.join() === 'ep_send' && r.auth[0] === 'Bearer TOK1' && r.out === 200, JSON.stringify(r));

  await p.evaluate(() => { window.W.expired = true; });
  r = await run();
  t('the server refuses the kept session (401): the payment fails and the session is thrown away', r.out === 401 && (await p.evaluate(() => localStorage.getItem('etel_pay_session_v1'))) === null, JSON.stringify(r));
  await p.evaluate(() => { window.W.expired = false; });
  r = await run();
  t('next payment asks the password again and works', r.prompts.length === 1 && r.calls.join() === 'ep_login_etelid,ep_send' && r.out === 200, JSON.stringify(r));

  await p.evaluate(() => localStorage.setItem('etel_user', JSON.stringify({ phone: '0922000002' })));
  r = await run();
  t('another phone number on the same phone: the kept session is NOT used', r.prompts.length === 1 && r.calls[0] === 'ep_login_etelid', JSON.stringify(r));
  await p.evaluate(() => localStorage.setItem('etel_user', JSON.stringify({ phone: '0911000001' })));
  await p.evaluate(() => { const s = JSON.parse(localStorage.getItem('etel_pay_session_v1')); s.exp = Date.now() - 1; localStorage.setItem('etel_pay_session_v1', JSON.stringify(s)); });
  r = await run();
  t('an old kept session (past its time) is not used', r.prompts.length === 1 && r.calls[0] === 'ep_login_etelid', JSON.stringify(r));
  await p.evaluate(() => { window.prompt = function (m) { window.PROMPTS.push(m); return 'wrong'; }; });
  await p.evaluate(() => localStorage.removeItem('etel_pay_session_v1'));
  r = await run();
  t('a wrong password is refused and nothing is kept', /LOGIN_FAILED/.test(String(r.out)) && (await p.evaluate(() => localStorage.getItem('etel_pay_session_v1'))) === null, JSON.stringify(r));
  t('no page errors', errs.length === 0, errs.join('|'));
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
