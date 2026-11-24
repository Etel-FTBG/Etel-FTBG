// Courier app (js/courier-vehicle-marker.js): the courier's own pin on the map is the REAL picture of their vehicle, not a green dot or an emoji, and it stays
// that way when the app redraws its pin. Real script and pictures, a plain test page with a stand-in pin.   node delivery/tests/ui_courier_vehicle_marker.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.js': 'application/javascript; charset=utf-8', '.png': 'image/png' };
const page = (cpVehicle) => `<!doctype html><html><body>${cpVehicle === null ? '' : '<span id="cp-vehicle">' + cpVehicle + '</span>'}<script>let courierMarker = { content: document.createElement('div') }; window.__orig = courierMarker.content; window.__orig.textContent = 'dot';</script>
<script src="/js/etel-vehicle-pic.js"></script><script src="/js/courier-vehicle-marker.js"></script></body></html>`;
let HTML = '';
const srv = http.createServer((q, r) => { const u = q.url.split('?')[0]; if (u === '/t.html') { r.setHeader('Content-Type', 'text/html; charset=utf-8'); return r.end(HTML); } const f = path.join(ROOT, decodeURIComponent(u).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/t.html';
  const b = await chromium.launch();
  const open = async (cp, extra) => { HTML = page(cp); const ctx = await b.newContext({ serviceWorkers: 'block' }); await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); });
    const p = await ctx.newPage(); p.errs = []; p.on('pageerror', e => p.errs.push(e.message)); if (extra) await extra(p); await p.goto(PAGE); return p; };
  const pic = (p) => p.evaluate(() => { const c = courierMarker.content; const i = c && c.querySelector && c.querySelector('img'); return i ? i.src : ''; });
  let p = await open('qute'); await p.waitForTimeout(1800);
  t('1 the pin becomes the real picture of the courier\'s vehicle (qute)', /img\/vehicles\/qute\.png/.test(await pic(p)), await pic(p));
  t('1 it is a picture, not the old dot', (await p.evaluate(() => courierMarker.content !== window.__orig)));
  await p.evaluate(() => { const e = document.createElement('div'); e.textContent = '🚺'; courierMarker.content = e; });   // the app redraws its own pin
  await p.waitForTimeout(1800);
  t('2 when the app redraws its pin the real picture comes back', /qute\.png/.test(await pic(p)));
  t('2 no page errors', p.errs.length === 0, p.errs.join('|')); await p.context().close();
  p = await open('motorcycle'); await p.waitForTimeout(1800);
  t('3 a motorcycle shows the motorcycle picture', /img\/vehicles\/moto\.png/.test(await pic(p)), await pic(p)); await p.context().close();
  p = await open('—', async (pp) => { await pp.route(/boot\.php/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { vehicle_type: 'car' } }) })); }); await p.waitForTimeout(2800);
  t('4 profile row empty: the vehicle is asked from the profile and the car picture shows', /img\/vehicles\/car\.png/.test(await pic(p)), await pic(p)); await p.context().close();
  p = await open('spaceship'); await p.waitForTimeout(1800);
  t('5 a vehicle with no picture keeps what the app drew', (await p.evaluate(() => courierMarker.content === window.__orig))); await p.context().close();
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
