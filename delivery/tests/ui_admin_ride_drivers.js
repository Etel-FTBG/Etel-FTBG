// Admin > Ride > Drivers (js/admin-ride-drivers.js): counts, filters, search, suspend / let drive, add driver, live map (fake Google) and the list fallback. Real admin.html, mocked API.
// Needs Playwright (node).   node delivery/tests/ui_admin_ride_drivers.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(''); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
const DRIVERS = () => [
  { id: 1, name: 'Abebe Kebede', phone: '+251911000001', online: 1, onboarded: 1, identity_match: 'matched', vehicle_type: 'Bajaj', vehicle_plate: 'AA 1111', total_rides: 40, rating: 4.8, last_seen: '2026-11-23 10:00:00' },
  { id: 2, name: 'Bekele Tola', phone: '+251911000002', online: 0, onboarded: 0, identity_match: 'pending', vehicle_type: 'Motorcycle', vehicle_plate: '', total_rides: 0, rating: 0, last_seen: null },
  { id: 3, name: 'Chala Dinku', phone: '+251911000003', online: 0, onboarded: 1, identity_match: 'mismatch', vehicle_type: 'Car', vehicle_plate: 'CC 3', total_rides: 3, rating: 3.1, last_seen: '2026-11-20 10:00:00' },
  { id: 4, name: 'Dawit Alemu', phone: '+251911000004', online: 0, onboarded: 1, identity_match: 'matched', vehicle_type: 'Bajaj', vehicle_plate: 'DD 4', total_rides: 9, rating: 4.1, last_seen: '2026-11-01 10:00:00' }];
