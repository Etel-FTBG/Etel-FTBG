// The public package tracking page (track.html?c=CODE&t=TOKEN): asks the server for the public view with the code and the token (no sign-in),
// shows package wording and the steps, no price, the PIN hand-over line when delivered, and a clear message for a wrong link.
// Real page and files (track.html, js/core.js), a fake API.   node delivery/tests/ui_package_public_track.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const VIEW = { order_code: 'ETP-AB12CD', status: 'in_transit', steps: [], from_zone: 'Piassa', to_zone: 'Tabor', package_type: 'parcel', speed: 'express', vehicle_class: 'moto', courier_first_name: 'Abebe', placed_at: '2026-11-27 09:00:00', delivered_at: '' };
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  for (const mode of [{ n: 'in transit', view: VIEW }, { n: 'delivered', view: Object.assign({}, VIEW, { status: 'delivered', delivered_at: '2026-11-27 10:05:00' }) }, { n: 'wrong link', view: null }]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const p = await ctx.newPage(); const errs = []; const reqs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/maps\.googleapis|gstatic|fonts\.|cdnjs|jsdelivr/, r => r.abort());
    await p.route(/boot\.php/, r => {
      const u = r.request().url(); reqs.push({ u, h: r.request().headers() });
      if (/action=public_track_package/.test(u)) r.fulfill({ status: mode.view ? 200 : 404, contentType: 'application/json', body: JSON.stringify(mode.view ? { success: true, data: mode.view } : { success: false, message: 'We could not find this package. Check the link.' }) });
      else r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) });
    });
    await p.goto(HOST + '/track.html?c=ETP-AB12CD&t=0123456789ab'); await p.waitForTimeout(1500);
    const n = mode.n + ': ';
    const call = reqs.find(r => /public_track_package/.test(r.u));
    t(n + 'asks the public action with the code and the token, no sign-in header', !!call && /code=ETP-AB12CD/.test(call.u) && /token=0123456789ab/.test(call.u) && !call.h['authorization'], call && call.u);
    t(n + 'does not use the signed-in tracking action', !reqs.some(r => /action=track_order/.test(r.u)));
    const txt = await p.evaluate(() => document.body.innerText.replace(/\s+/g, ' '));
    if (mode.view) {
      t(n + 'shows the code, the areas and the package wording', /ETP-AB12CD/.test(txt) && /Piassa/.test(txt) && /Tabor/.test(txt) && /Package booked/.test(txt) && /On the way to the recipient/.test(txt) && !/Order Placed/.test(txt), txt.slice(0, 300));
      t(n + 'shows Express and the courier first name', /Express/.test(txt) && /courier Abebe/.test(txt));
      t(n + 'no price is shown', !/ETB/.test(txt) && !(await p.evaluate(() => { const e = document.getElementById('res-fare'); return e && e.parentNode.offsetParent !== null; })));
      const act = await p.evaluate(() => document.querySelectorAll('.step.active').length), done = await p.evaluate(() => document.querySelectorAll('.step.done').length);
      if (mode.view.status === 'in_transit') t(n + 'the on-the-way step is active, 3 before it are done', act === 1 && done === 3, act + '/' + done);
      else t(n + 'the PIN hand-over line shows with the time', /Handed over with the recipient's PIN/.test(txt) && /2026-11-27 10:05:00/.test(txt), txt.slice(-200));
      await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/track_public_' + mode.view.status + '.png' });
    } else {
      t(n + 'a wrong link shows the plain message and no package', /could not find this package/i.test(txt) && !(await p.evaluate(() => document.getElementById('result').style.display === 'block')), txt.slice(0, 200));
    }
    t(n + 'no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
