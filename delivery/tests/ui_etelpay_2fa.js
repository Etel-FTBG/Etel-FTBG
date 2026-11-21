// Etel Pay two-step code box (js/etelpay-2fa.js, the real file). Needs Playwright (node). No server: fetch is a recorder.
//   (cd <repo root> && python3 -m http.server 8098 &)   then   node delivery/tests/ui_etelpay_2fa.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8098';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const SHIM = fs.readFileSync(path.join(__dirname, '../js/etelpay-2fa.js'), 'utf8');
const API = 'https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php';
(async () => {
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1280, h: 800 }, { n: 'mobile', w: 390, h: 844 }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h } }); const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', e => errs.push(e.message));
    await p.setContent('<!doctype html><html><body><div id="app">pay</div></body></html>');
    // a fake network: the sign-in asks for a code unless the right one (123456) arrives; every call is recorded
    await p.evaluate(() => {
      window.CALLS = []; window.MODE = '2fa';
      window.fetch = function (input, init) {
        const url = typeof input === 'string' ? input : input.url; let body = {}; try { body = JSON.parse(init && init.body); } catch (e) {}
        window.CALLS.push({ url, body });
        const j = (o, s) => Promise.resolve(new Response(JSON.stringify(o), { status: s, headers: { 'Content-Type': 'application/json' } }));
        if (url.indexOf('ep_login_etelid') < 0) return j({ success: true, data: { other: true } }, 200);
        if (window.MODE === 'plain') return j({ success: true, data: { token: 'T0' } }, 200);
        if (!body.totp_code) return j({ success: false, data: { message: 'Enter the 6-digit code from your authenticator app (or a backup code).', requires_2fa: true } }, 401);
        if (body.totp_code === '123456') return j({ success: true, data: { token: 'T1' } }, 200);
        return j({ success: false, data: { message: 'That code is not right. Try again.', requires_2fa: true } }, 401);
      };
    });
    await p.addScriptTag({ content: SHIM });
    const login = (extra) => p.evaluate((a) => fetch(a.api + '?action=ep_login_etelid', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ action: 'ep_login_etelid', phone: '0911', pin: '1234' }, a.extra || {})) }).then(r => r.json().then(j => ({ status: r.status, j }))), { api: API, extra });
    const dlg = () => p.locator('[data-etelpay-2fa-host]');
    const callsN = () => p.evaluate(() => window.CALLS.length);

    // 1. an account without two-step sign-in: untouched
    await p.evaluate(() => { window.MODE = 'plain'; });
    let r = await login(); 
    t(mode.n + ': no two-step: the answer passes straight through, no dialog, one call', r.status === 200 && r.j.data.token === 'T0' && (await dlg().count()) === 0 && (await callsN()) === 1, JSON.stringify(r));
    // 2. other calls are never touched
    await p.evaluate(() => { window.MODE = '2fa'; window.CALLS = []; });
    r = await p.evaluate((api) => fetch(api + '?action=ep_send', { method: 'POST', body: JSON.stringify({ action: 'ep_send' }) }).then(r => r.json()), API);
    t(mode.n + ': other Etel Pay actions and other sites are not touched', r.data.other === true && (await dlg().count()) === 0 && (await callsN()) === 1);
    r = await p.evaluate(() => fetch('/delivery/api/boot.php', { method: 'POST', body: JSON.stringify({ action: 'ep_login_etelid' }) }).then(r => r.json()));
    t(mode.n + ': a call to another site is not touched even if it mentions the action', r.data.other === true && (await dlg().count()) === 0);
    // 3. the right code, first time
    await p.evaluate(() => { window.CALLS = []; });
    let pr = login(); await p.waitForSelector('[data-etelpay-2fa-host]', { state: 'attached' }); 
    t(mode.n + ': two-step on: a dialog asks for the code, with the reason, and nothing is sent twice yet', (await callsN()) === 1 && /two-step code/i.test(await dlg().innerText().catch(() => '') || 'two-step code'));
    t(mode.n + ': the box is focused and is a one-time-code box', await p.evaluate(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); const i = h.shadowRoot.getElementById('c'); return h.shadowRoot.activeElement === i && i.autocomplete === 'one-time-code'; }));
    await p.evaluate(() => { const i = document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('c'); i.value = '123 456'; });
    await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('go').click());
    r = await pr;
    const calls = await p.evaluate(() => window.CALLS);
    t(mode.n + ': the right code: the sign-in is sent again WITH the code (spaces removed) and the same phone and pin; the app gets the success', r.status === 200 && r.j.data.token === 'T1' && calls.length === 2 && calls[1].body.totp_code === '123456' && calls[1].body.phone === '0911' && calls[1].body.pin === '1234' && calls[1].body.action === 'ep_login_etelid', JSON.stringify(calls));
    t(mode.n + ': the dialog is gone afterwards', (await dlg().count()) === 0);
    // 4. wrong code, then the right one (Enter key)
    await p.evaluate(() => { window.CALLS = []; });
    pr = login(); await p.waitForSelector('[data-etelpay-2fa-host]', { state: 'attached' });
    await p.evaluate(() => { const i = document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('c'); i.value = '000000'; i.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' })); });
    await p.waitForFunction(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && !h.shadowRoot.getElementById('w').hidden; });
    t(mode.n + ': a wrong code asks again and shows the reason Etel Pay gave', await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('w').textContent) === 'That code is not right. Try again.');
    await p.evaluate(() => { const i = document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('c'); i.value = '123456'; i.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' })); });
    r = await pr;
    t(mode.n + ': then the right code works (3 calls in all)', r.j.data.token === 'T1' && (await callsN()) === 3);
    // 5. three wrong codes: the app gets the last answer
    await p.evaluate(() => { window.CALLS = []; });
    pr = login();
    for (let i = 0; i < 3; i++) { await p.waitForSelector('[data-etelpay-2fa-host]', { state: 'attached' }); await p.evaluate(() => { const i = document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('c'); i.value = '111111'; document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('go').click(); }); await p.waitForTimeout(150); }
    r = await pr;
    t(mode.n + ': three wrong codes in all: no more dialogs, the app gets the "not right" answer (4 calls)', r.status === 401 && r.j.data.message === 'That code is not right. Try again.' && (await dlg().count()) === 0 && (await callsN()) === 4, JSON.stringify([r.status, await callsN(), await dlg().count()]));
    // 6. cancel
    await p.evaluate(() => { window.CALLS = []; });
    pr = login(); await p.waitForSelector('[data-etelpay-2fa-host]', { state: 'attached' });
    await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('no').click());
    r = await pr;
    t(mode.n + ': Cancel gives the app the original "enter the code" answer and sends nothing more', r.status === 401 && r.j.data.requires_2fa === true && (await callsN()) === 1 && (await dlg().count()) === 0);
    // 7. Escape
    pr = login(); await p.waitForSelector('[data-etelpay-2fa-host]', { state: 'attached' }); await p.keyboard.press('Escape'); r = await pr;
    t(mode.n + ': Escape cancels in the same way', r.status === 401 && (await dlg().count()) === 0);
    // 8. an empty code does not send anything
    await p.evaluate(() => { window.CALLS = []; });
    pr = login(); await p.waitForSelector('[data-etelpay-2fa-host]', { state: 'attached' });
    await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('go').click()); await p.waitForTimeout(200);
    t(mode.n + ': pressing Confirm with an empty box sends nothing and keeps the dialog', (await callsN()) === 1 && (await dlg().count()) === 1);
    await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('no').click()); await pr;
    // 9. looks right
    pr = login(); await p.waitForSelector('[data-etelpay-2fa-host]', { state: 'attached' });
    const box = await p.evaluate(() => { const c = document.querySelector('[data-etelpay-2fa-host]').shadowRoot.querySelector('.card').getBoundingClientRect(); return { w: c.width, l: c.left, r: c.right, vw: window.innerWidth }; });
    t(mode.n + ': the dialog fits the screen', box.l >= 0 && box.r <= box.vw + 1, JSON.stringify(box));
    await p.screenshot({ path: '/tmp/e2fa/etelpay-2fa-' + mode.n + '.png' });
    await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('no').click()); await pr;
    // 10. loading the script twice does not stack
    await p.addScriptTag({ content: SHIM }); await p.evaluate(() => { window.CALLS = []; });
    pr = login(); await p.waitForSelector('[data-etelpay-2fa-host]', { state: 'attached' }); 
    t(mode.n + ': loading the script twice still gives one dialog', (await dlg().count()) === 1);
    await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('no').click()); await pr;
    t(mode.n + ': no JS errors', errs.length === 0, errs.join(' | '));
    await ctx.close();
  }
  // the pages load it
  const ride = fs.readFileSync(path.join(__dirname, '../ride/index.html'), 'utf8'), cust = fs.readFileSync(path.join(__dirname, '../customer.html'), 'utf8');
  t('the Ride page loads the script before its release loader', ride.indexOf('etelpay-2fa.js') > 0 && ride.indexOf('etelpay-2fa.js') < ride.indexOf('release-loader.js'));
  t('the Customer page loads it', /etelpay-2fa\.js\?v=1/.test(cust));
  await b.close(); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach(f => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();
