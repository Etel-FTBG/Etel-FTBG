// Admin > Delivery fees (js/admin-delivery-fees.js): vehicle price lists with real pictures, the company / courier share, the service charge,
// the live price table, "try a trip", saving with a read-back check, and the messages in words. Real admin.html, mocked API.
// Needs Playwright (node).   node delivery/tests/ui_admin_delivery_fees.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
const VEH = () => [
  { class: 'bike', label: 'Bicycle', icon: '\u{1F6B2}', rank: 1, active: true, base: 45, per_km: 15, min: 60, default: { base: 45, per_km: 15, min: 60 }, couriers: 3, online: 1 },
  { class: 'moto', label: 'Motorbike', icon: '\u{1F6F5}', rank: 2, active: true, base: 55, per_km: 18, min: 75, default: { base: 55, per_km: 18, min: 75 }, couriers: 6, online: 0 },
  { class: 'car', label: 'Car', icon: '\u{1F697}', rank: 3, active: true, base: 80, per_km: 28, min: 120, default: { base: 80, per_km: 28, min: 120 }, couriers: 1, online: 1 },
  { class: 'van', label: 'Van / pickup', icon: '\u{1F690}', rank: 4, active: false, base: 120, per_km: 40, min: 180, default: { base: 120, per_km: 40, min: 180 }, couriers: 0, online: 0 }];
