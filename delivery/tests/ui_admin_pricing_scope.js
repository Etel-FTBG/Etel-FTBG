// Admin Pricing page follows the workspace: Ride shows the ride tabs, Delivery the delivery tabs, Company all (js/admin-pricing-studio.js + admin-workspaces.js). Mocked API.
// Needs Playwright (node).   node delivery/tests/ui_admin_pricing_scope.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(''); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
(async () => {
  await new Promise(r => srv.listen(0, r)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1300, h: 900 }, { n: 'mobile', w: 390, h: 844 }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(() => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin' })); } catch (e) {} });
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {};
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_get_ride_fare_rules') data = { rules: [{ vehicle_type: 'bajaj', base_fare: 120, per_km_rate: 32, per_km_rate_open: 30, wait_rate_per_min: 3, min_fare: 120, min_fare_short_trip: 150, active: 1 }], ride_surge_multiplier: 1.3 };
      else if (a === 'admin_live_ops') data = { now: {}, speed: {} };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) }); });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(2300);
    const n = mode.n;
    const tabs = async () => (await p.locator('#pricing-studio .ps-tabs [data-tab]').allInnerTexts()).map(x => x.trim());
    const openFrom = async (ws) => { await p.evaluate((w) => { AdminWS.show(w); }, ws); await p.waitForTimeout(300); await p.evaluate(() => { switchPage('pricing', document.querySelector('.nav-item[data-page="pricing"]')); }); await p.waitForTimeout(1200); };
    await p.evaluate(() => { AdminWS.show('ride'); }); await p.waitForTimeout(300);
    t(n + ': the Ride Home names the tile Ride prices', /Ride prices/.test(await p.innerText('#panel-wshome')));
    await p.evaluate(() => { AdminWS.show('delivery'); }); await p.waitForTimeout(300);
    t(n + ': the Delivery Home names the tile Delivery prices', /Delivery prices/.test(await p.innerText('#panel-wshome')));
    await openFrom('ride'); let tb = await tabs();
    t(n + ': opened from Ride, only the ride tabs show', tb.join('|') === 'Ride fares|Peak hours|Driver goals|Commission|Incentives', tb.join('|'));
    t(n + ': ... with the title Ride prices', /Ride prices/.test(await p.innerText('#pricing-studio .ak-hero')));
    await openFrom('delivery'); tb = await tabs();
    t(n + ': opened from Delivery, only the delivery tabs show', tb.join('|') === 'Delivery|Courier pay|Bank accounts', tb.join('|'));
    t(n + ': ... with the title Delivery prices and no ride numbers', /Delivery prices/.test(await p.innerText('#pricing-studio .ak-hero')) && !/Peak windows/.test(await p.innerText('#pricing-studio .ak-hero')));
    await openFrom('ride'); tb = await tabs();
    t(n + ': going back to Ride shows the ride tabs again and a ride tab is selected', tb.length === 5 && (await p.locator('#pricing-studio .ps-tabs [data-tab][aria-pressed="true"]').count()) === 1);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pricing_scope_' + n + '.png' });
    t(n + ': no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