(async () => {
  await new Promise(r => srv.listen(0, r)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1300, h: 900, maps: true }, { n: 'mobile', w: 390, h: 844, maps: false }]) {
    const calls = []; let SUS = [{ user_id: 4, name: 'Dawit Alemu', phone: '+251911000004', suspended_at: '2026-11-22 09:00:00', suspended_reason: 'Rude' }];
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript((maps) => {
      window.ETEL_CAPS = ['*']; window.__regOpened = 0; window.__markers = 0;
      try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin' })); } catch (e) {}
      Object.defineProperty(window, 'EtelDriverRegister', { configurable: true, writable: true, value: { open: function () { window.__regOpened++; }, css: function () {} } });
    }, mode.maps);
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message + ' @' + String(e.stack || '').split('\n')[1]); });
    await p.route('**/api/boot.php**', r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {}, ok = true, msg = '';
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_get_drivers') data = { drivers: DRIVERS() };
      else if (a === 'admin_get_suspended_drivers') data = { suspended: SUS };
      else if (a === 'admin_get_live_drivers') data = { drivers: [{ user_id: 1, name: 'Abebe Kebede', lat: 7.06, lng: 38.47, vehicle_type: 'Bajaj', vehicle_plate: 'AA 1111' }] };
      else if (a === 'admin_suspend_driver') { calls.push(d); SUS.push({ user_id: d.user_id, suspended_at: '2026-11-23 12:00:00', suspended_reason: d.reason }); }
      else if (a === 'admin_unsuspend_driver') { calls.push(d); SUS = SUS.filter(x => x.user_id !== d.user_id); }
      else if (a === 'admin_live_ops') data = { now: {}, speed: {} };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify(ok ? { success: true, data } : { success: false, message: msg }) }); });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(2300);
    await p.evaluate((maps) => {
      window.loadGoogleMaps = function (libs, cb) {
        if (!maps) { setTimeout(function () { document.dispatchEvent(new CustomEvent('etel:maps-failed', { detail: { reason: 'test' } })); }, 50); return; }
        window.google = { maps: { Map: function () { this.setCenter = function () {}; this.setZoom = function () {}; this.fitBounds = function () {}; }, Marker: function () { window.__markers++; this.setMap = function () {}; },
          LatLngBounds: function () { this.extend = function () {}; }, SymbolPath: { CIRCLE: 0 } } };
        setTimeout(function () { window[cb](); }, 30);
      };
    }, mode.maps);
    const n = mode.n;
    t(n + ': Drivers is a tile in the Ride workspace and not in Delivery', await p.evaluate(() => { AdminWS.show('ride'); return document.querySelector('#panel-wshome .aws-tile[data-go="ridedrivers"]') !== null; }));
    await p.waitForTimeout(300); await p.evaluate(() => { AdminWS.show('delivery'); }); await p.waitForTimeout(300);
    t(n + ': ... and not on the Delivery home', (await p.locator('#panel-wshome .aws-tile[data-go="ridedrivers"]').count()) === 0);
    await p.evaluate(() => { switchPage('ridedrivers', document.querySelector('.nav-item[data-page="ridedrivers"]')); }); await p.waitForTimeout(1200);
    const txt = await p.innerText('#panel-ridedrivers');
    t(n + ': four drivers are listed with their states', ['Abebe Kebede', 'Bekele Tola', 'Chala Dinku', 'Dawit Alemu'].every(x => txt.includes(x)) && /Waiting for approval/.test(txt) && /ID does not match/.test(txt) && /Suspended/.test(txt) && /Online/.test(txt), txt.slice(0, 400));
    t(n + ': the suspended reason shows', /Rude/.test(txt));
    await p.locator('#panel-ridedrivers [data-kpi="pending"]').click(); await p.waitForTimeout(200);
    t(n + ': the Waiting filter shows only the driver waiting for approval', (await p.locator('#panel-ridedrivers .rdv-card').count()) === 1 && /Bekele Tola/.test(await p.innerText('#panel-ridedrivers [data-list]')));
    await p.locator('#panel-ridedrivers [data-kpi="all"]').click(); await p.waitForTimeout(200);
    await p.fill('#panel-ridedrivers [data-q]', '0003'); await p.waitForTimeout(200);
    t(n + ': search by the end of a phone number finds one driver', (await p.locator('#panel-ridedrivers .rdv-card').count()) === 1 && /Chala/.test(await p.innerText('#panel-ridedrivers [data-list]')));
    await p.fill('#panel-ridedrivers [data-q]', 'zzz'); await p.waitForTimeout(200);
    t(n + ': a search with no result says so', /No driver matches/.test(await p.innerText('#panel-ridedrivers [data-list]')));
    await p.fill('#panel-ridedrivers [data-q]', ''); await p.waitForTimeout(200);
    await p.locator('#panel-ridedrivers .rdv-card[data-uid="1"] [data-sus]').click(); await p.waitForTimeout(250);
    await p.fill('.ak-modal [data-reason]', 'Fake GPS'); await p.locator('.ak-modal [data-yes]').click(); await p.waitForTimeout(500);
    t(n + ': suspending asks first, sends the reason and shows Suspended', calls.some(c => c.user_id === 1 && c.reason === 'Fake GPS') && /Let drive again/.test(await p.innerText('#panel-ridedrivers .rdv-card[data-uid="1"]')));
    await p.locator('#panel-ridedrivers .rdv-card[data-uid="4"] [data-unsus]').click(); await p.waitForTimeout(250); await p.locator('.ak-modal [data-yes]').click(); await p.waitForTimeout(500);
    t(n + ': letting a driver drive again sends it and the button flips', calls.some(c => c.user_id === 4 && !c.reason) && /Suspend/.test(await p.innerText('#panel-ridedrivers .rdv-card[data-uid="4"]')) && !/Let drive again/.test(await p.innerText('#panel-ridedrivers .rdv-card[data-uid="4"]')));
    await p.locator('#panel-ridedrivers [data-add]').click(); await p.waitForTimeout(150);
    t(n + ': Add driver opens the same driver steps as the Driver desk', (await p.evaluate(() => window.__regOpened)) === 1);
    if (mode.maps) t(n + ': the live map puts a marker for each online driver', (await p.evaluate(() => window.__markers)) >= 1, await p.evaluate(() => window.__markers));
    else t(n + ': when the map cannot load, the online drivers show as a list', /Abebe Kebede/.test(await p.innerText('#panel-ridedrivers [data-map]')) && /map could not load/.test(await p.innerText('#panel-ridedrivers [data-map-note]')), await p.innerText('#panel-ridedrivers [data-map-note]'));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/ride_drivers_' + n + '.png' });
    t(n + ': no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
