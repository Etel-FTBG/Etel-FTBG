// Etel Pay wallet PIN box and "create your PIN" box (js/etelpay-2fa.js, the real file). Needs Playwright (node). No server: fetch is a fake Etel Pay.
//   node delivery/tests/ui_etelpay_pin.js
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
    // a fake Etel Pay: wallet = { pin: '1234' | null, fails, locked, twofa } ; every call is recorded
    await p.evaluate(() => {
      window.CALLS = []; window.W = { pin: '1234', fails: 0, locked: false, twofa: false };
      window.fetch = function (input, init) {
        const url = typeof input === 'string' ? input : input.url; let body = {}; try { body = JSON.parse(init && init.body); } catch (e) {}
        window.CALLS.push({ url, body, headers: init && init.headers });
        const j = (o, s) => Promise.resolve(new Response(JSON.stringify(o), { status: s, headers: { 'Content-Type': 'application/json' } }));
        if (url.indexOf('/Etel.Pay/') < 0) return j({ success: false, data: { message: 'Enter your PIN to continue.', code: 'pin_required' } }, 400);
        if (url.indexOf('ep_login_etelid') >= 0) {
          if (window.W.twofa && !body.totp_code) return j({ success: false, data: { message: 'Enter the 6-digit code.', requires_2fa: true } }, 401);
          if (window.W.twofa && body.totp_code !== '123456') return j({ success: false, data: { message: 'That code is not right. Try again.', requires_2fa: true } }, 401);
          return j({ success: true, data: { token: 'TOK' } }, 200);
        }
        if (url.indexOf('ep_set_wallet_pin') >= 0) {
          if (body.etelid_pin !== 'pw123') return j({ success: false, data: { message: 'Your Etel-ID password is not right.' } }, 401);
          if (window.W.twofa && !body.totp_code) return j({ success: false, data: { message: 'Enter the 6-digit code.', requires_2fa: true } }, 401);
          if (window.W.twofa && body.totp_code !== '123456') return j({ success: false, data: { message: 'That code is not right. Try again.', requires_2fa: true } }, 401);
          window.W.pin = body.new_pin; return j({ success: true, message: 'Wallet PIN set' }, 200);
        }
        if (url.indexOf('ep_send') >= 0) {
          if (window.W.locked) return j({ success: false, data: { message: 'Too many wrong PINs. Try again in 15 minutes.', code: 'pin_locked' } }, 423);
          if (!body.pin) return j({ success: false, data: { message: 'Enter your PIN to continue.', code: 'pin_required' } }, 400);   // like the real server: the PIN is asked for first,
          if (window.W.pin === null) return j({ success: false, data: { message: 'Set a wallet PIN first.', code: 'pin_not_set' } }, 403);   // then it says there is none
          if (body.pin !== window.W.pin) { window.W.fails++; return j({ success: false, data: { message: 'Incorrect PIN. ' + (5 - window.W.fails) + ' attempts left before a lock.', code: 'pin_wrong' } }, 403); }
          return j({ success: true, data: { tx_ref: 'TX1' } }, 200);
        }
        return j({ success: true, data: { other: true } }, 200);
      };
    });
    await p.addScriptTag({ content: SHIM });
    const am = mode.lang === 'am';
    const send = () => p.evaluate((api) => fetch(api + '?action=ep_send', { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer TOK' }, body: JSON.stringify({ action: 'ep_send', receiver: '0911', amount: 448, currency: 'ETB' }) }).then(async r => ({ status: r.status, j: await r.json() })), API);
    const host = () => p.locator('[data-etelpay-2fa-host]');
    const dlgText = () => p.evaluate(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h ? h.shadowRoot.querySelector('.card').innerText : ''; });
    const setVals = (vals, key) => p.evaluate(([v, k]) => { const s = document.querySelector('[data-etelpay-2fa-host]').shadowRoot; for (const id in v) { const el = s.getElementById(id); el.value = v[id]; } s.getElementById(k || 'go').click(); }, [vals, key]);
    const waitDlg = () => p.waitForSelector('[data-etelpay-2fa-host]', { state: 'attached' });
    const calls = () => p.evaluate(() => window.CALLS);
    const warn = () => p.evaluate(() => { const w = document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('w'); return w.hidden ? '' : w.textContent; });
    const reset = (w) => p.evaluate((w) => { window.CALLS = []; window.W = Object.assign({ pin: '1234', fails: 0, locked: false, twofa: false }, w || {}); }, w);

    // 1. the wallet has a PIN and the app sent none: the PIN box, then the payment goes through
    let pr = send(); await waitDlg();
    t(mode.n + ': PIN asked: the box is titled "Wallet PIN" and nothing else was sent yet', (am ? /የቦርሳ ፒን/ : /Wallet PIN/).test(await dlgText()) && (await calls()).length === 1, await dlgText());
    t(mode.n + ': the PIN box hides what is typed and is numeric with 4 digits', await p.evaluate(() => { const i = document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('p'); return i.type === 'password' && i.maxLength === 4 && i.inputMode === 'numeric'; }));
    await p.screenshot({ path: SHOTS + '/etel-pay-pin-' + mode.n + '.png' });
    await setVals({ p: '1234' });
    let r = await pr; let c = await calls();
    t(mode.n + ': the right PIN: the same payment is sent again WITH the PIN (same receiver, amount, token); the app gets the success', r.status === 200 && r.j.data.tx_ref === 'TX1' && c.length === 2 && c[1].body.pin === '1234' && c[1].body.receiver === '0911' && c[1].body.amount === 448 && c[1].headers.Authorization === 'Bearer TOK', JSON.stringify(c));
    t(mode.n + ': the dialog is gone afterwards', (await host().count()) === 0);

    // 2. wrong PIN then right
    await reset(); pr = send(); await waitDlg(); await setVals({ p: '0000' });
    await p.waitForFunction(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && !h.shadowRoot.getElementById('w').hidden; });
    t(mode.n + ': a wrong PIN asks again and shows the reason Etel Pay gave (attempts left)', /4 attempts left/.test(await warn()), await warn());
    await setVals({ p: '1234' }); r = await pr;
    t(mode.n + ': then the right PIN pays', r.status === 200 && r.j.data.tx_ref === 'TX1' && (await calls()).length === 3);

    // 3. cancel: the app gets the original answer
    await reset(); pr = send(); await waitDlg(); await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('no').click()); r = await pr;
    t(mode.n + ': Cancel gives the app the original "Enter your PIN" answer, nothing more is sent', r.status === 400 && r.j.data.code === 'pin_required' && (await calls()).length === 1 && (await host().count()) === 0);
    // Escape
    await reset(); pr = send(); await waitDlg(); await p.keyboard.press('Escape'); r = await pr;
    t(mode.n + ': Escape does the same', r.status === 400 && (await host().count()) === 0);

    // 4. three wrong PINs: the last answer goes back to the app
    await reset(); pr = send();
    for (let i = 0; i < 3; i++) { await waitDlg(); await setVals({ p: '000' + i }); await p.waitForTimeout(150); }
    r = await pr;
    t(mode.n + ': after 3 wrong PINs the box stops and the app shows Etel Pay\'s own message', r.status === 403 && r.j.data.code === 'pin_wrong' && (await calls()).length === 4 && (await host().count()) === 0, r.status + ' ' + (await calls()).length);

    // 5. locked: no box, the message goes to the app
    await reset({ locked: true }); r = await send();
    t(mode.n + ': locked account: no dialog, Etel Pay\'s "try again in 15 minutes" goes straight to the app', r.status === 423 && /15 minutes/.test(r.j.data.message) && (await host().count()) === 0 && (await calls()).length === 1);

    // 6. no wallet PIN yet: the create box
    await reset({ pin: null }); pr = send(); await waitDlg();
    t(mode.n + ': the PIN box has an "I have no PIN yet" link', await p.evaluate(() => !!document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('alt')));
    await setVals({ p: '0000' });   // typing a PIN the wallet does not have: Etel Pay answers "set a PIN first" and the create box opens
    await p.waitForFunction(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && h.shadowRoot.getElementById('pw'); });
    t(mode.n + ': no PIN yet: the "Create your Etel Pay PIN" box with password, PIN and repeat', (am ? /የኢቴል ፔይ ፒንዎን ይፍጠሩ/ : /Create your Etel Pay PIN/).test(await dlgText()) && (await p.evaluate(() => ['pw', 'p1', 'p2'].every(id => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById(id)))));
    await p.screenshot({ path: SHOTS + '/etel-pay-create-' + mode.n + '.png' });
    const base = (await calls()).length;   // the payment without a PIN, and the PIN that was typed
    await setVals({ pw: '', p1: '4321', p2: '4321' }); await p.waitForTimeout(200);
    t(mode.n + ': an empty password stays in the box (nothing is sent)', (await calls()).length === base && (await host().count()) === 1);
    await setVals({ pw: 'pw123', p1: '4321', p2: '4322' }); await p.waitForTimeout(150);
    t(mode.n + ': PINs that do not match are refused in the box, nothing sent', /4/.test(await warn()) && (await calls()).length === base, await warn());
    await setVals({ pw: 'pw123', p1: '43a1', p2: '43a1' }); await p.waitForTimeout(150);
    t(mode.n + ': a PIN that is not 4 digits is refused, nothing sent', (await calls()).length === base && (await host().count()) === 1);
    await setVals({ pw: 'wrong', p1: '4321', p2: '4321' }); await p.waitForTimeout(250);
    t(mode.n + ': a wrong Etel-ID password shows Etel Pay\'s reason and the box stays', /password is not right/.test(await warn()) && (await host().count()) === 1, await warn());
    await setVals({ pw: 'pw123', p1: '4321', p2: '4321' }); r = await pr; c = await calls();
    const setCall = c.filter(x => x.url.indexOf('ep_set_wallet_pin') >= 0).pop(); const lastSend = c[c.length - 1];
    t(mode.n + ': the PIN is saved with ep_set_wallet_pin (password, new PIN, repeat) using the same session, then the payment is sent with the new PIN', r.status === 200 && r.j.data.tx_ref === 'TX1' && setCall && setCall.body.etelid_pin === 'pw123' && setCall.body.new_pin === '4321' && setCall.body.confirm_pin === '4321' && /action=ep_set_wallet_pin/.test(setCall.url) && setCall.headers.Authorization === 'Bearer TOK' && lastSend.body.pin === '4321' && lastSend.url.indexOf('ep_send') >= 0, JSON.stringify(c.map(x => x.url.split('action=')[1] + ':' + (x.body.pin || ''))));
    t(mode.n + ': the wallet now has the PIN', await p.evaluate(() => window.W.pin) === '4321' && (await host().count()) === 0);

    // 7. create a PIN on a two-step account: the code box comes in the middle
    await reset({ pin: null, twofa: true }); pr = send(); await waitDlg(); await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('alt').click());
    await p.waitForFunction(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && h.shadowRoot.getElementById('pw'); });
    t(mode.n + ': "I have no PIN yet" opens the create box at once, without sending anything', (await calls()).length === 1);
    await setVals({ pw: 'pw123', p1: '5555', p2: '5555' });
    await p.waitForFunction(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && h.shadowRoot.getElementById('c'); });
    t(mode.n + ': two-step account: the code box is asked for after the password', (am ? /ባለ ሁለት ደረጃ ኮድ/ : /Two-step code/).test(await dlgText()));
    await setVals({ c: '000000' }); await p.waitForFunction(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && h.shadowRoot.getElementById('c') && !h.shadowRoot.getElementById('w').hidden; });
    t(mode.n + ': a wrong code shows the reason and asks again', /not right/.test(await warn()));
    await setVals({ c: '123456' }); r = await pr;
    t(mode.n + ': the right code saves the PIN and the payment goes through with it', r.status === 200 && r.j.data.tx_ref === 'TX1' && (await p.evaluate(() => window.W.pin)) === '5555');
    // cancel at the code box
    await reset({ pin: null, twofa: true }); pr = send(); await waitDlg(); await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('alt').click());
    await p.waitForFunction(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && h.shadowRoot.getElementById('pw'); });
    await setVals({ pw: 'pw123', p1: '5555', p2: '5555' });
    await p.waitForFunction(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && h.shadowRoot.getElementById('c'); });
    await p.evaluate(() => document.querySelector('[data-etelpay-2fa-host]').shadowRoot.getElementById('no').click()); r = await pr;
    t(mode.n + ': cancelling at the code box leaves the wallet without a PIN and gives the app the original answer', r.status === 400 && r.j.data.code === 'pin_required' && (await p.evaluate(() => window.W.pin)) === null);

    // 8. the whole ride sequence on a two-step account: sign-in code, then the PIN
    await reset({ twofa: true });
    pr = p.evaluate((api) => fetch(api + '?action=ep_login_etelid', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'ep_login_etelid', phone: '0911', pin: 'pw123' }) }).then(r => r.json()).then(j => fetch(api + '?action=ep_send', { method: 'POST', headers: { Authorization: 'Bearer ' + j.data.token }, body: JSON.stringify({ action: 'ep_send', receiver: '0911', amount: 448 }) })).then(async r => ({ status: r.status, j: await r.json() })), API);
    await waitDlg(); t(mode.n + ': ride sequence: first the code box', (am ? /ባለ ሁለት ደረጃ ኮድ/ : /Two-step code/).test(await dlgText()));
    await setVals({ c: '123456' });
    await p.waitForFunction(() => { const h = document.querySelector('[data-etelpay-2fa-host]'); return h && h.shadowRoot.getElementById('p'); });
    t(mode.n + ': ... then the PIN box', (am ? /የቦርሳ ፒን/ : /Wallet PIN/).test(await dlgText()));
    await setVals({ p: '1234' }); r = await pr;
    t(mode.n + ': ... and the payment is done (this is the sequence that ended in "Enter your PIN to continue")', r.status === 200 && r.j.data.tx_ref === 'TX1', JSON.stringify(r));

    // 9. nothing else is touched
    await reset(); r = await p.evaluate(() => fetch('/delivery/api/boot.php', { method: 'POST', body: JSON.stringify({ action: 'x' }) }).then(async r => ({ status: r.status, j: await r.json() })));
    t(mode.n + ': another site that answers pin_required is not touched', r.status === 400 && (await host().count()) === 0 && (await calls()).length === 1);
    r = await p.evaluate((api) => fetch(api + '?action=ep_balance', { method: 'POST', body: JSON.stringify({ action: 'ep_balance' }) }).then(r => r.json()), API);
    t(mode.n + ': other Etel Pay answers pass straight through', r.data.other === true && (await host().count()) === 0);
    t(mode.n + ': no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
