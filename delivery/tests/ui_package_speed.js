// Send a package: delivery speed (Standard / Express) is chosen after the vehicle, the price breakdown shows the Express extra, the booking sends the speed,
// and the waybill shows it. An older server (no express block) or Express switched off shows no speed choice.
// Real page and files, a fake API.   node delivery/tests/ui_package_speed.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const OPTIONS = [
  { vehicle_class: 'moto', label: 'Motorbike', icon: '\u{1F3CD}', price: '120.00', max_weight_kg: 20, rank: 1 },
  { vehicle_class: 'car', label: 'Car', icon: '\u{1F697}', price: '220.00', max_weight_kg: 120, rank: 2 }];
const OFF = [
  { vehicle_class: 'bike', label: 'Bicycle', icon: '\u{1F6B2}', reason: 'weight', max_weight_kg: 5, rank: 0 },
  { vehicle_class: 'van', label: 'Van', icon: '\u{1F690}', reason: 'size', max_weight_kg: 500, rank: 3 }];
const BANKS = [{ bank_key: 'abysinia', display_name: 'Bank of Abyssinia', account_name: 'Etel Group', account_number: '1234567890' }];
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  const OPT = { vehicle_class: 'moto', label: 'Motorbike', icon: '\u{1F3CD}', price: 120, max_weight_kg: 20, rank: 1 };
  const MODES = [
    { n: 'express on', q: { express: { on: true, eta_min: 14 }, options: [Object.assign({ express_extra: 36, express_price: 156 }, OPT)] }, speed: true },
    { n: 'express off', q: { express: { on: false }, options: [OPT] }, speed: false },
    { n: 'older server', q: { options: [OPT] }, speed: false }];
  for (const m of MODES) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); });
    const p = await ctx.newPage(); const errs = []; const calls = []; p.on('pageerror', e => errs.push(e.message + ' @ ' + (e.stack || '').split('\n')[1]));
    await p.route(/maps\.googleapis|gstatic|fonts\./, r => r.abort());
    await p.route(/boot\.php/, r => {
      const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action; let data = {};
      if (act === 'customer_get_items' || act === 'get_items') data = { items: [], count: 0 };
      else if (act === 'customer_package_options') data = { sizes: [{ size: 'document' }, { size: 'small' }, { size: 'medium' }], vehicles: [], zones: [] };
      else if (act === 'customer_package_quote') data = Object.assign({ distance_km: 4.2, eta_min: 18, unavailable: [], pickup_zone: 'A', drop_zone: 'B' }, m.q);
      else if (act === 'customer_package_create') { calls.push(body); data = { order_id: 77, order_code: 'PKG-77', total: body.speed === 'express' ? '156.00' : '120.00', speed: body.speed }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(PAGE); await p.waitForTimeout(2000);
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); pkgOpen(); }); await p.waitForTimeout(600);
    const setf = async (f, v) => { await p.evaluate(([f, v]) => { const el = document.querySelector('[data-f="' + f + '"]'); el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }, [f, v]); };
    const zone = await p.evaluate(() => { const s = document.querySelector('select[data-f="pickup.zone"]'); return s && s.options[1] ? s.options[1].value : ''; });
    await setf('pickup.address', 'Piassa shop'); await setf('drop.address', 'Tabor home');
    if (zone) { await setf('pickup.zone', zone); await setf('drop.zone', zone); }
    await setf('sender_name', 'Sender'); await setf('sender_phone', '0911000001'); await setf('recipient_name', 'Rita'); await setf('recipient_phone', '0911223344');
    await p.evaluate(() => pkgNext()); await p.evaluate(() => pkgSize('small')); await setf('weight', '1'); await setf('desc', 'Books');
    await p.evaluate(() => { const c = document.getElementById('pkg-f-ack'); c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); });
    await p.evaluate(() => pkgNext()); await p.waitForTimeout(700);
    const n = m.n + ': ';
    t(n + 'no speed choice before a vehicle is chosen', await p.evaluate(() => !document.querySelector('.pkg-speed')));
    await p.evaluate(() => pkgVehicle('moto')); await p.waitForTimeout(200);
    const has = await p.evaluate(() => !!document.querySelector('.pkg-speed'));
    t(n + (m.speed ? 'the speed choice (Standard / Express) shows after choosing the vehicle' : 'no speed choice is shown'), has === m.speed, String(has));
    if (m.speed) {
      const txt = await p.evaluate(() => document.querySelector('.pkg-speed').innerText.replace(/\s+/g, ' '));
      t(n + 'Express shows its extra and its time, Standard its time', /Express/.test(txt) && /\+ETB 36/.test(txt) && /about 14 min/.test(txt) && /Standard/.test(txt) && /About 18 min/.test(txt), txt);
      t(n + 'Standard is chosen first', await p.evaluate(() => document.querySelector('.pkg-spd.on .pkg-pay-t').textContent === 'Standard'));
      await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_speed.png' });
      await p.evaluate(() => { [].slice.call(document.querySelectorAll('.pkg-spd'))[1].click(); }); await p.waitForTimeout(150);
      t(n + 'the Express card is now chosen', await p.evaluate(() => document.querySelector('.pkg-spd.on .pkg-pay-t').textContent === 'Express'));
    }
    await p.evaluate(() => pkgNext()); await p.waitForTimeout(300);
    if (m.speed) t(n + 'the Book button shows the Express total', await p.evaluate(() => /ETB 156/.test(document.querySelector('.pkg-foot .pkg-primary').textContent)));
    const sum = await p.evaluate(() => document.querySelector('.pkg-total').parentElement.innerText.replace(/\s+/g, ' '));
    if (m.speed) {
      t(n + 'the price breakdown shows fee, Express extra and the total', /Delivery fee ETB 120/.test(sum) && /Express extra \+ETB 36/.test(sum) && /Total ETB 156/.test(sum), sum);
      await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_breakdown.png' });
    } else t(n + 'the breakdown shows the fee and the total only', /Delivery fee ETB 120/.test(sum) && !/Express/.test(sum) && /Total ETB 120/.test(sum), sum);
    await p.evaluate(() => pkgSubmit()); await p.waitForTimeout(800);
    t(n + 'the booking sends the speed', calls.length === 1 && calls[0].speed === (m.speed ? 'express' : 'standard'), JSON.stringify(calls.map(c => c.speed)));
    const wb = await p.evaluate(() => document.getElementById('pkg-scroll').innerText.replace(/\s+/g, ' '));
    t(n + 'the waybill shows the speed', m.speed ? /speed express/i.test(wb) && /ETB 156/.test(wb) : /speed standard/i.test(wb) && /ETB 120/.test(wb), wb.slice(0, 300));
    t(n + 'no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
