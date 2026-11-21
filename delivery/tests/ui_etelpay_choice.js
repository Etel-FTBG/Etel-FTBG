// Etel Pay: the person's choice "PIN only, not the 6-digit code" (js/etelpay-2fa.js, the real file). Needs Playwright (node). No server: fetch is a fake Etel Pay/EtelID.
//   node delivery/tests/ui_etelpay_choice.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const SHIM = fs.readFileSync(path.join(__dirname, '../js/etelpay-2fa.js'), 'utf8');
const API = 'https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php';
(async () => {
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1280, h: 800, lang: 'en' }, { n: 'mobile-am', w: 390, h: 844, lang: 'am' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h } });
    await ctx.addInitScript((l) => { try { localStorage.setItem('etel_lang', l); } catch (e) {} }, mode.lang);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route('http://etel.test/**', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><meta name="viewport" content="width=device-width"><body style="background:#222"><div id="app">pay</div></body>' }));
    await p.goto('http://etel.test/');
    await p.evaluate(() => {
      localStorage.setItem('etel_user', JSON.stringify({ phone: '0911000001' }));
      window.CALLS = []; window.W = { twofa: true, skip: false, consumed: false, kill: false, old: false, pw: 'pw123', code: '123456', pin: '1234' };
      window.fetch = function (input, init) {
        const url = typeof input === 'string' ? input : input.url; let body = {}; try { body = JSON.parse(init && init.body); } catch (e) {}
        window.CALLS.push({ url, body });
        const j = (o, s) => Promise.resolve(new Response(JSON.stringify(o), { status: s, headers: { 'Content-Type': 'application/json' } }));
        const W = window.W;
        const codeOk = (c) => c === W.code && !W.consumed;
        if (url.indexOf('action=ep_login_etelid') >= 0) {
          if (body.pin !== W.pw) return j({ success: false, data: { message: 'Incorrect EtelID password.' } }, 401);
          if (W.twofa && !W.skip) {
            if (!body.totp_code) return j({ success: false, data: { message: 'Enter the 6-digit code.', requires_2fa: true } }, 401);
            if (!codeOk(body.totp_code)) return j({ success: false, data: { message: 'That code is not right. Try again.', requires_2fa: true } }, 401);
            W.consumed = true;
          }
          return j({ success: true, data: { token: 'TOK' } }, 200);
        }
        if (url.indexOf('action=ep_pay_code_mode') >= 0) {
          if (body.pin !== W.pw) return j({ success: false, data: { message: 'Incorrect EtelID password.' } }, 401);
          if (!W.twofa) return j({ success: true, data: { twofa_enabled: false, pay_skip: false } }, 200);
          if (body.mode === 'ask') { W.skip = false; return j({ success: true, data: { twofa_enabled: true, pay_skip: false } }, 200); }
          if (W.old) return j({ success: false, data: { message: 'This option is not available yet. Please try again later.', code: 'not_available' } }, 404);
          if (W.kill) return j({ success: false, data: { message: 'Etel Pay always asks for the 6-digit code at the moment.', code: 'pay_choice_off' } }, 403);
          if (!body.totp_code) return j({ success: false, data: { message: 'Enter the 6-digit code.', requires_2fa: true, code: 'requires_2fa' } }, 401);
          if (!codeOk(body.totp_code)) return j({ success: false, data: { message: 'That code is not right. Check the time on your phone and try again.', requires_2fa: true, code: 'bad_2fa' } }, 401);
          W.consumed = true; W.skip = true; return j({ success: true, data: { twofa_enabled: true, pay_skip: true } }, 200);
        }
        if (url.indexOf('action=ep_send') >= 0) {
          if (!body.pin) return j({ success: false, data: { message: 'Enter your PIN to continue.', code: 'pin_required' } }, 400);
          if (body.pin !== W.pin) return j({ success: false, data: { message: 'Incorrect PIN. 4 attempts left before a lock.', code: 'pin_wrong' } }, 403);
          return j({ success: true, data: { tx_ref: 'TX1' } }, 200);
        }
        return j({ success: true, data: { other: true } }, 200);
      };
    });
    await p.addScriptTag({ content: SHIM });
    const am = mode.lang === 'am';
    const host = () => p.locator('[data-etelpay-2fa-host]');
    const waitDlg = () => p.waitForSelector('[data-etelpay-2fa-host]', { state: 'attached', timeout: 6000 });
    const sr = '(document.querySelector("[data-etelpay-2fa-host]").shadowRoot)';
    const dlgText = () => p.evaluate(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h ? h.shadowRoot.querySelector('.card').innerText : ''; });
    const has = (id) => p.evaluate((id) => !!document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById(id), id);
    const fill = (vals, click, tick) => p.evaluate(([v, k, tk]) => { const s = document.querySelector('[data-etelpay-2fa-host]').shadowRoot; for (const id in v) s.getElementById(id).value = v[id]; if (tk !== undefined) s.getElementById('skip').checked = tk; s.getElementById(k || 'go').click(); }, [vals, click, tick]);
    const waitFor = (id) => p.waitForFunction((id) => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && h.shadowRoot.getElementById(id); }, id, { timeout: 6000 });
    const warn = () => p.evaluate(() => { const w = document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('w'); return w.hidden ? '' : w.textContent; });
    const info = () => p.evaluate(() => { const w = document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('i'); return w.hidden ? '' : w.textContent; });
    const calls = () => p.evaluate(() => window.CALLS);
    const W = () => p.evaluate(() => window.W);
    const setW = (o) => p.evaluate((o) => { window.CALLS = []; Object.assign(window.W, o); }, o);
    const login = (extra) => p.evaluate(([api, extra]) => fetch(api + '?action=ep_login_etelid', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ action: 'ep_login_etelid', phone: '0911000001', pin: 'pw123' }, extra || {})) }).then(async r => ({ status: r.status, j: await r.json() })), [API, extra]);
    const send = () => p.evaluate((api) => fetch(api + '?action=ep_send', { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer TOK' }, body: JSON.stringify({ action: 'ep_send', receiver: '0911', amount: 448 }) }).then(async r => ({ status: r.status, j: await r.json() })), API);
    const actions = (c) => c.map(x => (x.url.split('action=')[1] || '').split('&')[0]);

    // 1. the tick is offered in the code box of the sign-in
    let pr = login(); await waitDlg();
    t(mode.n + ': the code box offers "do not ask me for this code" (a tick, off by default)', (await has('skip')) && (await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('skip').checked === false)) && (am ? /ኮድ አትጠይቁኝ/ : /Do not ask me for this code/).test(await dlgText()), await dlgText());
    await p.screenshot({ path: SHOTS + '/etel-pay-choice-code-' + mode.n + '.png' });
    // 2. without the tick: as before (one retry with the code), nothing saved
    await fill({ c: '123456' }, 'go', false); let r = await pr; let c = await calls();
    t(mode.n + ': no tick: the sign-in is sent again WITH the code, no choice is saved', r.status === 200 && r.j.data.token === 'TOK' && actions(c).join() === 'ep_login_etelid,ep_login_etelid' && c[1].body.totp_code === '123456' && (await W()).skip === false);

    // 3. with the tick and the right code: the choice is saved, then the sign-in goes without a code
    await setW({ consumed: false });
    pr = login(); await waitDlg(); await fill({ c: '123456' }, 'go', true); r = await pr; c = await calls();
    t(mode.n + ': tick + right code: the choice is saved (phone, password, mode skip, the code), then the sign-in is sent WITHOUT a code and succeeds', r.status === 200 && r.j.data.token === 'TOK' && actions(c).join() === 'ep_login_etelid,ep_pay_code_mode,ep_login_etelid' && c[1].body.mode === 'skip' && c[1].body.phone === '0911000001' && c[1].body.pin === 'pw123' && c[1].body.totp_code === '123456' && !('totp_code' in c[2].body) && (await W()).skip === true, JSON.stringify(c.map(x => x.url.split('action=')[1] + ':' + JSON.stringify(x.body))));
    t(mode.n + ': the dialog is gone', (await host().count()) === 0);
    // 4. afterwards: no code is asked
    await setW({ consumed: true }); r = await login();
    t(mode.n + ': the next payments: no code box at all, one call', r.status === 200 && r.j.data.token === 'TOK' && (await host().count()) === 0 && (await calls()).length === 1);

    // 5. wrong code with the tick: asked again, then it works
    await setW({ skip: false, consumed: false });
    pr = login(); await waitDlg(); await fill({ c: '000000' }, 'go', true);
    await p.waitForFunction(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && !h.shadowRoot.getElementById('w').hidden; }, null, { timeout: 6000 });
    t(mode.n + ': tick + wrong code: the reason is shown and the code is asked again', /not right/.test(await warn()), await warn());
    await fill({ c: '123456' }, 'go', true); r = await pr;
    t(mode.n + ': ... then the right code with the tick saves the choice and signs in', r.status === 200 && (await W()).skip === true);

    // 6. the owner's kill switch / an EtelID that is not updated yet: the sign-in still works with the code, the choice is not saved
    for (const [label, flags] of [['kill switch', { kill: true }], ['EtelID not updated yet', { old: true }]]) {
      await setW(Object.assign({ skip: false, consumed: false, kill: false, old: false }, flags));
      pr = login(); await waitDlg(); await fill({ c: '123456' }, 'go', true); r = await pr; c = await calls();
      t(mode.n + ': ' + label + ': the tick could not be saved, so the sign-in is sent with the code as usual and succeeds', r.status === 200 && r.j.data.token === 'TOK' && actions(c).join() === 'ep_login_etelid,ep_pay_code_mode,ep_login_etelid' && c[2].body.totp_code === '123456' && (await W()).skip === false, JSON.stringify(actions(c)));
    }

    // 7. the tick is only offered where the person can really choose
    await setW({ skip: false, consumed: false, kill: false, old: false });
    pr = p.evaluate((api) => fetch(api + '?action=ep_login_etelid', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'ep_login_etelid', pin: 'pw123' }) }).then(r => r.json()), API); await waitDlg();
    t(mode.n + ': a sign-in that does not say who signs in: no tick (the choice could not be saved)', !(await has('skip')));
    await fill({ c: '123456' }, 'no'); await pr;
    // the code box of creating a PIN has no tick
    await setW({ pin: null }); await p.evaluate(() => { window.W.pin = null; });

    // 8. "Payment security" in the PIN box
    await setW({ skip: true, consumed: false, pin: '1234', twofa: true });
    pr = send(); await waitDlg();
    t(mode.n + ': the PIN box has a "Payment security" link', await has('alt2'));
    await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('alt2').click());
    await waitFor('pw');
    t(mode.n + ': the Payment security box: password, code (optional), "PIN only", "PIN and code" and Back', (await has('c')) && (await has('b0')) && (await has('b1')) && (await has('no')) && (am ? /የክፍያ ደህንነት/ : /Payment security/).test(await dlgText()) && (am ? /ፒን ብቻ/ : /PIN only \(fast\)/).test(await dlgText()), await dlgText());
    await p.screenshot({ path: SHOTS + '/etel-pay-security-' + mode.n + '.png' });
    // wrong password
    await fill({ pw: 'nope', c: '' }, 'b1'); await p.waitForFunction(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && !h.shadowRoot.getElementById('w').hidden; }, null, { timeout: 6000 });
    t(mode.n + ': a wrong password shows the reason and stays in the box', /Incorrect EtelID password/.test(await warn()) && (await has('pw')));
    // PIN and code (safer): password only
    await fill({ pw: 'pw123', c: '' }, 'b1'); await waitFor('p');
    t(mode.n + ': "PIN and 6-digit code": needs only the password, saved, back to the PIN box with a note', (await W()).skip === false && (am ? /ኮዱንም ይጠይቃል/ : /ask for the 6-digit code too/).test(await info()), await info());
    const sc = (await calls()).filter(x => x.url.indexOf('ep_pay_code_mode') >= 0).pop();
    t(mode.n + ': the choice call carries the phone from the page, the password and the mode', sc.body.phone === '0911000001' && sc.body.pin === 'pw123' && sc.body.mode === 'ask');
    // choose PIN only: the code is needed
    await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('alt2').click()); await waitFor('pw');
    await fill({ pw: 'pw123', c: '' }, 'b0'); await p.waitForFunction(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && h.shadowRoot.getElementById('pw') && !h.shadowRoot.getElementById('w').hidden; }, null, { timeout: 6000 });
    t(mode.n + ': "PIN only" without a code: asked for it, stays in the box', /6-digit/.test(await warn()) && (await W()).skip === false, await warn());
    await fill({ pw: 'pw123', c: '123456' }, 'b0'); await waitFor('p');
    t(mode.n + ': "PIN only" with the right code: saved, back to the PIN box with a note', (await W()).skip === true && (am ? /ፒንዎን ብቻ ይጠይቃል/ : /ask for your PIN only/).test(await info()), await info());
    // Back
    await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('alt2').click()); await waitFor('pw');
    await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('no').click()); await waitFor('p');
    t(mode.n + ': Back returns to the PIN box without a note', (await info()) === '');
    // and the payment itself still completes
    await fill({ p: '1234' }); r = await pr;
    t(mode.n + ': the payment itself still completes with the PIN', r.status === 200 && r.j.data.tx_ref === 'TX1');

    // 9. a member without two-step sign-in
    await setW({ twofa: false, skip: false }); pr = send(); await waitDlg(); await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('alt2').click()); await waitFor('pw');
    await fill({ pw: 'pw123', c: '' }, 'b0'); await waitFor('p');
    t(mode.n + ': no two-step sign-in: "PIN only" says there is no code to ask', (am ? /ሁለት ደረጃ/ : /no two-step sign-in/).test(await info()), await info());
    await p.keyboard.press('Escape'); await pr;
    // 10. no phone on the page
    await setW({ twofa: true }); await p.evaluate(() => localStorage.removeItem('etel_user'));
    pr = send(); await waitDlg(); await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('alt2').click()); await waitFor('p');
    t(mode.n + ': no phone number on the page: back to the PIN box with a plain message, nothing sent', (am ? /ስልክ ቁጥርዎን/ : /Could not find your phone number/).test(await warn()) && (await calls()).filter(x => x.url.indexOf('ep_pay_code_mode') >= 0).length === 0);
    await p.keyboard.press('Escape'); await pr;
    t(mode.n + ': no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
