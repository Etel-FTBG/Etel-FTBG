// Admin > Ride > Rides: the "System health" and "Live trip watch" cards at the top must be drawn (they were not, since 24 Oct: a comment inside their box made the code think they were
// already built). Real admin.html, mocked API.   node delivery/tests/ui_rides_health_card.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
(async () => {
  await new Promise(r => srv.listen(0, r)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'phone', w: 390, h: 844 }, { n: 'desktop', w: 1300, h: 900 }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(() => { window.ETEL_CAPS = ['*']; localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin' })); });
    const p = await ctx.newPage(); const errs = []; const calls = [];
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} calls.push(d.action); let data = {};
      if (d.action === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (d.action === 'admin_system_health') data = { overall: 'ok', checks: [{ name: 'Cron job', status: 'ok', detail: 'Ran 2 min ago' }, { name: 'Backups', status: 'warn', detail: 'No backup yet' }] };
      else data = { rides: [], issues: [], changes: [], flagged: [], requests: [], drivers: [], findings: [], checks: [], items: [], list: [], total: 0, stats: {}, counts: {}, watch: { findings: [] } };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) }); });
    await p.goto(HOST + '/delivery/admin.html'); await p.waitForTimeout(2500);
    await p.locator('#aws-switch .aws-chip[data-ws="ride"]').click(); await p.waitForTimeout(400);
    await p.locator('#panel-wshome .aws-tile[data-go="rides"]').click(); await p.waitForTimeout(1500);
    const n = mode.n;
    t(n + ': the System health card is drawn and on screen', await p.evaluate(() => { const e = document.getElementById('rdk-health'); if (!e) return false; const r = e.getBoundingClientRect(); return r.width > 50 && r.height > 20 && getComputedStyle(e).display !== 'none'; }));
    const txt = await p.evaluate(() => (document.getElementById('rdk-health') || {}).innerText || '');
    t(n + ': it names the checks (Cron job, Backups)', /Cron job/.test(txt) && /Backups/.test(txt), txt.slice(0, 120));
    t(n + ': it has a Refresh button that asks the server again', await (async () => { const before = calls.filter(a => a === 'admin_system_health').length; await p.locator('#rdk-health [data-pulse="health"]').click(); await p.waitForTimeout(600); return calls.filter(a => a === 'admin_system_health').length > before; })());
    t(n + ': the Live trip watch card is drawn too', await p.evaluate(() => !!document.getElementById('rdk-watch') || /Live trip watch/.test((document.getElementById('rides-ops-pro') || {}).innerText || '')));
    t(n + ': no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED (' + fails.length + '):\n' + fails.join('\n') : 'ok: ' + pass + ' checks passed'); process.exit(fails.length ? 1 : 0);
})();