(async () => {
  await new Promise(r => srv.listen(0, r)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1300, h: 900 }, { n: 'phone', w: 390, h: 844 }]) {
    let SET = { enabled: 1, company_pct: 40, service_pct: 0, road_factor: 1.3, round_step: 5, max_km: 0, night_mult: 1, night_start: 21, night_end: 6 }; let V = VEH(); const calls = []; let FAIL_SAVE = false, ADJUST = false;
    const STATS = { orders: 12, fees: 1860, courier: 1116, company: 744, service: 0 };
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(() => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin' })); } catch (e) {} });
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {}, ok = true, msg = '';
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_dfee_get') data = { settings: SET, defaults: { company_pct: 40 }, vehicles: V, stats: STATS };
      else if (a === 'admin_dfee_save') {
        calls.push(d);
        if (FAIL_SAVE) { ok = false; msg = 'You are not allowed to change the delivery fees.'; }
        else {
          SET = Object.assign({}, SET, d.settings); if (ADJUST && Number(SET.service_pct) > 30) SET.service_pct = 30;
          V = V.map(v => d.vehicles && d.vehicles[v.class] ? Object.assign({}, v, d.vehicles[v.class]) : v);
          data = { settings: SET, defaults: {}, vehicles: V, stats: STATS, message: 'Saved.' };
        }
      }
      else if (a === 'admin_live_ops') data = { now: {}, speed: {} };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify(ok ? { success: true, data } : { success: false, message: msg }) }); });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(2300);
    const n = mode.n;
    t(n + ': Delivery fees is on the Delivery home under Menu and prices, and not in the Ride workspace', await p.evaluate(() => { AdminWS.show('delivery'); return document.querySelector('#panel-wshome .aws-tile[data-go="dfees"]') !== null; }));
    await p.waitForTimeout(300); await p.evaluate(() => { AdminWS.show('ride'); }); await p.waitForTimeout(300);
    t(n + ': ... not on the Ride home', (await p.locator('#panel-wshome .aws-tile[data-go="dfees"]').count()) === 0);
    t(n + ': it has a menu item in Catalog & pricing', await p.evaluate(() => { const i = document.querySelector('.nav-item[data-page="dfees"]'); return !!i && /Delivery fees/.test(i.textContent); }));
    await p.evaluate(() => { const nv = document.querySelector('.nav-item[data-page="dfees"]'); switchPage('dfees', nv); }); await p.waitForTimeout(1000);
    const P = '#panel-dfees';
    const txt = await p.innerText(P);
    t(n + ': the page shows the share (60 / 40) and the service charge as Free', /Courier gets\s*60%|60%\s*Courier gets/i.test(txt.replace(/\n/g, ' ')) && /Free/.test(txt) && /Company 40%/.test(txt), txt.slice(0, 400));
    t(n + ': one card per real vehicle, each with a real picture', (await p.locator(P + ' .dfee-veh').count()) === 4 && (await p.locator(P + ' .dfee-veh .etel-vpic, ' + P + ' .dfee-veh .etel-vpic-fb').count()) === 4 && (await p.locator(P + ' .dfee-veh img.etel-vpic[src*="img/vehicles/"]').count()) >= 3);
    t(n + ': the bicycle shows 45 + 15 per km, minimum 60', (await p.inputValue(P + ' [data-v="bike"][data-f="base"]')) === '45' && (await p.inputValue(P + ' [data-v="bike"][data-f="per_km"]')) === '15' && (await p.inputValue(P + ' [data-v="bike"][data-f="min"]')) === '60');
    t(n + ': the courier counts and the switched-off vehicle are shown', /1 online/.test(txt) && /none online/.test(txt) && /switched off in Package prices/.test(txt));
    t(n + ': the last 30 days are shown (12 orders, ETB 744 for the company)', /12/.test(txt) && /744/.test(txt) && /Company earned/.test(txt));
    const cell = (row, col) => p.evaluate(([r, c]) => document.querySelectorAll('#panel-dfees .dfee-tbl tbody tr')[r].children[c].innerText.replace(/\s+/g, ' '), [row, col]);
    t(n + ': the price table: bicycle 3 km = ETB 105 (45 + 15 x 3.9 road km, rounded to 5)', /ETB 105/.test(await cell(2, 1)), await cell(2, 1));
    t(n + ': ... motorbike 3 km = ETB 125, car 3 km = ETB 190', /ETB 125/.test(await cell(2, 2)) && /ETB 190/.test(await cell(2, 3)), await cell(2, 2) + ' ' + await cell(2, 3));
    t(n + ': ... a very short trip is the minimum (1 km bicycle = 65)', /ETB 65/.test(await cell(0, 1)), await cell(0, 1));
    await p.locator(P + ' .dfee-grid').scrollIntoViewIfNeeded(); await p.waitForTimeout(500);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/delivery_fees_cards_' + n + '.png' });
    await p.locator(P + ' .dfee-tbl').scrollIntoViewIfNeeded(); await p.waitForTimeout(300);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/delivery_fees_table_' + n + '.png' });
    // typing changes everything live
    await p.fill(P + ' [data-v="bike"][data-f="per_km"]', '20');
    t(n + ': typing a new price per km changes the table at once (bicycle 3 km = 125)', /ETB 125/.test(await cell(2, 1)), await cell(2, 1));
    t(n + ': ... and the card ladder', /3 km\s*ETB 125/.test(await p.innerText(P + ' [data-veh="bike"] .dfee-ladder')));
    t(n + ': ... and says the changes are not saved yet, with an Undo button', /not saved yet/.test(await p.innerText(P + ' .dfee-save')) && (await p.locator(P + ' [data-discard]').count()) === 1);
    t(n + ': "Back to the starting price" appears for the changed vehicle only', (await p.locator(P + ' [data-reset="bike"]:not([hidden])').count()) === 1 && (await p.locator(P + ' [data-reset="moto"]:not([hidden])').count()) === 0);
    await p.locator(P + ' [data-reset="bike"]').click(); await p.waitForTimeout(250);
    t(n + ': going back restores 45 + 15 per km', (await p.inputValue(P + ' [data-v="bike"][data-f="per_km"]')) === '15' && /ETB 105/.test(await cell(2, 1)));
    // the share
    await p.locator(P + ' [data-chip="50"]').click(); await p.waitForTimeout(200);
    t(n + ': the 50 / 50 button moves the share and the hero, bar and example follow', /50%/.test(await p.innerText(P + ' [data-live="hero"]')) && /courier gets ETB 53/i.test((await p.innerText(P + ' [data-live="split"]')).replace(/\s+/g, ' ')) || /Courier gets ETB 53/.test((await p.innerText(P + ' [data-live="split"]')).replace(/\s+/g, ' ')), (await p.innerText(P + ' [data-live="split"]')));
    await p.evaluate(() => { const r = document.querySelector('#panel-dfees [data-g="company_pct"]'); r.value = 40; r.dispatchEvent(new Event('input', { bubbles: true })); });
    t(n + ': the slider back to 40 shows Company 40% / Courier 60%', /Company 40% . Courier 60%/.test(await p.innerText(P + ' [data-live="cut"]')));
    // service charge
    await p.fill(P + ' [data-g="service_pct"]', '5');
    t(n + ': a service charge of 5 shows in the top numbers instead of Free', /5%/.test(await p.innerText(P + ' [data-live="hero"]')) && !/Free/.test(await p.innerText(P + ' [data-live="hero"]')));
    // try a trip
    await p.fill(P + ' [data-try="km"]', '10');
    const tt = (await p.innerText(P + ' [data-live="try"]')).replace(/\s+/g, ' ');
    t(n + ': try a trip: 10 km bicycle = ETB 240 (45 + 15 x 13 road km) with the courier and company parts', /Bicycle ETB 240 Courier ETB 144 . Company ETB 96/.test(tt), tt.slice(0, 300));
    await p.locator(P + ' [data-try="night"]').check(); await p.waitForTimeout(100);
    t(n + ': at night with no night multiplier the price is the same', /Bicycle ETB 240/.test((await p.innerText(P + ' [data-live="try"]')).replace(/\s+/g, ' ')));
    // more controls change the table
    await p.locator(P + ' details summary').click();
    await p.selectOption(P + ' [data-g="round_step"]', '1');
    t(n + ': rounding to 1 ETB changes the table (bicycle 3 km = 104)', /ETB 104/.test(await cell(2, 1)), await cell(2, 1));
    await p.selectOption(P + ' [data-g="round_step"]', '5');
    // bad numbers are refused before sending
    await p.fill(P + ' [data-v="moto"][data-f="base"]', '-5');
    await p.locator(P + ' [data-save]').click(); await p.waitForTimeout(250);
    t(n + ': a minus price is refused in words and nothing is sent', /Check the prices of Motorbike/.test(await p.innerText(P + ' .dfee-save [data-note]')) && calls.length === 0, JSON.stringify(calls));
    await p.fill(P + ' [data-v="moto"][data-f="base"]', '55');
    // save
    await p.locator(P + ' [data-save]').click(); await p.waitForTimeout(700);
    const sent = calls[0] || {};
    t(n + ': saving sends the share, service charge, and every vehicle price list', sent.settings && Number(sent.settings.company_pct) === 40 && Number(sent.settings.service_pct) === 5 && sent.vehicles && Number(sent.vehicles.bike.base) === 45 && Number(sent.vehicles.bike.per_km) === 15 && Number(sent.vehicles.car.min) === 120 && !!sent.vehicles.van, JSON.stringify(sent).slice(0, 400));
    t(n + ': after saving it says the numbers were read back and checked', /Saved and checked/.test(await p.innerText(P + ' .dfee-save [data-note]')), await p.innerText(P + ' .dfee-save [data-note]'));
    t(n + ': ... and no longer says unsaved', !/not saved yet/.test(await p.innerText(P + ' .dfee-save')) && (await p.locator(P + ' [data-discard]').count()) === 0);
    // the server adjusts a number: say so
    ADJUST = true; await p.fill(P + ' [data-g="service_pct"]', '50'); await p.locator(P + ' [data-save]').click(); await p.waitForTimeout(700);
    t(n + ': when the server changes a number it says which one (service charge 50 became 30)', /changed some numbers/.test(await p.innerText(P + ' .dfee-save [data-note]')) && /service pct/.test(await p.innerText(P + ' .dfee-save [data-note]')), await p.innerText(P + ' .dfee-save [data-note]'));
    // refusal from the server
    FAIL_SAVE = true; await p.fill(P + ' [data-v="car"][data-f="base"]', '90'); await p.locator(P + ' [data-save]').click(); await p.waitForTimeout(500);
    t(n + ': a refused save shows the server message and keeps what was typed', /not allowed to change the delivery fees/.test(await p.innerText(P + ' .dfee-save [data-note]')) && (await p.inputValue(P + ' [data-v="car"][data-f="base"]')) === '90');
    // layout
    const sw = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    t(n + ': no sideways page scroll', sw <= 2, 'overflow ' + sw);
    await p.evaluate(() => document.querySelector('#panel-dfees').scrollIntoView()); await p.waitForTimeout(200);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/delivery_fees_' + n + '.png', fullPage: false });
    t(n + ': no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
