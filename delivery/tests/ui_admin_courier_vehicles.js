// Admin > Couriers: the "no vehicle chosen" bar and the Fix vehicles sheet (js/admin-courier-vehicles.js). Real admin.html, mocked API.
// Needs Playwright (node).   node delivery/tests/ui_admin_courier_vehicles.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(''); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
const CH = [{ value: 'motorcycle', label: 'Motorcycle', icon: '\u{1F3CD}', class: 'moto', class_label: 'Motorbike' }, { value: 'car', label: 'Car', icon: '\u{1F697}', class: 'car', class_label: 'Car' }, { value: 'qute', label: 'Qute', icon: '\u{1F6FA}', class: 'x1', class_label: 'Bajaj / Qute' }, { value: 'bajaj', label: 'Bajaj', icon: '\u{1F6FA}', class: 'x1', class_label: 'Bajaj / Qute' }];
const mk = (id, name, veh, rv) => ({ id, user_id: 10 + id, courier_code: 'C-0000' + id, name, phone: '09110000' + id, status: 'offline', vehicle_type: veh, vehicle_class: veh ? 'moto' : '', plate_number: '', zone: '', rating: 0, total_deliveries: 0, active: 1, account_active: 1, approval_status: 'approved', today_deliveries: 0, live_jobs: 0, created_at: '2026-10-28 10:00:00', ride_vehicle: rv || null, ride_driver: !!rv });
let ROWS = [];
(async () => {
  await new Promise(r => srv.listen(0, r)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1300, h: 900 }, { n: 'mobile', w: 390, h: 844 }]) {
    ROWS = [mk(1, 'Abel Bajaj', '', 'qute'), mk(2, 'Sara Car', 'car', null), mk(3, 'Dawit None', '', null)];
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(() => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin' })); } catch (e) {} });
    const p = await ctx.newPage(); const errs = []; const saves = []; let ignore = false;
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {};
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_couriers_board') data = { couriers: ROWS, count: ROWS.length, summary: { total: ROWS.length, online: 0, busy: 0, offline: 3, pending: 0, suspended: 0, deliveries_today: 0, avg_rating: null }, vehicles: { classes: [], unmapped: [] }, vehicle_choices: CH };
      else if (a === 'admin_courier_detail') { const c = ROWS.filter(x => x.id === d.id)[0]; data = { courier: c, vehicle_choices: CH, vehicles: { classes: [] }, daily: [], zones: [], details: null, id_types: {}, account_active: true, stats: {}, recent: [] }; }
      else if (a === 'admin_courier_approval_mode') data = { require: false };
      else if (a === 'admin_update_courier') { saves.push(d); if (!ignore) ROWS.filter(x => x.id === d.courier_id).forEach(x => { x.vehicle_type = d.vehicle_type; }); data = { message: 'Courier updated' }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) }); });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1200);
    await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="couriers"]'); if (n) n.classList.remove('etel-cap-hidden'); switchPage('couriers', n); }); await p.waitForTimeout(1800);
    const n = mode.n;
    t(n + ': a bar says how many couriers have no vehicle', /2 couriers have no vehicle chosen/.test(await p.innerText('#cnv-bar')), await p.innerText('#cou-pro').then(x => x.slice(0, 200)));
    await p.locator('#cnv-bar [data-cnv-open]').click(); await p.waitForTimeout(500);
    t(n + ': the sheet lists only those two couriers, with the real vehicle pictures', (await p.locator('.cnv-row').count()) === 2 && (await p.locator('.cnv-row').first().locator('img.etel-vpic').count()) >= 2);
    t(n + ': a courier who is also a ride driver shows the ride vehicle and a one-tap suggestion', /Ride driver: Qute/i.test(await p.innerText('.cnv-row >> nth=0'))  && (await p.locator('.cnv-row >> nth=0 >> [data-use="qute"]').count()) === 1);
    t(n + ': Save is off until a vehicle is tapped', await p.locator('.cnv-row >> nth=0 >> [data-save]').isDisabled());
    await p.locator('.cnv-row >> nth=0 >> [data-use="qute"]').click();
    t(n + ': the suggestion selects Qute and turns Save on', (await p.getAttribute('.cnv-row >> nth=0 >> [data-vt="qute"]', 'aria-pressed')) === 'true' && !(await p.locator('.cnv-row >> nth=0 >> [data-save]').isDisabled()));
    await p.locator('.cnv-row >> nth=0 >> [data-save]').click(); await p.waitForTimeout(1300);
    t(n + ': saving sends the courier and the vehicle, and the row leaves the list', saves.length === 1 && saves[0].courier_id === 1 && saves[0].vehicle_type === 'qute' && (await p.locator('.cnv-row').count()) === 1, JSON.stringify(saves));
    ignore = true; await p.locator('.cnv-row >> nth=0 >> [data-vt="bajaj"]').click(); await p.locator('.cnv-row >> nth=0 >> [data-save]').click(); await p.waitForTimeout(700);
    t(n + ': if the server keeps another vehicle the row says so (and stays)', /still|kept/.test(await p.innerText('.cnv-row >> nth=0 >> .st')) && (await p.locator('.cnv-row').count()) === 1, await p.innerText('.cnv-row >> nth=0 >> .st')); ignore = false;
    await p.locator('[data-all] [data-vt="car"]').click(); await p.locator('[data-all-save]').click(); await p.waitForTimeout(400);
    await p.locator('.ak-box button', { hasText: /^Apply$/ }).click(); await p.waitForTimeout(1800);
    t(n + ': "same vehicle for everyone" asks first, then sets it for the courier left', saves.some(s => s.courier_id === 3 && s.vehicle_type === 'car'), JSON.stringify(saves));
    t(n + ': when none are left the sheet says all done and the bar goes', /All done/.test(await p.innerText('.cnv-sheet header')) && (await p.locator('#cnv-bar').count()) === 0);
    t(n + ': no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
