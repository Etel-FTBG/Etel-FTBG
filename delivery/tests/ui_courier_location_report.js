// Courier app (js/courier-location-report.js): the app tells the server where the courier is, so the customer's live map moves. Every ~8 s with a job on the
// Active tab, ~30 s when only online, never when offline with no job or when the screen is hidden. Real script, plain test page, fake clock.
// Needs Playwright (node).   node delivery/tests/ui_courier_location_report.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
let HTML = '';
const page = (o) => `<!doctype html><html><body><div id="active-content">${o.job ? '<div class="job-card">job</div>' : ''}</div>
<script>let courierLat = ${o.lat}, courierLng = ${o.lng}; let COURIER_IS_ONLINE = ${o.online};</script><script src="/js/courier-location-report.js"></script></body></html>`;
const srv = http.createServer((q, r) => { const u = q.url.split('?')[0]; if (u === '/t.html') { r.setHeader('Content-Type', 'text/html; charset=utf-8'); return r.end(HTML); } fs.readFile(path.join(ROOT, u), (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', 'application/javascript'); r.end(d); } }); });
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/t.html';
  const b = await chromium.launch();
  const run = async (o, steps) => {
    HTML = page(o); const ctx = await b.newContext({ serviceWorkers: 'block' }); await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); });
    const p = await ctx.newPage(); const sent = []; const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/boot\.php/, r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} sent.push({ d, auth: r.request().headers()['authorization'] }); r.fulfill({ contentType: 'application/json', body: '{"success":true,"data":{"updated":true}}' }); });
    await p.clock.install(); await p.goto(PAGE); await steps(p, sent); await ctx.close(); return errs;
  };
  const adv = async (p, ms) => { await p.clock.runFor(ms); await p.waitForTimeout(60); };
  let errs = await run({ job: true, online: true, lat: 7.05, lng: 38.48 }, async (p, sent) => {
    await adv(p, 4100);
    t('1 with a job the position is sent right away', sent.length === 1 && sent[0].d.action === 'courier_update_location' && sent[0].d.lat === 7.05 && sent[0].d.lng === 38.48, JSON.stringify(sent));
    t('1 it carries the courier login', sent[0] && sent[0].auth === 'Bearer T');
    await p.evaluate(() => { window.courierLat = 7.0501; }); await adv(p, 4000);
    t('2 standing still or moving a little: not sent again within the first seconds', sent.length === 1, sent.length);
    await p.evaluate(() => { courierLat = 7.052; courierLng = 38.482; }); await adv(p, 8000);
    t('3 after about 8 s and a move, the new position is sent', sent.length === 2 && sent[1].d.lat === 7.052, JSON.stringify(sent.map(s => s.d.lat)));
    await adv(p, 26000);
    t('4 standing still with a job: still sent at least every ~25 s (the customer needs a fresh position)', sent.length >= 3, sent.length);
  });
  t('4 no page errors', errs.length === 0, errs.join('|'));
  await run({ job: false, online: true, lat: 7.05, lng: 38.48 }, async (p, sent) => {
    await adv(p, 4100); t('5 online with no job: sent', sent.length === 1, sent.length);
    await p.evaluate(() => { courierLat = 7.06; }); await adv(p, 12000);
    t('5 ... but only about every 30 s (not every 8 s)', sent.length === 1, sent.length);
    await adv(p, 20000); t('5 ... then again after 30 s', sent.length === 2, sent.length);
  });
  await run({ job: false, online: false, lat: 7.05, lng: 38.48 }, async (p, sent) => {
    await adv(p, 40000); t('6 offline with no job: nothing is sent', sent.length === 0, sent.length);
  });
  await run({ job: true, online: true, lat: 0, lng: 0 }, async (p, sent) => {
    await adv(p, 20000); t('7 no position known yet: nothing is sent', sent.length === 0, sent.length);
  });
  await run({ job: true, online: true, lat: 95, lng: 38.48 }, async (p, sent) => {
    await adv(p, 20000); t('8 an impossible position (latitude 95) is never sent', sent.length === 0, sent.length);
  });
  await run({ job: true, online: true, lat: 7.05, lng: 38.48 }, async (p, sent) => {
    await p.evaluate(() => { Object.defineProperty(document, 'visibilityState', { get: () => 'hidden', configurable: true }); });
    await adv(p, 30000); t('9 screen hidden: nothing is sent', sent.length === 0, sent.length);
    await p.evaluate(() => { Object.defineProperty(document, 'visibilityState', { get: () => 'visible', configurable: true }); document.dispatchEvent(new Event('visibilitychange')); }); await adv(p, 200);
    t('9 back on the screen: sent at once', sent.length === 1, sent.length);
  });
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
