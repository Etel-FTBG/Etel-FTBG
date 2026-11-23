// Admin > Couriers: the courier drawer (js/admin-courier-drawer.js) and the vehicle filter, on the real admin.html with a mocked API.
// Needs Playwright (node). No database, no other server needed.   node delivery/tests/ui_admin_courier_drawer.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(''); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
const CLASSES = [
  { class: 'bike', label: 'Bicycle', icon: '\u{1F6B2}', rank: 1, active: true, max_weight_kg: 5, names: ['bicycle'], builtin: true, couriers: 0, online: 0, price_5km: 100 },
  { class: 'moto', label: 'Motorbike', icon: '\u{1F6F5}', rank: 2, active: true, max_weight_kg: 25, names: ['motorcycle'], builtin: true, couriers: 6, online: 2, price_5km: 100 },
  { class: 'car', label: 'Car', icon: '\u{1F697}', rank: 3, active: true, max_weight_kg: 150, names: ['car'], builtin: true, couriers: 1, online: 1, price_5km: 150 }];
const CHOICES = [{ value: 'motorcycle', label: 'Motorcycle', icon: '\u{1F3CD}', class: 'moto', class_label: 'Motorbike' }, { value: 'car', label: 'Car', icon: '\u{1F697}', class: 'car', class_label: 'Car' }, { value: 'bajaj', label: 'Bajaj', icon: '\u{1F6FA}', class: '', class_label: '' }];
const ROWS = [
  { id: 1, user_id: 11, courier_code: 'C-00001', name: 'Etel Admin', phone: '0924445206', status: 'online', vehicle_type: '', vehicle_class: 'moto', plate_number: '1 SD 10181', zone: 'Atote', rating: 0, total_deliveries: 0, active: 1, account_active: 1, approval_status: 'approved', today_deliveries: 0, live_jobs: 0, created_at: '2026-10-27 16:51:00' },
  { id: 2, user_id: 12, courier_code: 'C-00002', name: 'Bajaj Abel', phone: '0911000002', status: 'offline', vehicle_type: 'bajaj', vehicle_class: '', plate_number: '', zone: '', rating: 4.6, total_deliveries: 31, active: 1, account_active: 1, approval_status: 'approved', today_deliveries: 2, live_jobs: 0, created_at: '2026-10-28 10:00:00' }];
const detail = (id) => { const c = ROWS.filter(r => r.id === id)[0]; return { vehicle_class: c.vehicle_class, vehicle_choices: CHOICES, vehicles: { classes: CLASSES, unmapped: [{ name: 'bajaj', couriers: 1, online: 0 }] },
  daily: Array.from({ length: 14 }, (_, i) => ({ d: '2026-11-' + (10 + i), n: i % 4 })), zones: ['Stadium', 'Tabor'],
  courier: Object.assign({}, c), details: null, id_types: {}, account_active: true, stats: { today: 0, d7: 4, d30: 12, cancelled_30d: 2, live: 0, avg_minutes: 24, fees_30d: 640 },
  recent: [{ order_code: 'ETD-1', status: 'delivered', fare_total: 150, from_zone: 'Stadium', to_zone: 'Tabor', placed_at: '2026-11-22 10:00:00' }] }; };
