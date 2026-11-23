// Admin > Ride > Special offers (js/admin-ride-offers.js): rules, special areas, validation messages. Real admin.html, mocked API.
// Needs Playwright (node).   node delivery/tests/ui_admin_ride_offers.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(''); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
(async () => {
  await new Promise(r => srv.listen(0, r)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1300, h: 900 }, { n: 'mobile', w: 390, h: 844 }]) {
    let SET = { ride_pull_on: 1, ride_pull_after_secs: 20, ride_pull_min_km: 2, ride_pull_max_km: 25, ride_vip_min_fare: 350 }; let AREAS = [{ id: 1, name: 'Piassa market', kind: 'sweet', lat: 7.0606, lng: 38.4775, radius_km: 1, note: 'Busy at lunch', active: 1 }]; const calls = [];
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(() => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin' })); } catch (e) {} });
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {}, ok = true, msg = '';
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_ride_offers_get') data = { settings: SET, areas: AREAS };
      else if (a === 'admin_ride_offers_save') { calls.push(d); if (Number(d.ride_pull_max_km) <= Number(d.ride_pull_min_km)) { ok = false; msg = 'The farthest distance must be more than the nearest.'; } else { SET = Object.assign({}, SET, d); delete SET.action; data = { message: 'Saved.', settings: SET }; } }
      else if (a === 'admin_ride_area_save') { calls.push(d); if (!d.name) { ok = false; msg = 'Give the area a name.'; } else { const id = d.id || 2; AREAS = AREAS.filter(x => x.id !== id).concat([Object.assign({}, d, { id, lat: Number(d.lat), lng: Number(d.lng), radius_km: Number(d.radius_km) })]); data = { id, areas: AREAS }; } }
      else if (a === 'admin_ride_area_delete') { calls.push(d); AREAS = AREAS.filter(x => x.id !== d.id); data = { areas: AREAS }; }
      else if (a === 'admin_live_ops') data = { now: {}, speed: {} };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify(ok ? { success: true, data } : { success: false, message: msg }) }); });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(2300);
    const n = mode.n;
    t(n + ': Special offers is in the Ride workspace and not in Delivery', await p.evaluate(() => { AdminWS.show('ride'); return document.querySelector('#panel-wshome .aws-tile[data-go="rideoffers"]') !== null; }));
    await p.waitForTimeout(300); await p.evaluate(() => { AdminWS.show('delivery'); }); await p.waitForTimeout(300);
    t(n + ': ... and not on the Delivery home', (await p.locator('#panel-wshome .aws-tile[data-go="rideoffers"]').count()) === 0);
    await p.evaluate(() => { const nv = document.querySelector('.nav-item[data-page="rideoffers"]'); switchPage('rideoffers', nv); }); await p.waitForTimeout(900);
    const txt = await p.innerText('#panel-rideoffers');
    t(n + ': the page shows the rules with the current numbers and the area', /Pull tab rules/.test(txt) && (await p.inputValue('#panel-rideoffers [name="ride_vip_min_fare"]')) === '350' && /Piassa market/.test(txt) && /Busy at lunch/.test(txt), txt.slice(0, 300));
    await p.fill('#panel-rideoffers [name="ride_pull_after_secs"]', '45'); await p.fill('#panel-rideoffers [name="ride_vip_min_fare"]', '500');
    await p.locator('#panel-rideoffers form[data-settings] button[type=submit]').click(); await p.waitForTimeout(500);
    t(n + ': saving the rules sends the numbers', calls.some(c => c.action === 'admin_ride_offers_save' && String(c.ride_pull_after_secs) === '45' && String(c.ride_vip_min_fare) === '500' && c.ride_pull_on === 1), JSON.stringify(calls));
    await p.fill('#panel-rideoffers [name="ride_pull_max_km"]', '1'); await p.locator('#panel-rideoffers form[data-settings] button[type=submit]').click(); await p.waitForTimeout(500);
    t(n + ': a refused rule shows the server message in words', /farthest distance must be more than the nearest/.test(await p.innerText('#panel-rideoffers form[data-settings] [data-note]')));
    await p.locator('#panel-rideoffers [data-add]').click(); await p.waitForTimeout(200);
    await p.selectOption('#panel-rideoffers form[data-area] [data-zone]', { label: 'Tabor' });
    t(n + ': choosing a Hawassa zone fills the centre and the name', (await p.inputValue('#panel-rideoffers form[data-area] [name="lat"]')) === '7.04' && (await p.inputValue('#panel-rideoffers form[data-area] [name="name"]')) === 'Tabor');
    await p.fill('#panel-rideoffers form[data-area] [name="name"]', ''); await p.locator('#panel-rideoffers form[data-area] button[type=submit]').click(); await p.waitForTimeout(400);
    t(n + ': an area without a name shows the message', /Give the area a name/.test(await p.innerText('#panel-rideoffers form[data-area] [data-note]')));
    await p.fill('#panel-rideoffers form[data-area] [name="name"]', 'Tabor gate'); await p.selectOption('#panel-rideoffers form[data-area] [name="kind"]', 'vip'); await p.fill('#panel-rideoffers form[data-area] [name="note"]', 'Rich customers');
    await p.locator('#panel-rideoffers form[data-area] button[type=submit]').click(); await p.waitForTimeout(600);
    t(n + ': saving an area sends it and lists it (VIP kind)', calls.some(c => c.action === 'admin_ride_area_save' && c.name === 'Tabor gate' && c.kind === 'vip' && c.note === 'Rich customers') && /Tabor gate/.test(await p.innerText('#panel-rideoffers')) && /VIP area/.test(await p.innerText('#panel-rideoffers')));
    await p.locator('#panel-rideoffers [data-del="1"]').click(); await p.waitForTimeout(300); await p.locator('.ak-box button', { hasText: /^Delete$/ }).click(); await p.waitForTimeout(600);
    t(n + ': deleting asks first, then removes the area', !/Piassa market/.test(await p.innerText('#panel-rideoffers')) && calls.some(c => c.action === 'admin_ride_area_delete' && c.id === 1));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/ride_offers_' + n + '.png' });
    t(n + ': no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
