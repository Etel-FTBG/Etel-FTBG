// Admin > Package prices (js/admin-packages.js): the tabs, the price table, the courier supply, the "no package vehicle" warning with a one-click add.
// Needs Playwright (node). No database: the API is a mock and the real admin.html is served from this repo.   node delivery/tests/ui_admin_package_prices.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(''); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
const rate = (k, label, icon, rank, base, km, max, extra) => Object.assign({ vehicle_class: k, label, icon, base_fee: base, per_km: km, free_kg: 5, per_kg_over: 6, min_fee: 80, max_weight_kg: max, sort_order: rank, active: 1, size_rank: rank, courier_names: '' }, extra || {});
const BASE = () => [rate('bike', 'Bicycle', '\u{1F6B2}', 1, 40, 8, 5), rate('moto', 'Motorbike', '\u{1F6F5}', 2, 60, 12, 25), rate('car', 'Car', '\u{1F697}', 3, 150, 25, 150), rate('van', 'Van / pickup', '\u{1F690}', 4, 350, 45, 1000)];
let RATES = BASE();
const SET = { late_cancel_fee: 30, tier_on: 1, d1_km: 5, d1_fee: 100, d2_km: 10, d2_fee: 200, d3_km: 25, d3_fee: 300, beyond_per_km: 15, w1_kg: 5, w1_fee: 0, w2_kg: 10, w2_fee: 50, w3_kg: 20, w3_fee: 100, w4_kg: 50, w4_fee: 200, w_beyond_per_kg: 10, mult_bike: 1, mult_moto: 1, mult_car: 1.5, mult_van: 2.5 };
const cat = () => ({ classes: RATES.map(r => ({ class: r.vehicle_class, label: r.label, icon: r.icon, rank: r.size_rank, active: true, couriers: r.vehicle_class === 'moto' ? 6 : r.vehicle_class === 'car' ? 1 : 0, online: r.vehicle_class === 'moto' ? 2 : 0, max_weight_kg: r.max_weight_kg })), unmapped: [{ name: 'bajaj', couriers: 2, online: 0 }] });
(async () => {
  await new Promise(r => srv.listen(0, r)); const HOST = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1300, h: 900 }, { n: 'mobile', w: 390, h: 844 }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(() => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin', phone: '0900000000' })); } catch (e) {} });
    RATES = BASE(); const p = await ctx.newPage(); const errs = []; let added = null, saved = null;
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {};
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_package_rates_get') data = { rates: RATES, settings: SET, vehicles: cat() };
      else if (a === 'admin_package_type_add') { added = d; RATES = RATES.concat([rate('x1', d.label, d.icon, 2, d.base_fee, d.per_km, d.max_weight_kg, { courier_names: d.courier_names })]); data = { message: 'Added.', vehicle_class: 'x1', rates: RATES }; }
      else if (a === 'admin_package_rates_save') { saved = d; data = { message: 'Saved.', rates: RATES }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1200);
    await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="packages"]'); if (n) n.classList.remove('etel-cap-hidden'); switchPage('packages', n); }); await p.waitForTimeout(1200);
    let txt = await p.innerText('#panel-packages');
    t(mode.n + ': four tabs', (await p.locator('.pkp-tabs button').count()) === 4, txt.slice(0, 200));
    t(mode.n + ': the hero counts couriers online / registered', /2 \/ 7/.test(await p.innerText('#panel-packages .ak-hero-big')) && /couriers online/i.test(txt), JSON.stringify(await p.innerText('#panel-packages .ak-hero-big')));
    t(mode.n + ': a warning says the bajaj couriers get no package jobs, with a one-click add', /2 couriers drive a bajaj/.test(txt) && (await p.locator('[data-quickadd="bajaj"]').count()) === 1, txt.slice(0, 600));
    t(mode.n + ': the price table has a column per vehicle and the row for the tried distance (5 km, 3 kg: ETB 100 bicycle, 150 car)', (await p.locator('.pkp-mx thead th').count()) === 5 && /ETB 150/.test(await p.innerText('.pkp-mx tr.try')) && /ETB 100/.test(await p.innerText('.pkp-mx tr.try')), await p.innerText('.pkp-mx'));
    t(mode.n + ': a heavy package marks the vehicles that cannot carry it', await (async () => { await p.fill('#pkg-calc-kg', '60'); await p.waitForTimeout(150); const x = await p.innerText('.pkp-mx tr.try'); await p.fill('#pkg-calc-kg', '3'); return /too heavy/.test(x); })());
    t(mode.n + ': the customer view lists the vehicles cheapest first with the courier count', /Bicycle[\s\S]*Motorbike[\s\S]*Car/.test(await p.innerText('[data-cv]')) && /2 couriers online/.test(await p.innerText('[data-cv]')));
    await p.screenshot({ path: SHOTS + '/package_prices_' + mode.n + '.png', fullPage: false });
    await p.locator('[data-quickadd="bajaj"]').click(); await p.waitForTimeout(400);
    t(mode.n + ': the one-click add opens the Vehicles tab with Bajaj and its word filled in', (await p.inputValue('form[data-new] [name=label]')) === 'Bajaj' && (await p.inputValue('form[data-new] [name=courier_names]')) === 'bajaj');
    await p.locator('form[data-new] button[type=submit]').click(); await p.waitForTimeout(700);
    t(mode.n + ': adding sends the new type with the courier word', added && added.label === 'Bajaj' && added.courier_names === 'bajaj', JSON.stringify(added));
    t(mode.n + ': the Vehicles tab shows a card per vehicle with its supply and ladder', (await p.locator('form[data-class]').count()) === 5 && /online/.test(await p.innerText('form[data-class="moto"]')) && (await p.locator('form[data-class="moto"] [data-ladder] .ak-chip').count()) === 4);
    await p.fill('form[data-class="moto"] [name=max_weight_kg]', '30'); await p.locator('form[data-class="moto"] button[type=submit]').click(); await p.waitForTimeout(500);
    t(mode.n + ': saving a vehicle sends its changes (max weight 30)', saved && saved.vehicle_class === 'moto' && String(saved.max_weight_kg) === '30', JSON.stringify(saved));
    await p.locator('.pkp-tabs [data-tab="bands"]').click(); await p.waitForTimeout(300);
    const eg0 = await p.innerText('[data-tier-eg]'); await p.fill('form[data-tiers] [name=w3_fee]', '500'); await p.waitForTimeout(150);
    t(mode.n + ': the Distance and weight tab shows a live example that moves when a band changes', /^\d+$/.test(eg0) && (await p.innerText('[data-tier-eg]')) !== eg0, eg0 + ' -> ' + await p.innerText('[data-tier-eg]'));
    await p.locator('.pkp-tabs [data-tab="rules"]').click(); await p.waitForTimeout(300);
    t(mode.n + ': the Rules tab has the late cancel fee', (await p.locator('form[data-setting="late_cancel_fee"]').count()) === 1);
    t(mode.n + ': no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
