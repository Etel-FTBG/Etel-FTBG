// Every signed-in app (js/etel-idle-logout.js): sign out when the app is not used. Warning with a countdown one minute before the end, "Stay signed in" resets,
// real use (tap, key, scroll) resets, background refreshes do not, working couriers / drivers are never signed out, a phone reopened after a long time is signed out
// at once, the sign-in page says why. Real script, a plain page with a stand-in for the app's Auth, fake clock.   node delivery/tests/ui_idle_logout.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
let EXTRA = '';
const page = () => `<!doctype html><html><body><button id="b">tap</button><div id="active-content"></div><div style="height:3000px"></div>
<script>window.__out = 0; window.Auth = { getToken: function () { return localStorage.getItem('etel_token') || ''; }, logout: function () { window.__out++; } }; ${EXTRA}</script>
<script src="/js/etel-idle-logout.js"></script></body></html>`;
const srv = http.createServer((q, r) => { const u = q.url.split('?')[0]; if (u === '/t.html') { r.setHeader('Content-Type', 'text/html; charset=utf-8'); return r.end(page()); } if (u === '/login.html') { r.setHeader('Content-Type', 'text/html; charset=utf-8'); return r.end('<!doctype html><html><body><p>login</p><script src="/js/etel-idle-notice.js"></script></body></html>'); }
  fs.readFile(path.join(ROOT, u), (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', 'application/javascript; charset=utf-8'); r.end(d); } }); });
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  const run = async (o, steps) => {
    EXTRA = o.extra || ''; const ctx = await b.newContext({ serviceWorkers: 'block' });
    await ctx.addInitScript(([tok, lang, last, stale]) => { if (tok) { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, role: tok })); } if (lang) localStorage.setItem('etel_lang', lang); if (last) { localStorage.setItem('etel_last_active', String(Date.now() - last)); if (!stale) localStorage.setItem('etel_last_active_tok', 'T'); } }, [o.token === undefined ? 'customer' : o.token, o.lang || '', o.last || o.lastStale || 0, !!o.lastStale]);
    const p = await ctx.newPage(); const pings = []; const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/boot\.php/, r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} pings.push({ active: d.active, auth: r.request().headers()['authorization'] });
      if (o.expired) return r.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ success: false, code: 'idle_expired' }) });
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { idle_minutes: o.limit == null ? 5 : o.limit, exempt: !!o.exempt, seconds_left: null } }) }); });
    await p.clock.install(); await p.goto(HOST + '/t.html'); await p.waitForTimeout(150);
    await steps(p, pings); const e = errs.slice(); await ctx.close(); return e;
  };
  const adv = async (p, ms) => { await p.clock.runFor(ms); await p.waitForTimeout(40); };
  const warn = p => p.locator('.eil-wrap').count();
  const out = p => p.evaluate(() => window.__out);
  let errs = await run({}, async (p, pings) => {
    await adv(p, 300);
    t('1 at start: one ping that only asks, then one that says the app was opened (use), both with the login', pings.length >= 2 && pings[0].active === false && pings[1].active === true && pings[0].auth === 'Bearer T', JSON.stringify(pings));
    await adv(p, 3 * 60000 + 30000);
    t('2 3.5 minutes without use (limit 5): no warning yet', (await warn(p)) === 0);
    await adv(p, 40000);
    t('3 about 4 minutes: the warning with the countdown appears', (await warn(p)) === 1 && /Are you still there/.test(await p.innerText('.eil-card')), await p.innerText('.eil-card').catch(() => ''));
    const n = parseInt(await p.innerText('#eil-n'), 10); t('3 the countdown shows the seconds left (about 50)', n > 40 && n <= 60, n);
    await adv(p, 10000); const n2 = parseInt(await p.innerText('#eil-n'), 10); t('3 and it counts down', n2 < n, n + ' -> ' + n2);
    const before = pings.length; await p.click('#eil-stay'); await adv(p, 200);
    t('4 "Stay signed in": the warning goes and the server is told the app was used', (await warn(p)) === 0 && pings.length > before && pings[pings.length - 1].active === true, JSON.stringify(pings.slice(-2)));
    await adv(p, 3 * 60000);
    t('4 3 minutes later: still signed in and no warning (the clock restarted)', (await out(p)) === 0 && (await warn(p)) === 0);
    await adv(p, 2 * 60000 + 5000);
    t('5 5 minutes without use: signed out (Auth.logout ran once)', (await out(p)) === 1, await out(p));
    t('5 the reason is left for the sign-in page', (await p.evaluate(() => sessionStorage.getItem('etel_logout_reason'))) === 'idle');
  });
  t('5 no page errors', errs.length === 0, errs.join('|'));
  await run({}, async (p) => {
    await adv(p, 3 * 60000); await p.click('#b'); await adv(p, 3 * 60000);
    t('6 a tap after 3 minutes restarts the clock (no warning after 3 more)', (await warn(p)) === 0 && (await out(p)) === 0);
    await p.keyboard.press('a'); await adv(p, 3 * 60000 + 30000);
    t('6 a key press restarts the clock', (await warn(p)) === 0 && (await out(p)) === 0, 'warn=' + (await warn(p)) + ' out=' + (await out(p)));
    await p.evaluate(() => { document.dispatchEvent(new Event('scroll')); }); await adv(p, 3 * 60000 + 30000);
    t('6 a scroll restarts it too', (await warn(p)) === 0 && (await out(p)) === 0, 'warn=' + (await warn(p)) + ' out=' + (await out(p)));
    await p.evaluate(() => { document.dispatchEvent(new WheelEvent('wheel')); }); await adv(p, 3 * 60000 + 30000);
    t('6 a mouse wheel restarts it too', (await warn(p)) === 0 && (await out(p)) === 0, 'warn=' + (await warn(p)) + ' out=' + (await out(p)));
  });
  await run({}, async (p, pings) => {
    await adv(p, 300); for (let i = 0; i < 10; i++) { await p.click('#b'); await adv(p, 3000); }
    const act = pings.filter(x => x.active === true).length;
    t('7 many taps do not flood the server (at most a ping every 2 minutes)', act <= 3, act);
  });
  await run({ exempt: true }, async (p) => { await adv(p, 300); await adv(p, 20 * 60000);
    t('8 the server says the person is working (courier online / driver online): never signed out', (await out(p)) === 0 && (await warn(p)) === 0); });
  await run({ token: 'courier', extra: 'window.COURIER_IS_ONLINE = true;' }, async (p) => { await adv(p, 300); await adv(p, 20 * 60000);
    t('9 a courier app that is online is never signed out', (await out(p)) === 0 && (await warn(p)) === 0); });
  await run({ token: 'courier', extra: "document.getElementById('active-content').innerHTML = '<div class=\"job-card\">job</div>';" }, async (p) => { await adv(p, 300); await adv(p, 20 * 60000);
    t('10 a courier with a job on the Active tab is never signed out', (await out(p)) === 0); });
  await run({ limit: 0 }, async (p) => { await adv(p, 300); await adv(p, 30 * 60000);
    t('11 limit 0 = never sign out', (await out(p)) === 0); });
  await run({ last: 10 * 60000 }, async (p) => { await adv(p, 600);
    t('12 a phone reopened after 10 minutes (limit 5) is signed out at once', (await out(p)) === 1, await out(p)); });
  await run({ last: 2 * 60000 }, async (p) => { await adv(p, 600);
    t('13 reopened after 2 minutes: fine', (await out(p)) === 0); });
  await run({ expired: true }, async (p) => { await adv(p, 600);
    t('14 the server says the session already ended (idle_expired): signed out at once', (await out(p)) === 1, await out(p)); });
  await run({ token: '' }, async (p, pings) => { await adv(p, 20 * 60000);
    t('15 not signed in (the sign-in page): nothing happens, no ping', pings.length === 0 && (await out(p)) === 0, JSON.stringify(pings)); });
  await run({}, async (p) => {
    await adv(p, 3 * 60000); await p.evaluate(() => { localStorage.setItem('etel_last_active', String(Date.now())); localStorage.setItem('etel_last_active_tok', 'T'); }); await adv(p, 3 * 60000);
    t('16 use in another tab of the same phone counts (shared clock)', (await warn(p)) === 0 && (await out(p)) === 0); });
  /* signing in again after an idle sign-out must not be thrown out by the OLD last-use time (the bug: sign in -> sign out at once -> login page, over and over) */
  await run({ lastStale: 3 * 3600000 }, async (p) => { await adv(p, 600);
    t('17 a last-use time left by an EARLIER sign-in (3 hours old, other sign-in) is ignored: the new sign-in opens normally', (await out(p)) === 0 && (await warn(p)) === 0, await out(p)); });
  await run({}, async (p) => { await adv(p, 5 * 60000 + 6000);
    t('18 an idle sign-out forgets the last-use time (both keys), so the next sign-in starts a fresh clock', (await out(p)) === 1 && await p.evaluate(() => localStorage.getItem('etel_last_active') === null && localStorage.getItem('etel_last_active_tok') === null)); });
  await run({ token: '', lastStale: 3 * 3600000 }, async (p) => {
    await p.goto(HOST + '/login.html'); await p.waitForTimeout(200);
    t('19 the sign-in page clears a leftover last-use time', await p.evaluate(() => localStorage.getItem('etel_last_active') === null && localStorage.getItem('etel_last_active_tok') === null)); });
  await run({ lang: 'am' }, async (p) => { await adv(p, 4 * 60000 + 10000);
    t('17 the warning is in Amharic when the app is', /አሁንም እዚያ ነዎት/.test(await p.innerText('.eil-card')) && /ገብቼ ልቆይ/.test(await p.innerText('.eil-card')), await p.innerText('.eil-card').catch(() => '')); });
  // the sign-in page says why
  { const ctx = await b.newContext({ serviceWorkers: 'block' }); const p = await ctx.newPage();
    await p.goto(HOST + '/login.html'); await p.evaluate(() => sessionStorage.setItem('etel_logout_reason', 'idle')); await p.reload(); await p.waitForTimeout(200);
    t('18 the sign-in page says the app was not used', /signed out because the app was not used/.test(await p.innerText('#etel-idle-note')));
    await p.click('#etel-idle-note button'); t('18 and the note can be closed', (await p.locator('#etel-idle-note').count()) === 0);
    await p.reload(); await p.waitForTimeout(200); t('18 and it is shown only once', (await p.locator('#etel-idle-note').count()) === 0); await ctx.close(); }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