(async () => {
  await new Promise(r => srv.listen(0, r)); const HOST = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1300, h: 900 }, { n: 'mobile', w: 390, h: 844 }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(() => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin', phone: '0900000000' })); } catch (e) {} });
    const p = await ctx.newPage(); const errs = []; let saved = null;
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {};
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_couriers_board') data = { couriers: ROWS, count: 2, summary: { total: 2, online: 1, busy: 0, offline: 1, pending: 0, suspended: 0, deliveries_today: 2, avg_rating: 4.6 }, vehicles: { classes: CLASSES, unmapped: [{ name: 'bajaj', couriers: 1, online: 0 }] }, vehicle_choices: CHOICES };
      else if (a === 'admin_courier_detail') data = detail(d.id);
      else if (a === 'admin_courier_approval_mode') data = { require: false };
      else if (a === 'admin_update_courier') { saved = d; data = { message: 'Courier updated' }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1200);
    await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="couriers"]'); if (n) n.classList.remove('etel-cap-hidden'); switchPage('couriers', n); }); await p.waitForTimeout(1500);
    const opts = await p.locator('#cou-pro select option').allInnerTexts();
    t(mode.n + ': the vehicle filter lists the real package vehicles with online / registered counts', opts.some(o => /Motorbike \(2 online \/ 6\)/.test(o)) && opts.some(o => /Car \(1 online \/ 1\)/.test(o)), opts.join('|'));
    t(mode.n + ': ... and an entry for couriers whose vehicle matches no package vehicle', opts.some(o => /No package vehicle/.test(o)));
    await p.selectOption('#cou-pro select >> nth=0', { label: opts.filter(o => /No package vehicle/.test(o))[0] }).catch(() => {});
    const cards = await p.locator('#cou-pro .ak-card').count();
    t(mode.n + ': the card list shows both couriers (filter checked below)', cards >= 1);
    await p.evaluate(() => { const s = [].slice.call(document.querySelectorAll('#cou-pro select')).filter(x => /No package vehicle/.test(x.innerText))[0]; if (s) { s.value = ''; s.dispatchEvent(new Event('change', { bubbles: true })); } }); await p.waitForTimeout(300);
    await p.locator('#cou-pro [data-open="1"]').first().click(); await p.waitForTimeout(900);
    const dr = await p.innerText('.ak-dr');
    t(mode.n + ': the drawer shows the readiness ring and what is missing', /\d+%/.test(dr) && /left before this courier is ready/.test(dr), dr.slice(0, 200));
    t(mode.n + ': no vehicle saved says so, instead of "No vehicle"', /No vehicle is saved/.test(dr) && !/\bNo vehicle\b(?! is saved| chosen yet)/.test(dr), dr.slice(0, 600));
    t(mode.n + ': the vehicle card shows the package class, the 5 km price and online / registered', /Counts as Motorbike/.test(dr) && /ETB 100/.test(dr) && /2 online \/ 6 registered/.test(dr));
    t(mode.n + ': numbers: completion rate 86% (12 of 14), average time, fees', /86%/.test(dr) && /Completion/.test(dr) && /Fees \(30 d\)/.test(dr));
    t(mode.n + ': the 14-day chart, the checklist and the quick buttons are there', (await p.locator('.acd-bars i').count()) === 14 && (await p.locator('.acd-chk li').count()) >= 10 && (await p.locator('.acd-quick a[href^="tel:"]').count()) === 1 && (await p.locator('.acd-quick a[href^="https://wa.me/251924445206"]').count()) === 1);
    const vopts = await p.locator('.ak-dr select[data-f="vehicle_type"] option').allInnerTexts();
    t(mode.n + ': the vehicle choices are the real types, grouped by package vehicle', vopts.some(o => /Motorcycle/.test(o)) && vopts.some(o => /Bajaj/.test(o)) && (await p.locator('.ak-dr select optgroup').count()) >= 2, vopts.join('|'));
    await p.screenshot({ path: SHOTS + '/courier_drawer_' + mode.n + '.png' }); await p.evaluate(() => { const b = document.querySelector('.ak-dr-body'); b.scrollTop = 620; }); await p.waitForTimeout(200); await p.screenshot({ path: SHOTS + '/courier_drawer_' + mode.n + '_2.png' }); await p.evaluate(() => { const b = document.querySelector('.ak-dr-body'); b.scrollTop = 1300; }); await p.waitForTimeout(200); await p.screenshot({ path: SHOTS + '/courier_drawer_' + mode.n + '_3.png' });
    await p.selectOption('.ak-dr select[data-f="vehicle_type"]', 'car'); await p.locator('.ak-dr [data-save]').click(); await p.waitForTimeout(500);
    t(mode.n + ': saving sends the chosen vehicle', saved && saved.vehicle_type === 'car' && saved.courier_id === 1, JSON.stringify(saved));
    // a courier whose vehicle matches no package vehicle gets a clear warning
    await p.evaluate(() => { const x = document.querySelector('.ak-x'); if (x) x.click(); }); await p.waitForTimeout(400);
    await p.locator('#cou-pro [data-open="2"]').first().click(); await p.waitForTimeout(900);
    const d2 = await p.innerText('.ak-dr');
    t(mode.n + ': a bajaj courier is told they get no package jobs and where to fix it', /does not match any package vehicle/.test(d2) && /Package prices/.test(d2), d2.slice(0, 500));
    t(mode.n + ': no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
