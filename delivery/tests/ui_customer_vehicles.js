// Customer app cart: the real vehicle types in the delivery picker, with how many couriers are online for each (js/customer.js, the real page and file).
// Needs Playwright (node). No server: the page is opened from disk and the API is a fake.   node delivery/tests/ui_customer_vehicles.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });   // a tiny static server for the page's own files
let PAGE = '';
const VEHICLES = [
  { tier_key: 'bike', vehicle_class: 'bike', label: 'Bicycle', icon: '\u{1F6B2}', multiplier: 1.0, eta_delta_min: 0, couriers: 0, online: 0, available: false },
  { tier_key: 'moto', vehicle_class: 'moto', label: 'Motorbike', icon: '\u{1F6F5}', multiplier: 1.0, eta_delta_min: 0, couriers: 6, online: 2, available: true },
  { tier_key: 'car', vehicle_class: 'car', label: 'Car', icon: '\u{1F697}', multiplier: 1.12, eta_delta_min: -2, couriers: 1, online: 1, available: true },
  { tier_key: 'van', vehicle_class: 'van', label: 'Van / pickup', icon: '\u{1F690}', multiplier: 1.6, eta_delta_min: 5, couriers: 0, online: 0, available: false },
  { tier_key: 'x1', vehicle_class: 'x1', label: 'Bajaj', icon: '\u{1F6FA}', multiplier: 1.0, eta_delta_min: 0, couriers: 2, online: 1, available: true }];
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  for (const mode of [{ n: 'with vehicles', veh: true }, { n: 'old server (tiers only)', veh: false }]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
    await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); });
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message)); let sent = null;
    await p.route(/boot\.php/, r => {
      const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action;
      const items = [{ id: 1, name: 'Burger', price: '500.00', category: 'food', service_giver_id: 14, business_name: 'Shop', zone: 'Z', is_available: 1, stock_quantity: 50, is_online: 1, extras: [], gallery: [] }];
      let data = {};
      if (act === 'customer_get_items' || act === 'get_items') data = { items, count: 1 };
      else if (act === 'get_delivery_tiers') data = mode.veh ? { tiers: [{ tier_key: 'moto', label: 'Delivery by moto', icon: 'x', multiplier: 1, eta_delta_min: 0 }], vehicles: VEHICLES } : { tiers: [{ tier_key: 'moto', label: 'Delivery by moto', icon: 'x', multiplier: 1, eta_delta_min: 0 }, { tier_key: 'car', label: 'Delivery by car', icon: 'x', multiplier: 1.12, eta_delta_min: -2 }, { tier_key: 'express_cargo', label: 'Express Cargo', icon: 'x', multiplier: 1.6, eta_delta_min: 5 }] };
      else if (act === 'customer_create_order') { sent = body; data = { order_id: 9, order_code: 'ETD-9', fare_total: 600 }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(PAGE); await p.waitForTimeout(2500);
    await p.evaluate(() => { cart.push({ id: 1, item_id: 1, name: 'Burger', price: 500, quantity: 1, extras: [], xkey: '' }); try { toggleCart(true); } catch (e) { try { openCart(); } catch (e2) {} } renderCartDrawer(); });
    await p.waitForTimeout(800);
    await p.evaluate(() => { const d = document.getElementById('cart-checkout'); if (d) d.scrollIntoView(); });
    const txt = await p.evaluate(() => (document.getElementById('cart-checkout') || document.body).innerText.replace(/\s+/g, ' '));
    if (mode.veh) {
      t(mode.n + ': the picker lists every real vehicle', /Bicycle/.test(txt) && /Motorbike/.test(txt) && /Car/.test(txt) && /Van \/ pickup/.test(txt) && /Bajaj/.test(txt), txt.slice(0, 500));
      t(mode.n + ': each shows how many couriers are online, or none', /2 online/.test(txt) && /1 online/.test(txt) && (txt.match(/none online/g) || []).length === 2, txt.slice(0, 600));
      t(mode.n + ': the first vehicle with a courier online is chosen (not the empty bicycle)', await p.evaluate(() => selectedTier) === 'moto', await p.evaluate(() => selectedTier));
      await p.evaluate(() => selectTier('van')); await p.waitForTimeout(200);
      const t2 = await p.evaluate(() => document.getElementById('cart-checkout').innerText.replace(/\s+/g, ' '));
      t(mode.n + ': choosing a vehicle nobody drives right now says so', /No Van \/ pickup courier is online right now/.test(t2), t2.slice(0, 500));
      await p.evaluate(() => selectTier('car')); await p.waitForTimeout(200);
      t(mode.n + ': the delivery fee follows the vehicle (car = zone fee x 1.12)', await p.evaluate(() => { selectedZone = 'Tabor'; return getDeliveryFee('Tabor') === Math.round(HAWASSA_FEES['Tabor'].fee * 1.12); }));
      await p.evaluate(() => { selectedZone = 'Tabor'; document.getElementById('co-name') && (document.getElementById('co-name').value = 'R'); });
      const pl = await p.evaluate(() => ({ tier: selectedTier, v: _vehKey() }));
      t(mode.n + ': the order will carry the picked vehicle', pl.tier === 'car' && pl.v === 'car', JSON.stringify(pl));
    } else {
      t(mode.n + ': an old server still shows the three tiers and sends no vehicle', /Delivery by moto/.test(txt) && /Express Cargo/.test(txt) && await p.evaluate(() => _vehKey()) === '', txt.slice(0, 400));
    }
    t(mode.n + ': no page errors', errs.length === 0, errs.join('|'));
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); const d = document.getElementById('cart-checkout'); if (d) d.scrollIntoView(); }); await p.waitForTimeout(300);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/cart_vehicles_' + (mode.veh ? 'new' : 'old') + '.png' });
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();
